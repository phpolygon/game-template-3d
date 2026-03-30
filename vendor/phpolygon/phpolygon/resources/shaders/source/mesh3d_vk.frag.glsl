#version 450

layout(location = 0) in vec3 v_normal;
layout(location = 1) in vec3 v_worldPos;
layout(location = 2) in vec2 v_uv;

// ================================================================
//  Vulkan Descriptor Bindings
// ================================================================

struct DirLight {
    vec3 direction; float _pad0;
    vec3 color;     float intensity;
};

struct PointLight {
    vec3  position;  float intensity;
    vec3  color;     float radius;
};

// Binding 0: Frame UBO (set by vertex shader too)
// Binding 1: Lighting + Material + Weather UBO
layout(binding = 1) uniform LightingUBO {
    // Ambient
    vec3  u_ambient_color;
    float u_ambient_intensity;

    // Material
    vec3  u_albedo;
    float u_roughness;
    vec3  u_emission;
    float u_metallic;
    float u_alpha;
    float u_time;
    int   u_proc_mode;
    float u_moon_phase;

    // Fog
    vec3  u_fog_color;
    float u_fog_near;
    vec3  u_camera_pos;
    float u_fog_far;

    // Sky / Environment
    vec3  u_sky_color;
    int   u_has_environment_map;
    vec3  u_horizon_color;
    int   u_has_shadow_map;
    vec3  u_season_tint;
    int   u_has_cloud_shadow;

    // Weather
    float u_rain_intensity;
    float u_snow_coverage;
    float u_temperature;
    float u_dew_wetness;
    float u_storm_intensity;
    float _pad_w0;
    float _pad_w1;
    float _pad_w2;

    // Shadow
    mat4  u_light_space_matrix;

    // Directional lights
    int   u_dir_light_count;
    int   _pad_dl0;
    int   _pad_dl1;
    int   _pad_dl2;
    DirLight u_dir_lights[16];

    // Point lights
    int   u_point_light_count;
    int   _pad_pl0;
    int   _pad_pl1;
    int   _pad_pl2;
    PointLight u_point_lights[8];
};

// Legacy aliases
#define u_dir_light_direction u_dir_lights[0].direction
#define u_dir_light_color u_dir_lights[0].color
#define u_dir_light_intensity u_dir_lights[0].intensity

// Binding 2: Shadow map (depth comparison sampler)
layout(binding = 2) uniform sampler2DShadow u_shadow_map;

// Binding 3: Cloud shadow map (R8 opacity)
layout(binding = 3) uniform sampler2D u_cloud_shadow_map;

// Binding 4: Environment cubemap
layout(binding = 4) uniform samplerCube u_environment_map;

layout(location = 0) out vec4 frag_color;

// ================================================================
// ================================================================
//  Noise functions
// ================================================================

float hash21(vec2 p) {
    p = fract(p * vec2(127.1, 311.7));
    p += dot(p, p + 19.19);
    return fract(p.x * p.y);
}

float hash31(vec3 p) {
    p = fract(p * vec3(443.897, 441.423, 437.195));
    p += dot(p, p.yzx + 19.19);
    return fract((p.x + p.y) * p.z);
}

float noise(vec2 p) {
    vec2 i = floor(p);
    vec2 f = fract(p);
    f = f * f * (3.0 - 2.0 * f);
    float a = hash21(i);
    float b = hash21(i + vec2(1.0, 0.0));
    float c = hash21(i + vec2(0.0, 1.0));
    float d = hash21(i + vec2(1.0, 1.0));
    return mix(mix(a, b, f.x), mix(c, d, f.x), f.y);
}

// Fractal Brownian Motion — layered noise
float fbm(vec2 p, int octaves) {
    float value = 0.0;
    float amp = 0.5;
    float freq = 1.0;
    for (int i = 0; i < octaves; i++) {
        value += amp * noise(p * freq);
        freq *= 2.0;
        amp *= 0.5;
    }
    return value;
}

// ================================================================
//  Shadow calculation
// ================================================================

float calcShadow(vec3 worldPos) {
    if (u_has_shadow_map == 0 && u_has_cloud_shadow == 0) return 1.0;

    vec4 lightSpacePos = u_light_space_matrix * vec4(worldPos, 1.0);
    vec3 projCoords = lightSpacePos.xyz / lightSpacePos.w;
    projCoords = projCoords * 0.5 + 0.5;

    // Outside shadow map → no shadow
    if (projCoords.x < 0.0 || projCoords.x > 1.0 ||
        projCoords.y < 0.0 || projCoords.y > 1.0 ||
        projCoords.z > 1.0) return 1.0;

    float shadow = 1.0;

    // Geometry shadow (depth-based, PCF 3×3)
    if (u_has_shadow_map == 1) {
        float geomShadow = 0.0;
        float texelSize = 1.0 / 2048.0;
        float bias = 0.002;
        float refDepth = projCoords.z - bias;

        for (int x = -1; x <= 1; x++) {
            for (int y = -1; y <= 1; y++) {
                vec2 offset = vec2(float(x), float(y)) * texelSize;
                geomShadow += texture(u_shadow_map, vec3(projCoords.xy + offset, refDepth));
            }
        }
        geomShadow /= 9.0;
        shadow *= geomShadow;
    }

    // Cloud shadow (opacity-based, wide soft blur for realistic cloud penumbra)
    if (u_has_cloud_shadow == 1) {
        float cloudShadow = 0.0;
        float cloudTexelSize = 1.0 / 1024.0;

        // Large 5×5 Gaussian-weighted blur for soft cloud shadow edges
        // Weights: center=4, adjacent=2, diagonal=1 (total 48)
        float weights[5] = float[](1.0, 2.0, 4.0, 2.0, 1.0);
        float totalWeight = 0.0;
        for (int x = -2; x <= 2; x++) {
            for (int y = -2; y <= 2; y++) {
                float w = weights[x + 2] * weights[y + 2];
                vec2 offset = vec2(float(x), float(y)) * cloudTexelSize * 3.0; // 3× spread for wider blur
                float cloudAlpha = texture(u_cloud_shadow_map, projCoords.xy + offset).r;
                cloudShadow += cloudAlpha * w;
                totalWeight += w;
            }
        }
        cloudShadow /= totalWeight;

        // Cloud opacity attenuates sunlight (0 = fully blocked, 1 = no cloud)
        shadow *= (1.0 - cloudShadow * 0.7); // Clouds don't block 100% — some light scatters through
    }

    return shadow;
}

// ================================================================
//  PBR helpers
// ================================================================

vec3 fresnelSchlick(float cosTheta, vec3 F0) {
    return F0 + (1.0 - F0) * pow(clamp(1.0 - cosTheta, 0.0, 1.0), 5.0);
}

// GGX Normal Distribution Function
float distributionGGX(float NdotH, float rough) {
    float a = rough * rough;
    float a2 = a * a;
    float denom = NdotH * NdotH * (a2 - 1.0) + 1.0;
    return a2 / (3.14159 * denom * denom + 0.0001);
}

// ================================================================
//  Procedural Sand
// ================================================================

vec3 computeSand(vec3 N, vec3 V, vec3 L, out float roughOut) {
    float zone = v_uv.x;    // 0.0=damp, 0.25=mid, 0.5=dry, 0.75=dune
    float variant = v_uv.y;

    // Zone color palettes — warm natural beach tones
    const vec3 damp[4] = vec3[](
        vec3(0.478, 0.369, 0.165), vec3(0.408, 0.306, 0.125),
        vec3(0.541, 0.408, 0.188), vec3(0.290, 0.220, 0.094)
    );
    const vec3 mid[4] = vec3[](
        vec3(0.722, 0.565, 0.314), vec3(0.627, 0.471, 0.220),
        vec3(0.784, 0.596, 0.345), vec3(0.420, 0.333, 0.157)
    );
    const vec3 dry[4] = vec3[](
        vec3(0.831, 0.722, 0.478), vec3(0.769, 0.643, 0.384),
        vec3(0.878, 0.769, 0.549), vec3(0.545, 0.451, 0.251)
    );
    const vec3 dune[4] = vec3[](
        vec3(0.863, 0.753, 0.502), vec3(0.910, 0.800, 0.565),
        vec3(0.816, 0.706, 0.439), vec3(0.604, 0.502, 0.282)
    );

    // Blend between zones smoothly
    vec3 colors[4];
    if (zone < 0.125)      colors = damp;
    else if (zone < 0.375) colors = mid;
    else if (zone < 0.625) colors = dry;
    else                   colors = dune;

    // Smooth variant blending
    float vi = variant * 3.0;
    int idx = int(floor(vi));
    vec3 baseColor = mix(colors[clamp(idx, 0, 3)], colors[clamp(idx + 1, 0, 3)], fract(vi));

    // Seasonal tint modulates terrain color
    baseColor *= u_season_tint;

    // Multi-scale noise — creates natural organic sand pattern
    float n1 = fbm(v_worldPos.xz * 1.5, 3);          // large color patches
    float n2 = noise(v_worldPos.xz * 6.0);             // medium grain clumps
    float n3 = noise(v_worldPos.xz * 25.0);            // individual grains
    float n4 = noise(v_worldPos.xz * 80.0);            // micro detail

    vec3 sandColor = baseColor;
    sandColor *= 0.82 + n1 * 0.36;                     // broad variation
    sandColor *= 0.92 + (n2 - 0.5) * 0.16;             // clump variation
    sandColor += vec3(0.02) * (n3 - 0.5);              // grain-level color shift
    sandColor += vec3(0.01, 0.008, 0.005) * (n4 - 0.5); // warm micro detail

    // Wind ripple patterns — diagonal lines across the beach
    float ripple = sin(v_worldPos.x * 3.0 + v_worldPos.z * 1.5 + n1 * 2.0) * 0.5 + 0.5;
    ripple = smoothstep(0.3, 0.7, ripple);
    float rippleStrength = smoothstep(0.3, 0.8, zone); // stronger on dry/dune
    sandColor *= 1.0 - ripple * 0.06 * rippleStrength;

    // Subsurface scattering approximation — warm glow when backlit
    float scatter = max(dot(V, L), 0.0);
    scatter = pow(scatter, 4.0) * 0.08;
    sandColor += vec3(0.15, 0.10, 0.04) * scatter;

    // Sparkle / glint — individual grains catching sunlight
    vec3 grainPos = floor(v_worldPos * 40.0);
    float glint = hash31(grainPos);
    vec3 grainNormal = normalize(vec3(
        hash21(grainPos.xz) - 0.5,
        1.0,
        hash21(grainPos.xz + 100.0) - 0.5
    ));
    float glintSpec = pow(max(dot(reflect(-L, grainNormal), V), 0.0), 80.0);
    if (glint > 0.96) {
        sandColor += vec3(0.4, 0.35, 0.25) * glintSpec * max(dot(N, L), 0.0);
    }

    // Roughness per zone — wet sand is shinier
    roughOut = mix(0.45, 0.95, smoothstep(0.0, 0.3, zone));
    // Wet sand also gets slight specular tint
    if (zone < 0.15) {
        sandColor = mix(sandColor, sandColor * 1.15, 0.3);
    }

    return sandColor;
}

// ================================================================
//  Procedural Water
// ================================================================

vec3 computeWater(vec3 N, vec3 V, vec3 L, out float alphaOut, out float roughOut) {
    // Animated wave normals — multiple layers at different speeds and scales
    vec2 uv1 = v_worldPos.xz * 0.8 + u_time * vec2(0.03, 0.02);
    vec2 uv2 = v_worldPos.xz * 1.6 + u_time * vec2(-0.02, 0.04);
    vec2 uv3 = v_worldPos.xz * 4.0 + u_time * vec2(0.05, -0.03);
    vec2 uv4 = v_worldPos.xz * 8.0 + u_time * vec2(-0.04, 0.06);

    // Compute normal perturbation from noise derivatives
    float eps = 0.05;
    float h1a = fbm(uv1, 3); float h1b = fbm(uv1 + vec2(eps, 0), 3); float h1c = fbm(uv1 + vec2(0, eps), 3);
    float h2a = fbm(uv2, 2); float h2b = fbm(uv2 + vec2(eps, 0), 2); float h2c = fbm(uv2 + vec2(0, eps), 2);
    float h3a = noise(uv3);  float h3b = noise(uv3 + vec2(eps, 0));   float h3c = noise(uv3 + vec2(0, eps));
    float h4a = noise(uv4);  float h4b = noise(uv4 + vec2(eps, 0));   float h4c = noise(uv4 + vec2(0, eps));

    vec3 waveNormal = vec3(0.0, 1.0, 0.0);
    // Large swell
    waveNormal.x += (h1a - h1b) * 1.5 + (h2a - h2b) * 0.8;
    waveNormal.z += (h1a - h1c) * 1.5 + (h2a - h2c) * 0.8;
    // Detail ripples
    waveNormal.x += (h3a - h3b) * 0.3 + (h4a - h4b) * 0.15;
    waveNormal.z += (h3a - h3c) * 0.3 + (h4a - h4c) * 0.15;
    waveNormal = normalize(waveNormal);

    // Blend wave normal with geometry normal
    N = normalize(N + waveNormal * vec3(1.0, 0.0, 1.0));

    // Fresnel — more reflective at shallow angles (realistic water!)
    float NdotV = max(dot(N, V), 0.0);
    float fresnel = pow(1.0 - NdotV, 5.0);
    fresnel = mix(0.02, 1.0, fresnel); // water F0 ≈ 0.02

    // Depth-based coloring
    float depth = max(0.0, -8.0 - v_worldPos.z) / 70.0; // 0 at shore, 1 at deep
    depth = clamp(depth, 0.0, 1.0);

    vec3 shallowColor = vec3(0.15, 0.55, 0.50);  // turquoise
    vec3 deepColor    = vec3(0.02, 0.08, 0.15);   // dark navy
    vec3 waterColor   = mix(shallowColor, deepColor, depth);

    // Reflection — cubemap or sky color fallback
    vec3 R = reflect(-V, N);
    vec3 reflectColor;
    if (u_has_environment_map == 1) {
        reflectColor = texture(u_environment_map, R).rgb;
    } else {
        // Blend between horizon (low R.y) and sky (high R.y) based on reflection direction
        float skyBlend = clamp(R.y * 2.0, 0.0, 1.0);
        reflectColor = mix(u_horizon_color, u_sky_color, skyBlend);
    }
    // Sun hotspot on reflection
    float sunCatch = pow(max(dot(R, L), 0.0), 256.0);
    reflectColor = mix(reflectColor, u_dir_light_color, sunCatch * 2.0);

    // Combine: fresnel blends between water body color and reflection
    vec3 finalColor = mix(waterColor, reflectColor, fresnel);

    // Sun specular hotspot on water
    vec3 Hw = normalize(V + L);
    float specWater = pow(max(dot(N, Hw), 0.0), 512.0);
    finalColor += u_dir_light_color * u_dir_light_intensity * specWater * 2.0;

    // Shore foam — white noise patches where water is shallow
    float foamLine = smoothstep(0.02, 0.0, depth);
    float foamNoise = fbm(v_worldPos.xz * 6.0 + u_time * 0.5, 3);
    float foam = foamLine * smoothstep(0.35, 0.65, foamNoise);
    finalColor = mix(finalColor, vec3(0.9, 0.95, 1.0), foam * 0.7);

    // Caustic light pattern on shallow water (subtle)
    if (depth < 0.3) {
        float caustic1 = noise(v_worldPos.xz * 3.0 + u_time * 0.8);
        float caustic2 = noise(v_worldPos.xz * 3.0 - u_time * 0.6 + 50.0);
        float caustic = pow(min(caustic1, caustic2), 3.0) * 2.0;
        finalColor += vec3(0.1, 0.15, 0.1) * caustic * (1.0 - depth / 0.3);
    }

    // Transparency: shallow = more transparent, deep = more opaque
    alphaOut = mix(0.5, 0.92, depth);
    // Foam areas are opaque
    alphaOut = mix(alphaOut, 1.0, foam * 0.8);

    roughOut = 0.05; // water is very smooth

    return finalColor;
}

// ================================================================
//  Procedural Rock
// ================================================================

vec3 computeRock(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    vec3 p = worldPos * 2.5;

    // Base rock color with large-scale variation
    float n1 = fbm(p.xz, 4);
    float n2 = fbm(p.xz * 3.0 + 50.0, 3);
    float n3 = noise(p.xz * 12.0);

    // Mix between dark and light stone
    vec3 darkStone  = baseAlbedo * 0.6;
    vec3 lightStone = baseAlbedo * 1.3;
    vec3 rockColor = mix(darkStone, lightStone, n1);

    // Veins / cracks — dark lines
    float crack = noise(p.xz * 8.0 + vec2(p.y * 2.0));
    crack = smoothstep(0.48, 0.52, crack);
    rockColor = mix(rockColor, rockColor * 0.5, crack * 0.4);

    // Strata layers — horizontal bands common in sedimentary rock
    float strata = sin(worldPos.y * 15.0 + n1 * 3.0) * 0.5 + 0.5;
    strata = smoothstep(0.4, 0.6, strata);
    rockColor *= 0.9 + strata * 0.2;

    // Moss patches — green on top-facing surfaces
    float upFacing = max(dot(N, vec3(0.0, 1.0, 0.0)), 0.0);
    float mossNoise = fbm(worldPos.xz * 4.0, 3);
    float moss = upFacing * smoothstep(0.4, 0.7, mossNoise) * smoothstep(0.5, 0.9, upFacing);
    vec3 mossColor = vec3(0.15, 0.25, 0.08);
    rockColor = mix(rockColor, mossColor, moss * 0.6);

    // Lichen spots — orange/yellow patches
    float lichenNoise = noise(worldPos.xz * 10.0 + 200.0);
    if (lichenNoise > 0.85) {
        vec3 lichenColor = vec3(0.6, 0.5, 0.2);
        rockColor = mix(rockColor, lichenColor, (lichenNoise - 0.85) * 4.0 * 0.3);
    }

    // Surface roughness variation
    roughOut = 0.75 + n2 * 0.2;
    roughOut = mix(roughOut, 0.6, moss * 0.5); // moss is smoother

    return rockColor;
}

// ================================================================
//  Procedural Palm Trunk
// ================================================================

vec3 computePalmTrunk(vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Bark rings — horizontal bands around the trunk
    float ringFreq = 12.0;
    float ring = sin(worldPos.y * ringFreq) * 0.5 + 0.5;
    ring = smoothstep(0.3, 0.7, ring);

    // Fiber texture — vertical streaks
    float fiber = noise(vec2(worldPos.x * 20.0 + worldPos.z * 20.0, worldPos.y * 3.0));
    float fiberFine = noise(vec2(worldPos.x * 50.0 + worldPos.z * 50.0, worldPos.y * 8.0));

    // Base bark color with warm brown variation
    vec3 darkBark  = baseAlbedo * 0.65;
    vec3 lightBark = baseAlbedo * 1.2;
    vec3 barkColor = mix(darkBark, lightBark, ring * 0.6 + fiber * 0.4);

    // Ring shadows — darker in the grooves
    barkColor *= 0.85 + ring * 0.3;

    // Fiber detail
    barkColor *= 0.95 + (fiberFine - 0.5) * 0.15;

    // Slight green/grey weathering
    float weather = fbm(worldPos.xz * 5.0, 2);
    barkColor = mix(barkColor, barkColor * vec3(0.85, 0.9, 0.8), weather * 0.2);

    roughOut = 0.85 + ring * 0.1;
    return barkColor;
}

// ================================================================
//  Procedural Palm Leaf
// ================================================================

vec3 computePalmLeaf(vec3 worldPos, vec3 N, vec3 V, vec3 L, vec3 baseAlbedo, out float roughOut) {
    // Leaf vein pattern — runs along the frond length
    float vein = abs(sin(worldPos.x * 30.0 + worldPos.z * 30.0));
    vein = smoothstep(0.0, 0.15, vein);

    // Base green with variation
    float n = fbm(worldPos.xz * 8.0, 3);
    vec3 leafColor = baseAlbedo * (0.8 + n * 0.4);

    // Central vein is lighter
    leafColor = mix(leafColor * 1.3, leafColor, vein);

    // Tip browning — leaves get yellow/brown at edges
    float edgeNoise = noise(worldPos.xz * 12.0);
    leafColor = mix(leafColor, vec3(0.4, 0.35, 0.15), edgeNoise * 0.15);

    // Translucency — light shining through leaf
    float translucency = max(dot(-N, L), 0.0);
    translucency = pow(translucency, 2.0) * 0.3;
    leafColor += vec3(0.1, 0.2, 0.02) * translucency;

    // Subsurface scattering
    float scatter = pow(max(dot(V, L), 0.0), 3.0) * 0.1;
    leafColor += vec3(0.05, 0.1, 0.02) * scatter;

    roughOut = 0.6 + edgeNoise * 0.15;
    return leafColor;
}

// ================================================================
//  Procedural Wood Planks (beach hut walls/furniture)
// ================================================================

vec3 computeWoodPlanks(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Use world Y for horizontal planks, world XZ for grain direction
    // Plank spacing — each plank ~0.15 units tall
    float plankScale = 6.5;
    float plankY = worldPos.y * plankScale;
    float plankIndex = floor(plankY);
    float withinPlank = fract(plankY);

    // Gap between planks — dark thin line
    float gap = smoothstep(0.0, 0.03, withinPlank) * smoothstep(1.0, 0.97, withinPlank);

    // Each plank has a unique color shift based on its index
    float plankHash = hash21(vec2(plankIndex * 17.3, plankIndex * 7.1));
    float plankHash2 = hash21(vec2(plankIndex * 31.7, plankIndex * 13.3));

    // Base wood color with per-plank variation
    vec3 woodColor = baseAlbedo;
    woodColor *= 0.8 + plankHash * 0.4; // brightness variation
    woodColor = mix(woodColor, woodColor * vec3(1.05, 0.95, 0.85), plankHash2 * 0.3); // hue shift

    // Wood grain — runs horizontally along the plank
    float grainCoord = worldPos.x * 8.0 + worldPos.z * 8.0 + plankHash * 20.0;
    float grain = sin(grainCoord + noise(vec2(grainCoord * 0.5, plankIndex)) * 3.0);
    grain = grain * 0.5 + 0.5;
    woodColor *= 0.9 + grain * 0.15;

    // Fine grain detail
    float fineGrain = noise(vec2(grainCoord * 3.0, worldPos.y * 2.0 + plankIndex * 5.0));
    woodColor *= 0.95 + fineGrain * 0.1;

    // Knot holes — rare dark circles
    float knotSeed = hash21(vec2(plankIndex * 43.7, floor(grainCoord * 0.3)));
    if (knotSeed > 0.92) {
        vec2 knotCenter = vec2(
            fract(knotSeed * 127.1) * 0.8 + 0.1,
            0.5
        );
        vec2 knotUV = vec2(fract(grainCoord * 0.15), withinPlank);
        float knotDist = length(knotUV - knotCenter);
        if (knotDist < 0.08) {
            woodColor *= 0.4 + knotDist * 5.0; // dark center, lighter rim
        }
    }

    // Nail heads — small bright spots
    float nailSeed = hash21(vec2(plankIndex * 11.1, 0.0));
    if (fract(nailSeed * 7.7) > 0.7) {
        vec2 nailPos = vec2(fract(nailSeed * 31.3) * 0.6 + 0.2, 0.5);
        vec2 nailUV = vec2(fract((worldPos.x + worldPos.z) * 2.0), withinPlank);
        if (length(nailUV - nailPos) < 0.015) {
            woodColor = vec3(0.3, 0.3, 0.35); // metal nail
        }
    }

    // Apply gap — dark line between planks
    woodColor *= gap * 0.85 + 0.15;

    // Weathering — random darker patches
    float weather = fbm(worldPos.xz * 3.0 + worldPos.y * 2.0, 2);
    woodColor *= 0.85 + weather * 0.2;

    // Normal perturbation — grain direction bumps
    float bumpX = noise(vec2(grainCoord + 0.1, worldPos.y * 8.0)) - 0.5;
    float bumpY = (withinPlank < 0.04 || withinPlank > 0.96) ? -0.3 : 0.0; // gap indent
    N = normalize(N + vec3(bumpX * 0.08, bumpY, bumpX * 0.05));

    roughOut = 0.78 + plankHash * 0.15;
    return woodColor;
}

// ================================================================
//  Procedural Thatch / Straw (beach hut roof)
// ================================================================

vec3 computeThatch(vec3 N, vec3 worldPos, vec3 baseAlbedo, out float roughOut) {
    // Straw strands running diagonally across the roof
    float strandAngle = worldPos.x * 12.0 + worldPos.z * 6.0 + worldPos.y * 4.0;
    float strand1 = sin(strandAngle) * 0.5 + 0.5;
    float strand2 = sin(strandAngle * 1.7 + 3.0) * 0.5 + 0.5;
    float strand3 = sin(strandAngle * 0.6 + 7.0) * 0.5 + 0.5;

    // Straw density layers
    float density = strand1 * 0.4 + strand2 * 0.35 + strand3 * 0.25;
    density = smoothstep(0.2, 0.8, density);

    // Color — golden straw with variation
    vec3 strawColor = baseAlbedo;
    float n = fbm(worldPos.xz * 5.0 + worldPos.y * 3.0, 3);
    strawColor *= 0.75 + n * 0.5;

    // Individual strand highlights
    float strandHighlight = pow(strand1, 8.0);
    strawColor += vec3(0.1, 0.08, 0.02) * strandHighlight;

    // Darker gaps between strands
    float strandGap = smoothstep(0.45, 0.5, strand1) * smoothstep(0.55, 0.5, strand1);
    strawColor *= 1.0 - strandGap * 0.3;

    // Weathering — some strands are darker/older
    float age = noise(worldPos.xz * 8.0);
    strawColor = mix(strawColor, strawColor * 0.6, smoothstep(0.7, 0.9, age) * 0.4);

    // Normal perturbation for strand direction
    float nx_p = sin(strandAngle + 0.1) * 0.1;
    float nz_p = cos(strandAngle * 0.7) * 0.08;
    N = normalize(N + vec3(nx_p, 0.0, nz_p));

    roughOut = 0.92 + density * 0.06;
    return strawColor;
}

// ================================================================
//  Procedural Cloud
// ================================================================

vec3 computeCloud(vec3 N, vec3 V, vec3 L, vec3 baseAlbedo, out float alphaOut) {
    // Cloud color based on sun-facing
    float NdotL = max(dot(N, L), 0.0);

    // Bright top, darker base
    vec3 sunColor = vec3(1.0, 0.98, 0.95);
    vec3 shadowColor = vec3(0.6, 0.65, 0.72);
    vec3 cloudColor = mix(shadowColor, sunColor, NdotL * 0.7 + 0.3);

    // Subsurface scattering — light passes through cloud edges
    float scatter = pow(max(dot(V, L), 0.0), 3.0);
    cloudColor += vec3(0.3, 0.25, 0.15) * scatter * 0.4;

    // Silver lining — bright rim when backlit
    float rim = pow(1.0 - max(dot(N, V), 0.0), 3.0);
    cloudColor += vec3(0.5, 0.5, 0.4) * rim * scatter * 0.6;

    // Soft noise variation
    float n = fbm(v_worldPos.xz * 0.3, 3);
    cloudColor *= 0.9 + n * 0.2;

    // Edge transparency — clouds are more transparent at edges
    float edgeFade = pow(max(dot(N, V), 0.0), 0.8);
    alphaOut = edgeFade * 0.85;

    return cloudColor;
}

// ================================================================
//  Main
// ================================================================

void main() {
    vec3 N = normalize(v_normal);
    if (!gl_FrontFacing) N = -N;

    vec3 V = normalize(u_camera_pos - v_worldPos);
    vec3 L = normalize(-u_dir_light_direction);
    vec3 H = normalize(V + L);

    float roughness = clamp(u_roughness, 0.04, 1.0);
    float alpha = u_alpha;
    vec3 albedo;

    // ---- Material selection ----
    if (u_proc_mode == 2) {
        // Water — full procedural with reflections, foam, caustics
        albedo = computeWater(N, V, L, alpha, roughness);

        // Water handles its own lighting — skip PBR, go to fog
        float fogDist = length(v_worldPos - u_camera_pos);
        float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        vec3 color = mix(albedo, u_fog_color, fogFactor);
        color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));
        frag_color = vec4(color, alpha);
        return;

    } else if (u_proc_mode == 1) {
        // Sand terrain
        albedo = computeSand(N, V, L, roughness);
        float nx_p = noise(v_worldPos.xz * 20.0 + vec2(0.1, 0.0));
        float nz_p = noise(v_worldPos.xz * 20.0 + vec2(0.0, 0.1));
        N = normalize(N + vec3((nx_p - 0.5) * 0.05, 0.0, (nz_p - 0.5) * 0.05));

    } else if (u_proc_mode == 3) {
        // Rock
        albedo = computeRock(N, v_worldPos, u_albedo, roughness);
        // Rock normal perturbation for surface roughness
        float rnx = noise(v_worldPos.xz * 15.0 + vec2(0.1, 0.0));
        float rnz = noise(v_worldPos.xz * 15.0 + vec2(0.0, 0.1));
        float rny = noise(v_worldPos.yz * 15.0);
        N = normalize(N + vec3((rnx - 0.5) * 0.12, (rny - 0.5) * 0.08, (rnz - 0.5) * 0.12));

    } else if (u_proc_mode == 4) {
        // Palm trunk
        albedo = computePalmTrunk(v_worldPos, u_albedo, roughness);
        float tnx = noise(vec2(v_worldPos.x * 30.0, v_worldPos.y * 5.0));
        N = normalize(N + vec3((tnx - 0.5) * 0.08, 0.0, (tnx - 0.5) * 0.08));

    } else if (u_proc_mode == 5) {
        // Palm leaf
        albedo = computePalmLeaf(v_worldPos, N, V, L, u_albedo, roughness);

    } else if (u_proc_mode == 7) {
        // Wood planks — beach hut walls, furniture
        albedo = computeWoodPlanks(N, v_worldPos, u_albedo, roughness);
        float wnx = noise(v_worldPos.xz * 15.0 + vec2(0.1, 0.0));
        float wnz = noise(v_worldPos.xz * 15.0 + vec2(0.0, 0.1));
        N = normalize(N + vec3((wnx - 0.5) * 0.06, 0.0, (wnz - 0.5) * 0.06));

    } else if (u_proc_mode == 8) {
        // Thatch / straw — beach hut roof
        albedo = computeThatch(N, v_worldPos, u_albedo, roughness);

    } else if (u_proc_mode == 6) {
        // Cloud — self-lit, skip PBR
        albedo = computeCloud(N, V, L, u_albedo, alpha);

        float fogDist = length(v_worldPos - u_camera_pos);
        float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        vec3 color = mix(albedo, u_fog_color, fogFactor);
        color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));
        frag_color = vec4(color, alpha);
        return;

    } else if (u_proc_mode == 9) {
        // Moon — procedural phase rendering
        vec3 moonN = normalize(N);

        // Build view-space right vector for terminator direction
        vec3 vUp = abs(V.y) > 0.99 ? vec3(0.0, 0.0, 1.0) : vec3(0.0, 1.0, 0.0);
        vec3 viewRight = normalize(cross(V, vUp));

        // Local X on moon face: -1 = left, +1 = right (from camera's perspective)
        float localX = dot(moonN, viewRight);

        // Terminator sweeps with phase:
        // 0.0 = new moon (all dark), 0.5 = full (all lit), 1.0 = new again
        float terminatorPos = cos(u_moon_phase * 2.0 * 3.14159);
        float illumination = smoothstep(terminatorPos - 0.12, terminatorPos + 0.12, localX);

        // Moon surface with procedural craters
        vec3 objPos = moonN;
        float crater = noise(objPos.xz * 4.0 + objPos.y * 2.0);
        float mare = smoothstep(0.42, 0.55, crater) * 0.25;
        float detail = (noise(objPos.xz * 12.0 + objPos.yz * 8.0) - 0.5) * 0.08;
        vec3 moonColor = vec3(0.85, 0.87, 0.92) * (1.0 - mare) + detail;

        // Apply illumination (no limb darkening — caused the blackout)
        vec3 litColor = moonColor * illumination;

        // Earthshine: faint blue on dark side
        litColor += vec3(0.02, 0.025, 0.04) * (1.0 - illumination);

        // Gamma, no fog
        frag_color = vec4(pow(max(litColor, vec3(0.0)), vec3(1.0 / 2.2)), 1.0);
        return;

    } else if (u_proc_mode == 10) {
        // Rainbow: UV.y encodes spectral band position (ROY G BIV)
        float band = v_uv.y;
        vec3 rainbow = vec3(0.0);
        rainbow += vec3(1.0, 0.0, 0.0) * smoothstep(0.0, 0.14, band) * smoothstep(0.28, 0.14, band);
        rainbow += vec3(1.0, 0.5, 0.0) * smoothstep(0.14, 0.28, band) * smoothstep(0.42, 0.28, band);
        rainbow += vec3(1.0, 1.0, 0.0) * smoothstep(0.28, 0.42, band) * smoothstep(0.57, 0.42, band);
        rainbow += vec3(0.0, 0.8, 0.0) * smoothstep(0.42, 0.57, band) * smoothstep(0.71, 0.57, band);
        rainbow += vec3(0.0, 0.4, 1.0) * smoothstep(0.57, 0.71, band) * smoothstep(0.85, 0.71, band);
        rainbow += vec3(0.5, 0.0, 1.0) * smoothstep(0.71, 0.85, band) * smoothstep(1.0, 0.85, band);

        // Emission-only, semi-transparent
        float brightness = length(rainbow);
        vec3 color = rainbow * 0.8;

        // Fog
        float fogDist = length(v_worldPos - u_camera_pos);
        float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        color = mix(color, u_fog_color, fogFactor);

        color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));
        frag_color = vec4(color, brightness * u_alpha * 0.4);
        return;

    } else if (u_proc_mode == 11) {
        // ============ GLASS / CRYSTAL ============
        // Transparent with refraction-like color shift, colored tinting, sharp specular
        float NdotV = max(dot(N, V), 0.0);
        float fresnel = pow(1.0 - NdotV, 4.0);
        fresnel = mix(0.04, 1.0, fresnel);

        // Refraction color shift — simulate chromatic dispersion
        vec3 refractDir = refract(-V, N, 0.67); // glass IOR ~1.5
        float dispersion = dot(refractDir, N) * 0.5 + 0.5;
        vec3 glassColor = u_albedo * (0.3 + dispersion * 0.7);

        // Reflection
        vec3 R = reflect(-V, N);
        float skyBlend = clamp(R.y * 2.0, 0.0, 1.0);
        vec3 reflectColor = mix(u_horizon_color, u_sky_color, skyBlend);

        albedo = mix(glassColor, reflectColor, fresnel);
        roughness = 0.02;
        alpha = mix(0.15, 0.6, fresnel) * u_alpha;

    } else if (u_proc_mode == 12) {
        // ============ POLISHED METAL ============
        // Anisotropic highlights, colored reflections, brushed grain
        vec3 metalColor = u_albedo;

        // Brushed grain direction
        float grain = noise(v_worldPos.xz * 40.0 + v_worldPos.y * 20.0);
        float grainLine = sin(v_worldPos.x * 80.0 + grain * 5.0) * 0.5 + 0.5;
        grainLine = smoothstep(0.3, 0.7, grainLine);

        // Anisotropic highlight — stretched along grain
        vec3 tangent = normalize(cross(N, vec3(0.0, 1.0, 0.0)));
        float aniso = pow(abs(dot(tangent, normalize(V + L))), 32.0);

        metalColor *= 0.8 + grainLine * 0.2 + aniso * 0.3;

        // Reflection tinted by metal color (metallic reflection)
        vec3 R = reflect(-V, N);
        float skyB = clamp(R.y * 2.0, 0.0, 1.0);
        vec3 envColor = mix(u_horizon_color, u_sky_color, skyB) * metalColor;
        float fres = pow(1.0 - max(dot(N, V), 0.0), 3.0);

        albedo = mix(metalColor, envColor, 0.3 + fres * 0.5);
        roughness = 0.15 + grainLine * 0.1;

    } else if (u_proc_mode == 13) {
        // ============ FABRIC / CLOTH ============
        // Micro-fiber patterns, soft shading, subtle sheen
        vec3 fabricColor = u_albedo;

        // Weave pattern — cross-hatch
        float warpThread = sin(v_worldPos.x * 60.0 + v_worldPos.z * 2.0) * 0.5 + 0.5;
        float weftThread = sin(v_worldPos.z * 60.0 + v_worldPos.x * 2.0) * 0.5 + 0.5;
        float weave = warpThread * 0.5 + weftThread * 0.5;
        weave = smoothstep(0.3, 0.7, weave);

        fabricColor *= 0.85 + weave * 0.3;

        // Fuzz — scatter at grazing angles (cloth sheen)
        float NdotV = max(dot(N, V), 0.0);
        float fuzz = pow(1.0 - NdotV, 3.0) * 0.15;
        fabricColor += vec3(fuzz) * u_albedo;

        // Micro-noise for fiber texture
        float fiberNoise = noise(v_worldPos.xz * 100.0);
        fabricColor *= 0.95 + fiberNoise * 0.1;

        albedo = fabricColor;
        roughness = 0.75 + weave * 0.15;

    } else if (u_proc_mode == 14) {
        // ============ FIRE / FLAME ============
        // Animated procedural fire, emission-only
        vec2 fireUV = v_worldPos.xz * 2.0;
        float fireT = u_time * 2.5;

        // Upward flow noise
        float n1 = fbm(vec2(fireUV.x * 3.0, fireUV.y * 2.0 - fireT), 4);
        float n2 = noise(vec2(fireUV.x * 6.0, fireUV.y * 4.0 - fireT * 1.5));
        float fireShape = n1 * 0.7 + n2 * 0.3;

        // Height fade (fire tapers upward)
        float heightFade = smoothstep(1.0, 0.0, v_uv.y);
        fireShape *= heightFade;

        // Color gradient: white core → yellow → orange → red → black
        vec3 fireColor = vec3(0.0);
        fireColor = mix(vec3(0.1, 0.0, 0.0), vec3(1.0, 0.2, 0.0), smoothstep(0.1, 0.3, fireShape));
        fireColor = mix(fireColor, vec3(1.0, 0.8, 0.0), smoothstep(0.3, 0.5, fireShape));
        fireColor = mix(fireColor, vec3(1.0, 1.0, 0.8), smoothstep(0.6, 0.9, fireShape));

        // Early return — emission only, no PBR
        float fireAlpha = smoothstep(0.05, 0.2, fireShape) * u_alpha;
        vec3 color = fireColor * 2.0; // HDR emission
        float fogDist = length(v_worldPos - u_camera_pos);
        float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        color = mix(color, u_fog_color, fogFactor);
        color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));
        frag_color = vec4(color, fireAlpha);
        return;

    } else if (u_proc_mode == 15) {
        // ============ LAVA / MAGMA ============
        // Glowing cracks with animated flow over dark rock
        vec3 rockBase = u_albedo * 0.15; // very dark rock

        // Animated crack pattern
        float flowT = u_time * 0.3;
        float crack1 = fbm(v_worldPos.xz * 3.0 + vec2(flowT * 0.2, flowT * 0.1), 4);
        float crack2 = fbm(v_worldPos.xz * 5.0 - vec2(flowT * 0.15, flowT * 0.25), 3);
        float cracks = max(crack1, crack2);
        cracks = smoothstep(0.45, 0.55, cracks); // sharp crack edges

        // Glow color: deep red → orange → yellow in crack centers
        vec3 glowColor = mix(vec3(0.8, 0.1, 0.0), vec3(1.0, 0.6, 0.0), cracks);
        glowColor = mix(glowColor, vec3(1.0, 0.9, 0.4), smoothstep(0.7, 0.95, cracks));

        // Pulsing glow
        float pulse = 0.8 + 0.2 * sin(u_time * 1.5 + v_worldPos.x * 2.0);

        albedo = mix(rockBase, glowColor * pulse, cracks);
        // Emit from cracks
        vec3 emissionAdd = glowColor * cracks * pulse * 1.5;
        albedo += emissionAdd;
        roughness = mix(0.9, 0.3, cracks);

    } else if (u_proc_mode == 16) {
        // ============ ICE / FROST ============
        // Transparent layers, crystal patterns, subsurface blue glow
        float NdotV = max(dot(N, V), 0.0);
        float fresnel = pow(1.0 - NdotV, 3.0);

        // Crystal structure — Voronoi-like pattern
        vec2 iceP = v_worldPos.xz * 8.0;
        float crystal = 0.0;
        for (int i = 0; i < 4; i++) {
            vec2 cellP = floor(iceP) + vec2(float(i % 2), float(i / 2));
            float dist = length(fract(iceP) - hash21(cellP));
            crystal = max(crystal, 1.0 - dist * 2.0);
        }
        crystal = clamp(crystal, 0.0, 1.0);

        // Subsurface scattering — blue glow through ice
        float sss = pow(max(dot(V, L), 0.0), 2.0) * 0.15;
        vec3 iceColor = vec3(0.7, 0.85, 1.0) * (0.8 + crystal * 0.3);
        iceColor += vec3(0.05, 0.1, 0.2) * sss;

        // Frost on surface — white noise patches
        float frostNoise = fbm(v_worldPos.xz * 15.0, 3);
        float frost = smoothstep(0.4, 0.7, frostNoise);
        iceColor = mix(iceColor, vec3(0.95, 0.97, 1.0), frost * 0.5);

        albedo = iceColor;
        roughness = mix(0.05, 0.6, frost);
        alpha = mix(0.4, 0.9, fresnel) * u_alpha;

    } else if (u_proc_mode == 17) {
        // ============ GRASS / VEGETATION ============
        // Blade patterns, translucency, wind-reactive color shift
        vec3 grassColor = u_albedo;

        // Blade pattern — parallel lines with variation
        float blade = sin(v_worldPos.x * 40.0 + noise(v_worldPos.xz * 5.0) * 8.0) * 0.5 + 0.5;
        blade = smoothstep(0.3, 0.7, blade);

        // Color variation: tip lighter, base darker
        float tipFade = v_uv.y; // assumes UV.y = 0 at base, 1 at tip
        grassColor *= 0.7 + tipFade * 0.5 + blade * 0.1;

        // Translucency — light shining through thin blades
        float scatter = pow(max(dot(V, L), 0.0), 4.0);
        grassColor += vec3(0.08, 0.15, 0.02) * scatter;

        // Seasonal tint
        grassColor *= u_season_tint;

        albedo = grassColor;
        roughness = 0.6 + blade * 0.2;

    } else if (u_proc_mode == 18) {
        // ============ NEON / GLOW ============
        // Intense self-illumination with bloom-like falloff and pulsing
        vec3 neonColor = u_emission;
        if (length(neonColor) < 0.01) neonColor = u_albedo;

        // Pulsing glow
        float pulse = 0.85 + 0.15 * sin(u_time * 3.0 + v_worldPos.x * 2.0 + v_worldPos.z * 1.5);

        // Edge glow (fresnel-based bloom simulation)
        float NdotV = max(dot(N, V), 0.0);
        float edgeGlow = pow(1.0 - NdotV, 2.0);

        // Scanline effect (subtle horizontal lines)
        float scanline = sin(v_worldPos.y * 80.0 + u_time * 5.0) * 0.5 + 0.5;
        scanline = smoothstep(0.3, 0.7, scanline);

        vec3 color = neonColor * pulse * (1.5 + edgeGlow * 2.0);
        color *= 0.9 + scanline * 0.1;

        // Early return — pure emission
        float fogDist = length(v_worldPos - u_camera_pos);
        float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        color = mix(color, u_fog_color, fogFactor);
        color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));
        frag_color = vec4(color, u_alpha);
        return;

    } else if (u_proc_mode == 19) {
        // ============ CONCRETE ============
        // Aggregate detail, cracks, stains, weathering
        vec3 concreteBase = u_albedo;

        // Large-scale stains
        float stain = fbm(v_worldPos.xz * 1.5, 3);
        concreteBase *= 0.85 + stain * 0.3;

        // Aggregate — small stone speckles
        float agg = noise(v_worldPos.xz * 30.0);
        float aggMask = smoothstep(0.55, 0.65, agg);
        concreteBase = mix(concreteBase, concreteBase * 1.2, aggMask * 0.3);

        // Cracks
        float crackNoise = noise(v_worldPos.xz * 8.0);
        float crack = smoothstep(0.48, 0.52, crackNoise);
        concreteBase = mix(concreteBase, concreteBase * 0.4, crack * 0.5);

        // Surface pores
        float pores = noise(v_worldPos.xz * 50.0);
        concreteBase *= 0.95 + pores * 0.1;

        albedo = concreteBase;
        roughness = 0.85 + aggMask * 0.1 - crack * 0.2;

    } else if (u_proc_mode == 20) {
        // ============ BRICK ============
        // Individual bricks with mortar, per-brick color variation
        vec3 brickColor = u_albedo;

        // Brick grid — offset every other row
        vec2 brickUV = v_worldPos.xz * vec2(4.0, 8.0);
        float row = floor(brickUV.y);
        brickUV.x += mod(row, 2.0) * 0.5; // offset alternating rows
        vec2 brickID = floor(brickUV);
        vec2 brickLocal = fract(brickUV);

        // Mortar (gap between bricks)
        float mortarX = smoothstep(0.0, 0.06, brickLocal.x) * smoothstep(1.0, 0.94, brickLocal.x);
        float mortarY = smoothstep(0.0, 0.08, brickLocal.y) * smoothstep(1.0, 0.92, brickLocal.y);
        float mortar = 1.0 - mortarX * mortarY; // 1 = mortar, 0 = brick face

        // Per-brick color variation
        float brickVar = hash21(brickID) * 0.3 - 0.15;
        brickColor *= 1.0 + brickVar;

        // Mortar color
        vec3 mortarColor = vec3(0.6, 0.58, 0.55);
        albedo = mix(brickColor, mortarColor, mortar);

        // Weathering — darker at bottom, stains from rain
        float weather = fbm(v_worldPos.xz * 3.0 + v_worldPos.y * 0.5, 2);
        albedo *= 0.85 + weather * 0.15;

        roughness = mix(0.7, 0.9, mortar);

    } else if (u_proc_mode == 21) {
        // ============ TILE / CERAMIC ============
        // Glossy surface with grout grid
        vec3 tileColor = u_albedo;

        // Tile grid
        vec2 tileUV = v_worldPos.xz * 5.0;
        vec2 tileLocal = fract(tileUV);
        vec2 tileID = floor(tileUV);

        // Grout lines
        float groutX = smoothstep(0.0, 0.04, tileLocal.x) * smoothstep(1.0, 0.96, tileLocal.x);
        float groutY = smoothstep(0.0, 0.04, tileLocal.y) * smoothstep(1.0, 0.96, tileLocal.y);
        float grout = 1.0 - groutX * groutY;

        // Per-tile subtle color variation
        float tileVar = hash21(tileID) * 0.1 - 0.05;
        tileColor *= 1.0 + tileVar;

        vec3 groutColor = vec3(0.5, 0.48, 0.45);
        albedo = mix(tileColor, groutColor, grout);

        // Glossy tile surface, rough grout
        roughness = mix(0.08, 0.8, grout);

    } else if (u_proc_mode == 22) {
        // ============ LEATHER ============
        // Pore detail, wrinkle patterns, wear marks
        vec3 leatherColor = u_albedo;

        // Large wrinkle pattern
        float wrinkle = fbm(v_worldPos.xz * 8.0, 3);
        wrinkle = smoothstep(0.35, 0.65, wrinkle);

        // Pore texture — fine dots
        float pores = noise(v_worldPos.xz * 60.0);
        pores = smoothstep(0.4, 0.6, pores);

        leatherColor *= 0.8 + wrinkle * 0.3 - pores * 0.08;

        // Wear — lighter on raised areas (edges, folds)
        float wear = pow(wrinkle, 2.0) * 0.15;
        leatherColor += vec3(wear);

        // Subtle sheen at grazing angles
        float NdotV = max(dot(N, V), 0.0);
        float sheen = pow(1.0 - NdotV, 4.0) * 0.08;
        leatherColor += vec3(sheen);

        albedo = leatherColor;
        roughness = 0.55 + wrinkle * 0.2 + pores * 0.1;

    } else if (u_proc_mode == 23) {
        // ============ SKIN / ORGANIC ============
        // Subsurface scattering approximation, pore detail
        vec3 skinColor = u_albedo;

        // SSS approximation: light wraps around and tints reddish
        float NdotL = max(dot(N, L), 0.0);
        float wrapDiffuse = max(0.0, (dot(N, L) + 0.5) / 1.5);
        float sssStrength = (wrapDiffuse - NdotL) * 0.8;
        vec3 sssColor = vec3(0.8, 0.2, 0.1) * sssStrength; // blood red scatter

        // Back-lighting scatter
        float backScatter = pow(max(dot(V, L), 0.0), 3.0) * 0.12;
        sssColor += vec3(0.6, 0.15, 0.05) * backScatter;

        // Pore detail
        float pores = noise(v_worldPos.xz * 80.0 + v_worldPos.y * 40.0);
        skinColor *= 0.95 + pores * 0.1;

        // Oily sheen
        float NdotV = max(dot(N, V), 0.0);
        float oilSheen = pow(1.0 - NdotV, 5.0) * 0.06;

        albedo = skinColor + sssColor;
        albedo += vec3(oilSheen);
        roughness = 0.45 + pores * 0.15;

    } else if (u_proc_mode == 24) {
        // ============ PARTICLE / SMOKE ============
        // Soft-edge billboard, opacity falloff from center
        float dist = length(v_uv - vec2(0.5));
        float softEdge = 1.0 - smoothstep(0.3, 0.5, dist);

        // Animated noise for smoke turbulence
        float smokeNoise = fbm(v_uv * 3.0 + u_time * 0.5, 3);
        softEdge *= 0.5 + smokeNoise * 0.5;

        vec3 color = u_emission + u_albedo * 0.5;

        float fogDist = length(v_worldPos - u_camera_pos);
        float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        color = mix(color, u_fog_color, fogFactor);
        color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));
        frag_color = vec4(color, softEdge * u_alpha);
        return;

    } else if (u_proc_mode == 25) {
        // ============ HOLOGRAM ============
        // Scanlines, color fringing, edge glow, transparency, flicker
        vec3 holoColor = u_emission;
        if (length(holoColor) < 0.01) holoColor = vec3(0.2, 0.8, 1.0);

        // Scanlines
        float scanline = sin(v_worldPos.y * 120.0 + u_time * 8.0) * 0.5 + 0.5;
        scanline = smoothstep(0.3, 0.5, scanline);

        // Color fringing (chromatic shift)
        float fringe = sin(v_worldPos.y * 200.0 + u_time * 3.0) * 0.02;
        holoColor.r += fringe;
        holoColor.b -= fringe;

        // Edge glow (fresnel)
        float NdotV = max(dot(N, V), 0.0);
        float edgeGlow = pow(1.0 - NdotV, 3.0);

        // Flicker
        float flicker = 0.9 + 0.1 * sin(u_time * 15.0 + sin(u_time * 47.0) * 3.0);

        vec3 color = holoColor * (0.5 + scanline * 0.5 + edgeGlow * 1.5) * flicker;

        float fogDist = length(v_worldPos - u_camera_pos);
        float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
        fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
        color = mix(color, u_fog_color, fogFactor);
        color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));
        frag_color = vec4(color, (0.3 + edgeGlow * 0.5) * scanline * flicker * u_alpha);
        return;

    } else {
        // Standard material
        float nse = noise(v_worldPos.xz * 0.4);
        float noiseMask = smoothstep(0.3, 0.9, roughness);
        albedo = u_albedo * (1.0 + (nse - 0.5) * 0.12 * noiseMask);
    }

    // ---- Weather surface effects (wet, snow, dew) ----
    // Skip: water (2), cloud (6), moon (9), fire (14), neon (18), particle (24), hologram (25)
    // Also skip emission-dominant surfaces (sky domes, glow objects)
    float emissionStrength = u_emission.r + u_emission.g + u_emission.b;
    bool isPhysicalSurface = u_proc_mode != 2 && u_proc_mode != 6 && u_proc_mode != 9
        && u_proc_mode != 14 && u_proc_mode != 18 && u_proc_mode != 24 && u_proc_mode != 25
        && emissionStrength < 0.5;
    if (isPhysicalSurface) {
        // Wet surfaces: rain + morning dew
        float wetness = max(u_rain_intensity, u_dew_wetness * 0.6);
        if (wetness > 0.01) {
            // Puddles in low-lying areas
            float puddleNoise = noise(v_worldPos.xz * 2.0);
            float lowArea = smoothstep(0.5, 0.0, v_worldPos.y);
            float puddle = smoothstep(0.4, 0.6, puddleNoise) * lowArea * wetness;
            // Darken albedo (wet absorbs more light)
            albedo *= 1.0 - wetness * 0.3 - puddle * 0.2;
            // Reduce roughness (wet = shinier, more specular)
            roughness = mix(roughness, 0.1, wetness * 0.5 + puddle * 0.4);
        }
        // Snow accumulation on upward-facing surfaces
        if (u_snow_coverage > 0.01) {
            float upFacing = max(dot(N, vec3(0.0, 1.0, 0.0)), 0.0);
            // Snow settles on any surface facing up (>15°)
            float snowAmt = u_snow_coverage * smoothstep(0.15, 0.5, upFacing);
            // Patchy coverage via noise — but not too aggressive
            float snowNoise = fbm(v_worldPos.xz * 2.0, 2);
            snowAmt *= smoothstep(0.2, 0.5, snowNoise);
            // Stronger blend — snow is very white and opaque
            snowAmt = min(1.0, snowAmt * 1.5);
            albedo = mix(albedo, vec3(0.96, 0.97, 1.0), snowAmt);
            roughness = mix(roughness, 0.65, snowAmt);
        }
    }

    // ---- PBR Lighting (sand + standard materials) ----
    float shininess = exp2(10.0 * (1.0 - roughness) + 1.0);

    vec3 F0 = mix(vec3(0.04), albedo, u_metallic);

    // Shadow factor (from primary light — index 0)
    float shadow = calcShadow(v_worldPos);

    // Ambient shadow strength scales with light intensity.
    // Strong shadows in bright sunlight, subtle in moonlight.
    float primaryIntensity = u_dir_light_count > 0 ? u_dir_lights[0].intensity : 0.0;
    float shadowStrength = clamp(primaryIntensity / 1.0, 0.0, 1.0); // 0 at night, 1 at noon
    float ambientShadow = mix(1.0, mix(0.5, 1.0, shadow), shadowStrength);
    vec3 color = u_ambient_color * u_ambient_intensity * albedo * (1.0 - u_metallic * 0.9) * ambientShadow;

    // All directional lights (with Half-Lambert wrap for terrain/sand)
    for (int dl = 0; dl < u_dir_light_count; dl++) {
        vec3 dL = normalize(-u_dir_lights[dl].direction);
        vec3 dH = normalize(V + dL);
        float rawNdotL = dot(N, dL);
        float dNdotL = max(rawNdotL, 0.0);

        // Half-Lambert: wraps lighting around surfaces so horizontal terrain
        // still receives light at low sun angles (sunrise/sunset golden glow).
        // Standard: max(NdotL, 0) gives 0 at 90°.
        // Half-Lambert: (NdotL * 0.5 + 0.5)² gives 0.25 at 90° — much softer.
        float halfLambert = rawNdotL * 0.5 + 0.5;
        halfLambert *= halfLambert;
        // Blend: use half-Lambert for diffuse, standard NdotL for specular
        float diffuseNdotL = mix(dNdotL, halfLambert, 0.4); // 40% wrap

        // Shadow only applies to primary light (index 0)
        float dShadow = (dl == 0) ? shadow : 1.0;

        if (diffuseNdotL > 0.0) {
            color += albedo * u_dir_lights[dl].color * u_dir_lights[dl].intensity * diffuseNdotL * dShadow * (1.0 - u_metallic);
        }
        if (dNdotL > 0.0) {
            float dNdotH = max(dot(N, dH), 0.0);
            float spec = pow(dNdotH, shininess) * (shininess + 2.0) / 8.0;
            vec3 F = fresnelSchlick(max(dot(dH, V), 0.0), F0);
            color += F * u_dir_lights[dl].color * u_dir_lights[dl].intensity * spec * dNdotL * dShadow;
        }
    }

    // Point lights
    for (int i = 0; i < u_point_light_count; i++) {
        vec3 Lp = u_point_lights[i].position - v_worldPos;
        float dist = length(Lp);
        Lp = normalize(Lp);
        vec3 Hp = normalize(V + Lp);

        float radius = max(u_point_lights[i].radius, 0.001);
        float atten = clamp(1.0 - (dist * dist) / (radius * radius), 0.0, 1.0);
        atten *= atten;

        float NdotPL = max(dot(N, Lp), 0.0);
        if (NdotPL > 0.0) {
            color += albedo * u_point_lights[i].color * u_point_lights[i].intensity
                     * NdotPL * atten * (1.0 - u_metallic);
            float NdotHP = max(dot(N, Hp), 0.0);
            float specP = pow(NdotHP, shininess) * (shininess + 2.0) / 8.0;
            vec3 FP = fresnelSchlick(max(dot(Hp, V), 0.0), F0);
            color += FP * u_point_lights[i].color * u_point_lights[i].intensity
                     * specP * NdotPL * atten;
        }
    }

    // Emission
    color += u_emission;

    // Fog
    float fogDist = length(v_worldPos - u_camera_pos);
    float fogFactor = clamp((fogDist - u_fog_near) / (u_fog_far - u_fog_near), 0.0, 1.0);
    fogFactor = 1.0 - exp(-fogFactor * fogFactor * 3.0);
    color = mix(color, u_fog_color, fogFactor);

    // Gamma correction
    color = pow(max(color, vec3(0.0)), vec3(1.0 / 2.2));

    frag_color = vec4(color, alpha);
}
