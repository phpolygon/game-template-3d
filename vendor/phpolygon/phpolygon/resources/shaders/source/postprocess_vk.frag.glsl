#version 410 core

in vec2 v_uv;

uniform sampler2D u_scene_color;   // HDR scene color
uniform sampler2D u_scene_depth;   // Scene depth buffer
uniform vec3 u_camera_pos;
uniform vec3 u_sun_direction;
uniform float u_sun_intensity;
uniform float u_time;
uniform int u_width;
uniform int u_height;

// Post-processing toggles
uniform int u_enable_ssao;
uniform int u_enable_bloom;
uniform int u_enable_godrays;
uniform int u_enable_dof;
uniform float u_dof_focus_distance;
uniform float u_dof_range;

out vec4 frag_color;

// ================================================================
//  Noise (for SSAO)
// ================================================================

float hash(vec2 p) {
    p = fract(p * vec2(443.897, 441.423));
    p += dot(p, p + 19.19);
    return fract(p.x * p.y);
}

// ================================================================
//  SSAO — Screen-Space Ambient Occlusion
// ================================================================

float computeSSAO(vec2 uv) {
    float depth = texture(u_scene_depth, uv).r;
    if (depth >= 1.0) return 1.0; // sky

    float occlusion = 0.0;
    float radius = 0.02;
    int samples = 16;

    for (int i = 0; i < samples; i++) {
        float angle = float(i) * 2.399 + hash(uv * float(i + 1)) * 6.28;
        float r = radius * (float(i + 1) / float(samples));
        vec2 offset = vec2(cos(angle), sin(angle)) * r;

        float sampleDepth = texture(u_scene_depth, uv + offset).r;
        float diff = depth - sampleDepth;

        // Only occlude if sample is closer (in front)
        if (diff > 0.0001 && diff < 0.01) {
            occlusion += 1.0;
        }
    }

    return 1.0 - (occlusion / float(samples)) * 0.6;
}

// ================================================================
//  Bloom — Bright pixel extraction + blur
// ================================================================

vec3 computeBloom(vec2 uv) {
    vec3 bloom = vec3(0.0);
    float bloomThreshold = 1.0;

    // Sample bright pixels in a star pattern
    vec2 texelSize = 1.0 / vec2(float(u_width), float(u_height));

    for (int x = -3; x <= 3; x++) {
        for (int y = -3; y <= 3; y++) {
            vec2 offset = vec2(float(x), float(y)) * texelSize * 3.0;
            vec3 sample_color = texture(u_scene_color, uv + offset).rgb;
            float brightness = dot(sample_color, vec3(0.2126, 0.7152, 0.0722));
            if (brightness > bloomThreshold) {
                float weight = 1.0 / (1.0 + float(x * x + y * y));
                bloom += (sample_color - vec3(bloomThreshold)) * weight;
            }
        }
    }

    return bloom * 0.15;
}

// ================================================================
//  Volumetric Light (God Rays)
// ================================================================

vec3 computeGodRays(vec2 uv) {
    // Project sun position to screen space (approximate)
    vec2 sunScreen = vec2(0.5, 0.7); // TODO: actual sun screen projection from u_sun_direction

    vec2 deltaUV = (uv - sunScreen) * (1.0 / 64.0);
    vec2 sampleUV = uv;
    float illumination = 0.0;
    float decay = 0.96;
    float weight = 1.0;

    for (int i = 0; i < 64; i++) {
        sampleUV -= deltaUV;
        float sampleDepth = texture(u_scene_depth, clamp(sampleUV, 0.0, 1.0)).r;

        // If depth = 1.0 (sky), light passes through
        if (sampleDepth >= 0.9999) {
            illumination += weight * 0.5;
        }
        weight *= decay;
    }

    illumination /= 64.0;
    vec3 sunColor = vec3(1.0, 0.9, 0.7) * u_sun_intensity;
    return sunColor * illumination * 0.3;
}

// ================================================================
//  Depth of Field
// ================================================================

vec3 computeDOF(vec2 uv, vec3 sceneColor) {
    float depth = texture(u_scene_depth, uv).r;

    // Linearize depth (approximate)
    float linearDepth = 0.3 / (1.0 - depth + 0.001); // near = 0.3

    // Circle of confusion
    float coc = abs(linearDepth - u_dof_focus_distance) / u_dof_range;
    coc = clamp(coc, 0.0, 1.0);

    if (coc < 0.01) return sceneColor;

    // Simple box blur scaled by CoC
    vec2 texelSize = 1.0 / vec2(float(u_width), float(u_height));
    vec3 blurred = vec3(0.0);
    float total = 0.0;
    int radius = int(coc * 4.0) + 1;

    for (int x = -radius; x <= radius; x++) {
        for (int y = -radius; y <= radius; y++) {
            vec2 offset = vec2(float(x), float(y)) * texelSize * coc * 2.0;
            blurred += texture(u_scene_color, uv + offset).rgb;
            total += 1.0;
        }
    }

    return blurred / total;
}

// ================================================================
//  ACES Filmic Tone Mapping
// ================================================================

vec3 acesToneMap(vec3 x) {
    float a = 2.51;
    float b = 0.03;
    float c = 2.43;
    float d = 0.59;
    float e = 0.14;
    return clamp((x * (a * x + b)) / (x * (c * x + d) + e), 0.0, 1.0);
}

// ================================================================
//  Main
// ================================================================

void main() {
    vec2 uv = v_uv;
    vec3 color = texture(u_scene_color, uv).rgb;

    // SSAO
    if (u_enable_ssao == 1) {
        float ao = computeSSAO(uv);
        color *= ao;
    }

    // Bloom
    if (u_enable_bloom == 1) {
        vec3 bloom = computeBloom(uv);
        color += bloom;
    }

    // God Rays
    if (u_enable_godrays == 1 && u_sun_intensity > 0.1) {
        vec3 rays = computeGodRays(uv);
        color += rays;
    }

    // Depth of Field
    if (u_enable_dof == 1) {
        color = computeDOF(uv, color);
    }

    // ACES Tone Mapping (HDR → LDR)
    color = acesToneMap(color);

    // Gamma correction
    color = pow(color, vec3(1.0 / 2.2));

    frag_color = vec4(color, 1.0);
}
