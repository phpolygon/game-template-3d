---
description: "PHPolygon OpenGL 3D Renderer — render flow, shader uniforms, material system, shadow passes, instancing. Reference when comparing OpenGL and Vulkan backends."
---

# OpenGL 3D Renderer — Complete Reference

## File Locations

| File | Purpose |
|------|---------|
| `vendor/phpolygon/phpolygon/src/Rendering/OpenGLRenderer3D.php` | Main renderer — translates RenderCommandList to GL calls |
| `vendor/phpolygon/phpolygon/src/Rendering/ShadowMapRenderer.php` | Depth-based shadow map (2048x2048) |
| `vendor/phpolygon/phpolygon/src/Rendering/CloudShadowRenderer.php` | Cloud opacity shadow map (1024x1024) |
| `vendor/phpolygon/phpolygon/src/Rendering/PostProcessPipeline.php` | Post-processing (SSAO, Bloom, God Rays, DOF, Tone Mapping) |
| `vendor/phpolygon/phpolygon/resources/shaders/source/mesh3d.vert.glsl` | Vertex shader |
| `vendor/phpolygon/phpolygon/resources/shaders/source/mesh3d.frag.glsl` | Fragment shader |
| `vendor/phpolygon/phpolygon/resources/shaders/source/skybox.vert.glsl` | Skybox vertex shader |
| `vendor/phpolygon/phpolygon/resources/shaders/source/skybox.frag.glsl` | Skybox fragment shader |
| `vendor/phpolygon/phpolygon/resources/shaders/source/postprocess.vert.glsl` | Post-process vertex shader |
| `vendor/phpolygon/phpolygon/resources/shaders/source/postprocess.frag.glsl` | Post-process fragment shader |

---

## 1. Full Render Flow

The `render(RenderCommandList)` method executes this pipeline each frame:

### Pass 0: Setup
- Enable `GL_DEPTH_TEST`, `GL_MULTISAMPLE`; disable `GL_CULL_FACE`
- Clear depth buffer
- Set all uniform defaults (ambient, fog, weather, wave animation, etc.)
- Bind dummy textures to prevent "unloadable texture" warnings
- If post-processing is enabled, redirect output to HDR offscreen FBO via `PostProcessPipeline::beginSceneCapture()`

### Pass 1: Collect Non-Draw Commands
Iterate the command list and collect state:
- `SetCamera` — sets `u_view`, `u_projection`, `u_camera_pos`
- `SetAmbientLight` — sets `u_ambient_color`, `u_ambient_intensity`
- `SetDirectionalLight` — accumulates into `u_dir_lights[]` array (max 16)
- `AddPointLight` — accumulates into `u_point_lights[]` array (max 8)
- `SetFog` — sets `u_fog_color`, `u_fog_near`, `u_fog_far`
- `SetSkybox` — stores pending skybox ID
- `SetSkyColors` — sets `u_sky_color`, `u_horizon_color`
- `SetEnvironmentMap` — binds cubemap to texture unit 5
- `SetWeatherUniforms` — sets rain, snow, temperature, dew, storm uniforms

After collection, upload all directional and point lights as uniform arrays.

### Pass 2: Shadow Map (depth from sun)
Calls `renderShadowMap()`:
1. Lazy-init `ShadowMapRenderer` (2048x2048 depth FBO)
2. Find the brightest directional light (skip if intensity < 0.05)
3. Compute orthographic light-space matrix (`orthoSize=60, near=0.5, far=200`)
4. Bind shadow FBO, set light-space matrix as view, identity as projection
5. Draw only opaque geometry (alpha >= 0.9), skipping sky/sun/moon/cloud/precipitation materials
6. Uses the same main shader program but with `u_proc_mode=0`, `u_dir_light_count=0`, etc.

### Pass 3: Cloud Shadow (opacity from sun)
Runs immediately after the depth shadow pass:
1. Lazy-init `CloudShadowRenderer` (1024x1024 R8 color FBO + depth RBO)
2. Same light-space matrix as geometry shadows
3. Draw ONLY `cloud_*` materials — their opacity is written to the R channel
4. Cloud shadow intensity = `(1.0 - alpha) * 0.5 + 0.1`

### Pass 4: Bind Shadow Maps for Main Pass
- Bind depth shadow map to texture unit 6, set `u_has_shadow_map = 1`
- Bind cloud shadow map to texture unit 7, set `u_has_cloud_shadow = 1` (only if cloud geometry exists)
- Upload `u_light_space_matrix`
- Restore main viewport and re-upload camera + directional lights

### Pass 5a: Opaque Geometry
- `glDepthMask(true)`, `glDisable(GL_BLEND)`
- Iterate commands: draw `DrawMesh` and `DrawMeshInstanced` where material `alpha >= 1.0`
- `SetWaveAnimation` commands update vertex animation uniforms inline

### Pass 5b: Transparent Geometry
- `glDepthMask(false)`, `glEnable(GL_BLEND)`, `glBlendFunc(GL_SRC_ALPHA, GL_ONE_MINUS_SRC_ALPHA)`
- Draw `DrawMesh` and `DrawMeshInstanced` where material `alpha < 1.0`
- Restore depth mask and disable blend

### Pass 6: Skybox
- If a skybox was requested (`SetSkybox` command), render it with a separate shader program
- Uses `GL_LEQUAL` depth func so skybox renders at maximum depth
- Cubemap loaded via `CubemapRegistry` and cached

### Pass 7: Post-Processing
- If enabled, calls `PostProcessPipeline::applyAndPresent()`
- Passes sun direction + intensity for god rays
- Passes view/projection matrices for sun screen-space projection
- Applies SSAO, Bloom, God Rays, DOF (optional), Tone Mapping as a fullscreen pass
- Restores main shader program for any subsequent 2D overlay rendering

---

## 2. All Uniform Names, Types, and Purposes

### Vertex Shader Uniforms

| Uniform | Type | Purpose |
|---------|------|---------|
| `u_model` | `mat4` | Model matrix (non-instanced draws) |
| `u_view` | `mat4` | View matrix from camera |
| `u_projection` | `mat4` | Projection matrix from camera |
| `u_normal_matrix` | `mat3` | Normal matrix (transpose(inverse(model))) for non-instanced draws |
| `u_use_instancing` | `int` | 0 = use `u_model`, 1 = use per-instance attribute matrices |
| `u_time` | `float` | Global time in seconds (increments ~0.016/frame) |
| `u_vertex_anim` | `int` | 0 = no animation, 1 = GPU wave animation |
| `u_wave_amplitude` | `float` | Wave animation amplitude |
| `u_wave_frequency` | `float` | Wave animation frequency |
| `u_wave_phase` | `float` | Wave animation phase offset |
| `u_temperature` | `float` | Temperature for heat shimmer vertex displacement (active above 30.0) |
| `u_camera_pos` | `vec3` | Camera world position (used for heat shimmer distance fade) |

### Fragment Shader Uniforms — Lighting

| Uniform | Type | Purpose |
|---------|------|---------|
| `u_ambient_color` | `vec3` | Ambient light color (default: white) |
| `u_ambient_intensity` | `float` | Ambient light intensity (default: 0.1) |
| `u_dir_lights[i].direction` | `vec3` | Directional light direction (max 16) |
| `u_dir_lights[i].color` | `vec3` | Directional light color |
| `u_dir_lights[i].intensity` | `float` | Directional light intensity |
| `u_dir_light_count` | `int` | Number of active directional lights |
| `u_point_lights[i].position` | `vec3` | Point light world position (max 8) |
| `u_point_lights[i].color` | `vec3` | Point light color |
| `u_point_lights[i].intensity` | `float` | Point light intensity |
| `u_point_lights[i].radius` | `float` | Point light attenuation radius |
| `u_point_light_count` | `int` | Number of active point lights |

### Fragment Shader Uniforms — Material

| Uniform | Type | Purpose |
|---------|------|---------|
| `u_albedo` | `vec3` | Base color (from MaterialRegistry) |
| `u_emission` | `vec3` | Emission color |
| `u_roughness` | `float` | PBR roughness (0 = mirror, 1 = matte) |
| `u_metallic` | `float` | PBR metallic (0 = dielectric, 1 = metal) |
| `u_alpha` | `float` | Opacity (1.0 = opaque) |
| `u_proc_mode` | `int` | Procedural material mode (see section 8) |

### Fragment Shader Uniforms — Environment and Fog

| Uniform | Type | Purpose |
|---------|------|---------|
| `u_fog_color` | `vec3` | Fog color (default: 0.5, 0.5, 0.5) |
| `u_fog_near` | `float` | Fog start distance (default: 50.0) |
| `u_fog_far` | `float` | Fog end distance (default: 200.0) |
| `u_camera_pos` | `vec3` | Camera position for fog distance calc |
| `u_time` | `float` | Time for animated materials |
| `u_sky_color` | `vec3` | Sky zenith color for env reflection fallback |
| `u_horizon_color` | `vec3` | Horizon color for env reflection fallback |
| `u_environment_map` | `samplerCube` | Cubemap environment map (texture unit 5) |
| `u_has_environment_map` | `int` | 0/1 flag — whether cubemap is bound |

### Fragment Shader Uniforms — Shadows

| Uniform | Type | Purpose |
|---------|------|---------|
| `u_shadow_map` | `sampler2DShadow` | Depth shadow map (texture unit 6, hardware PCF) |
| `u_has_shadow_map` | `int` | 0/1 flag |
| `u_light_space_matrix` | `mat4` | Light-space transform for shadow lookup |
| `u_cloud_shadow_map` | `sampler2D` | Cloud opacity map (texture unit 7) |
| `u_has_cloud_shadow` | `int` | 0/1 flag |

### Fragment Shader Uniforms — Weather

| Uniform | Type | Purpose |
|---------|------|---------|
| `u_rain_intensity` | `float` | Rain wetness (0 = dry, 1 = heavy rain) |
| `u_snow_coverage` | `float` | Snow accumulation (0 = none, 1 = full cover) |
| `u_temperature` | `float` | Temperature in Celsius (affects vertex shimmer + surface) |
| `u_dew_wetness` | `float` | Morning dew wetness factor |
| `u_storm_intensity` | `float` | Storm intensity factor |

### Fragment Shader Uniforms — Special

| Uniform | Type | Purpose |
|---------|------|---------|
| `u_moon_phase` | `float` | Moon phase (0.0 = new, 0.5 = full, 1.0 = new again). Encoded via material roughness by DayNightSystem. |
| `u_season_tint` | `vec3` | Seasonal color tint multiplier (1,1,1 = no change). Used by sand terrain. |

### Texture Unit Assignments

| Unit | Sampler | Content |
|------|---------|---------|
| 5 | `u_environment_map` | Cubemap (or 1x1 dummy) |
| 6 | `u_shadow_map` | Depth shadow map 2048x2048 (or 1x1 dummy) |
| 7 | `u_cloud_shadow_map` | Cloud opacity map 1024x1024 (or 1x1 dummy) |

---

## 3. Material System — Per-Draw Uniforms

The `applyMaterial(string $materialId)` method sets per-draw uniforms:

1. **Resolve `u_proc_mode`** from material ID prefix (cached after first lookup)
2. **Special proc_mode handling:**
   - Mode 9 (moon): reads `roughness` from material as `u_moon_phase`
   - Mode 1 (sand): computes `u_season_tint` as ratio of material albedo to default sand color
3. **Upload material properties:**
   - `u_albedo` — from `$material->albedo` (Color)
   - `u_emission` — from `$material->emission` (Color)
   - `u_roughness` — from `$material->roughness` (float)
   - `u_metallic` — from `$material->metallic` (float)
   - `u_alpha` — from `$material->alpha` (float)
4. **Fallback** if material not found: grey albedo (0.8), roughness 0.5, no emission, no metallic, full alpha

### Proc Mode Resolution Table

| Mode | Material ID Prefixes |
|------|---------------------|
| 0 | Default / standard |
| 1 | `sand_terrain*` |
| 2 | `water_*` |
| 3 | `rock*` |
| 4 | `palm_trunk*` |
| 5 | `palm_branch*`, `palm_leaves*`, `palm_leaf*`, `palm_canopy*`, `palm_frond*` |
| 6 | `cloud_*` |
| 7 | `hut_wood*`, `hut_door*`, `hut_table*`, `hut_chair*`, `hut_floor*`, `hut_window*` |
| 8 | `hut_thatch*` |
| 9 | `moon_disc*` |
| 10 | `rainbow*` |
| 11 | `glass*`, `crystal*`, `window_glass*` |
| 12 | `chrome*`, `steel*`, `copper*`, `gold*`, `iron*`, `polished_metal*` |
| 13 | `fabric*`, `cloth*`, `canvas*`, `silk*`, `cotton*`, `wool*` |
| 14 | `fire*`, `flame*`, `torch*` |
| 15 | `lava*`, `magma*`, `molten*` |
| 16 | `ice*`, `frost*`, `frozen*` |
| 17 | `grass*`, `lawn*`, `vegetation*` |
| 18 | `neon*`, `glow*`, `led*` |
| 19 | `concrete*`, `asphalt*`, `cement*` |
| 20 | `brick*`, `masonry*` |
| 21 | `tile*`, `ceramic*`, `porcelain*` |
| 22 | `leather*`, `hide*` |
| 23 | `skin*`, `flesh*`, `organic*` |
| 24 | `particle*`, `smoke*`, `dust*` |
| 25 | `hologram*`, `holo*`, `cyber*` |

---

## 4. Instancing System

### Two VAO Architectures

**Non-instanced (DrawMesh):**
- One VAO per mesh with interleaved VBO: `position(3) + normal(3) + uv(2) = 8 floats/vertex`
- Uses EBO (index buffer) for indexed drawing
- Drawn with `glDrawElements(GL_TRIANGLES, indexCount, GL_UNSIGNED_INT, 0)`
- Model matrix set via `u_model` uniform; `u_use_instancing = 0`

**Instanced (DrawMeshInstanced):**
- Separate VAO per mesh with **expanded** (non-indexed) vertices — required because `glDrawArraysInstanced` does not use index buffers
- Vertex data duplicated per triangle from the indexed MeshData
- Same interleaved layout: `position(3) + normal(3) + uv(2)`, stride = 32 bytes
- Instance VBO stores per-instance model matrices as 4 `vec4` columns at attribute locations 3-6
- `glVertexAttribDivisor(loc, 1)` marks locations 3-6 as per-instance
- Drawn with `glDrawArraysInstanced(GL_TRIANGLES, 0, expandedVertexCount, instanceCount)`
- `u_use_instancing = 1` in shader

### Instance Buffer Upload

- For each `DrawMeshInstanced`, a `FloatBuffer` is built from the `Mat4[]` array (16 floats per matrix)
- Uploaded via `glBufferData(GL_ARRAY_BUFFER, buffer, GL_DYNAMIC_DRAW)` each frame
- **Static optimization:** When `$isStatic = true`, the PHP-side `FloatBuffer` is cached by `meshId:materialId` key, skipping the foreach loop on subsequent frames. The GPU upload still happens each frame but the PHP overhead of iterating 260k+ floats is eliminated.

### Vertex Attribute Layout

| Location | Attribute | Type | Per-instance |
|----------|-----------|------|--------------|
| 0 | `a_position` | `vec3` | No |
| 1 | `a_normal` | `vec3` | No |
| 2 | `a_uv` | `vec2` | No |
| 3 | `a_instance_model_col0` | `vec4` | Yes (divisor=1) |
| 4 | `a_instance_model_col1` | `vec4` | Yes (divisor=1) |
| 5 | `a_instance_model_col2` | `vec4` | Yes (divisor=1) |
| 6 | `a_instance_model_col3` | `vec4` | Yes (divisor=1) |

---

## 5. Shadow Map Renderer Flow

**Class:** `ShadowMapRenderer` (2048x2048 depth-only FBO)

### Initialization
- Depth texture: `GL_DEPTH_COMPONENT`, `GL_FLOAT`, 2048x2048
- Filter: `GL_LINEAR` (enables hardware PCF)
- Wrap: `GL_CLAMP_TO_BORDER` with border color = 1.0 (no shadow outside)
- Compare mode: `GL_COMPARE_REF_TO_TEXTURE` / `GL_LEQUAL`
- FBO with only depth attachment, `glDrawBuffer(GL_NONE)`

### Light Matrix Computation
- `updateLightMatrix(Vec3 $sunDirection)`:
  - Light position = `-normalize(dir) * 80.0` (80 units from origin)
  - Target = world origin
  - Up = `(0,0,1)` when sun is nearly vertical, else `(0,1,0)`
  - View = lookAt(lightPos, target, up)
  - Projection = orthographic: `-orthoSize..+orthoSize` (default 60), near=0.5, far=200
  - lightSpaceMatrix = projection * view

### Shadow Pass
- `beginShadowPass()`: bind FBO, set viewport to 2048x2048, enable `GL_DEPTH_TEST`, clear depth, enable front-face culling (`GL_FRONT`) to reduce peter-panning
- Main renderer draws opaque geometry using the same shader but with simplified uniforms
- `endShadowPass()`: unbind FBO, disable cull face

### Fragment Shader Shadow Lookup
- `calcShadow(vec3 worldPos)`:
  - Transform world position to light-space via `u_light_space_matrix`
  - Perspective divide + remap to [0,1]
  - Outside shadow map = no shadow (return 1.0)
  - 3x3 PCF kernel using `texture(u_shadow_map, vec3(uv, refDepth))` (hardware comparison)
  - Bias = 0.002
  - texelSize = 1/2048

---

## 6. Cloud Shadow Renderer Flow

**Class:** `CloudShadowRenderer` (1024x1024 R8 color FBO + depth RBO)

### Initialization
- Color texture: `GL_R8` format — stores cloud opacity in R channel
- Wrap: `GL_CLAMP_TO_BORDER` with border color = 0.0 (no cloud shadow outside)
- Depth renderbuffer for correct occlusion between overlapping clouds

### Cloud Shadow Pass
- Uses same light-space matrix as the geometry shadow pass
- Renders ONLY `cloud_*` materials
- Cloud opacity written as: `(1.0 - material_alpha) * 0.5 + 0.1`
- Written to `u_albedo` (R channel) — the fragment shader just outputs this as color

### Fragment Shader Cloud Shadow Lookup
- 5x5 Gaussian-weighted blur for soft cloud shadow edges
- Weights: `[1, 2, 4, 2, 1]` per axis (separable)
- 3x spread on texel size for wider penumbra
- Cloud shadow attenuates sunlight: `shadow *= (1.0 - cloudShadow * 0.7)`
- Clouds don't fully block light — 30% still scatters through

---

## 7. Post-Processing Pipeline

**Class:** `PostProcessPipeline`

### Architecture
- HDR offscreen FBO with color texture + depth texture
- Fullscreen quad shader (`postprocess.vert.glsl` / `postprocess.frag.glsl`)
- Renders to the default framebuffer (screen)

### Available Effects
| Effect | Default | Description |
|--------|---------|-------------|
| SSAO | Enabled | Screen-space ambient occlusion |
| Bloom | Enabled | Bright area glow |
| God Rays | Enabled | Volumetric sun shafts (needs sun direction + view/proj matrices) |
| DOF | Disabled | Depth-of-field blur (configurable focus distance + range) |
| Tone Mapping | Always on | HDR to LDR conversion |

### Integration with Main Renderer
1. Before scene rendering: `postProcess->beginSceneCapture()` (binds HDR FBO)
2. Scene renders into HDR FBO (all passes: shadow, opaque, transparent, skybox)
3. After scene: `postProcess->applyAndPresent()` reads HDR color + depth, applies effects, blits to screen
4. Sun data and view/projection matrices passed for god rays

---

## 8. Vertex and Fragment Shader Details

### Vertex Shader (`mesh3d.vert.glsl`)

**Inputs:** `a_position (loc=0)`, `a_normal (loc=1)`, `a_uv (loc=2)`, instance model columns (loc=3-6)

**Flow:**
1. Select model matrix: per-instance attribute (if `u_use_instancing == 1`) or `u_model` uniform
2. Optional GPU wave animation (if `u_vertex_anim == 1`): sinusoidal Y displacement based on world XZ + time
3. Transform position to world space
4. Optional heat shimmer (if `u_temperature > 30.0`): subtle vertex displacement with distance fade
5. Compute normal: for instanced draws, derive normal matrix from model; for non-instanced, use `u_normal_matrix` (with zero-check fallback)
6. Output: `gl_Position = projection * view * worldPos`, pass `v_normal`, `v_worldPos`, `v_uv`

### Fragment Shader (`mesh3d.frag.glsl`)

**Varyings received:** `v_normal`, `v_worldPos`, `v_uv`

**Noise functions:** `hash21`, `hash31`, `noise` (value noise), `fbm` (fractal Brownian motion)

**Material Processing by proc_mode:**

Modes with **early return** (skip PBR, handle own lighting):
- **2 (Water):** Wave normals from multi-layer fbm, Fresnel reflection, depth-based color, foam, caustics, sun specular. Custom alpha from depth.
- **6 (Cloud):** Self-lit, subsurface scattering, silver lining, edge transparency.
- **9 (Moon):** Procedural phase rendering with terminator, craters, earthshine. No fog.
- **10 (Rainbow):** UV.y spectral bands (ROY G BIV), emission-only, semi-transparent.
- **14 (Fire):** Animated upward flow noise, color gradient white-yellow-orange-red-black.
- **18 (Neon):** Intense self-illumination, pulsing, scanlines, edge glow.
- **24 (Particle/Smoke):** Soft-edge billboard with noise turbulence.
- **25 (Hologram):** Scanlines, chromatic shift, edge glow, flicker.

Modes that **set albedo + roughness** then continue to PBR:
- **0 (Standard):** Slight noise variation on albedo.
- **1 (Sand):** Multi-zone color (damp/mid/dry/dune via UV.x), multi-scale noise, wind ripples, subsurface scatter, sparkle glints. Normal perturbation.
- **3 (Rock):** FBM-based color, veins/cracks, strata layers, moss patches, lichen spots. Normal perturbation.
- **4 (Palm Trunk):** Bark rings, fiber texture, weathering. Normal perturbation.
- **5 (Palm Leaf):** Vein pattern, translucency, subsurface scatter.
- **7 (Wood Planks):** Plank spacing with gaps, per-plank color, grain, knot holes, nails, weathering.
- **8 (Thatch):** Straw strand layers, gaps, highlights, weathering.
- **11 (Glass):** Fresnel + refraction color shift + reflection blend. Custom alpha.
- **12 (Polished Metal):** Brushed grain, anisotropic highlight, tinted reflections.
- **13 (Fabric):** Weave pattern, fuzz sheen, fiber noise.
- **15 (Lava):** Animated crack pattern with glow gradient + pulsing.
- **16 (Ice):** Crystal structure, subsurface blue glow, frost patches. Custom alpha.
- **17 (Grass):** Blade pattern, tip-to-base color, translucency, seasonal tint.
- **19 (Concrete):** Stains, aggregate speckles, cracks, pores.
- **20 (Brick):** Brick grid with mortar, per-brick color, weathering.
- **21 (Tile):** Tile grid with grout, per-tile variation, glossy surface.
- **22 (Leather):** Wrinkle pattern, pore detail, wear marks, sheen.
- **23 (Skin):** Subsurface scattering (wrap diffuse + blood-red scatter), pore detail, oily sheen.

**Weather Surface Effects** (applied to physical surfaces, skipping water/cloud/moon/fire/neon/particle/hologram):
- **Rain/Dew wetness:** Darkens albedo, reduces roughness, creates puddles in low areas
- **Snow:** Accumulates on upward-facing surfaces with patchy noise coverage

**PBR Lighting:**
- Shininess = `exp2(10 * (1 - roughness) + 1)`
- F0 = `mix(0.04, albedo, metallic)` (Fresnel-Schlick)
- Shadow factor from `calcShadow()` applied to primary directional light only
- Ambient shadow scales with primary light intensity (strong in daylight, subtle at night)
- Ambient: `ambient_color * ambient_intensity * albedo * (1 - metallic*0.9) * ambientShadow`
- Directional lights: Half-Lambert diffuse (40% wrap blend) + Blinn-Phong specular with GGX-influenced normalization
- Point lights: Inverse-square attenuation clamped to radius, diffuse + specular
- Emission: added directly
- Fog: squared exponential based on distance (`1 - exp(-fogFactor^2 * 3)`)
- Gamma correction: `pow(color, 1/2.2)`

---

## 9. Key Differences from Vulkan Renderer

| Aspect | OpenGL | Vulkan |
|--------|--------|--------|
| **Uniform upload** | `glUniform*` calls per-draw (immediate mode) | UBO (Uniform Buffer Object) per-frame, descriptor sets |
| **Per-draw data** | Individual `setUniform*` calls for model/material | Push constants or per-draw UBO update |
| **Shader compilation** | GLSL compiled at runtime via `glCreateShader` | GLSL pre-compiled to SPIR-V via `glslangValidator`, loaded as `ShaderModule` |
| **Pipeline state** | Mutable GL state (`glEnable`, `glBlendFunc`) | Immutable `Pipeline` objects (separate opaque + transparent pipelines) |
| **Descriptor binding** | `glActiveTexture` + `glBindTexture` | Descriptor sets with layouts, per-frame descriptor sets for race-free multi-buffering |
| **Frames in flight** | Single-buffered (implicit sync) | Explicit double-buffering (`MAX_FRAMES_IN_FLIGHT = 2`) with fences + semaphores |
| **Depth buffers** | Single shared depth buffer | Per-swapchain-image depth buffers to prevent GPU race conditions |
| **Instancing** | Expanded (non-indexed) VAO + `glDrawArraysInstanced` | Vertex buffer + index buffer + instanced draw (indexed instancing supported natively) |
| **Shadow/Cloud renderers** | `ShadowMapRenderer` / `CloudShadowRenderer` (GL FBOs) | `VulkanShadowMapRenderer` / `VulkanCloudShadowRenderer` (Vulkan images + render passes) |
| **Post-processing** | `PostProcessPipeline` (GL FBO + fullscreen quad) | `VulkanPostProcessPipeline` (separate render pass + pipeline) |
| **Command submission** | Immediate GL calls in PHP | Recorded into `CommandBuffer`, submitted to `Queue` with sync primitives |
| **Memory management** | Driver-managed (GL handles) | Explicit: `DeviceMemory` allocation, `Buffer` binding, memory type selection |
| **Resource cleanup** | PHP GC calls GL delete functions | Must prevent PHP GC from destroying Vulkan handles while GPU is using them |
| **Render passes** | Implicit (FBO bind/unbind) | Explicit `RenderPass` objects with subpass dependencies |
