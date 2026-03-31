---
description: "PHPolygon Metal Renderer — php-metal API, CAMetalLayer bridge, MSL shader conventions, buffer index mapping. Use when modifying the Metal rendering backend."
---

# Metal 3D Renderer — Reference

## Architecture

Native Metal backend for PHPolygon on macOS. Replaces MoltenVK/Vulkan with direct Metal API via `php-metal` extension.

**Bridge:** GLFW window → `glfwGetCocoaWindow()` → `CAMetalLayer::createFromWindow()` → Metal rendering

## Key Files

| File | Purpose |
|------|---------|
| `vendor/phpolygon/.../Rendering/MetalRenderer3D.php` | Main renderer — translates RenderCommandList to Metal calls |
| `vendor/phpolygon/.../resources/shaders/source/mesh3d_metal.metal` | MSL vertex + fragment shader |
| `vendor/phpolygon/.../Engine.php` | Backend selection (`'metal'` preferred on macOS) |
| `~/PhpstormProjects/php-metal/metal.c` | CAMetalLayer + Drawable extension code |
| `~/PhpstormProjects/php-glfw/phpglfw_functions.c` | glfwGetCocoaWindow() function |

## php-metal API (Key Classes)

### Metal\CAMetalLayer
```php
$layer = Metal\CAMetalLayer::createFromWindow(int $nsWindowPtr, Metal\Device $device): self
$layer->nextDrawable(): Metal\Drawable
$layer->setDrawableSize(int $w, int $h): void
$layer->setPixelFormat(int $format): void
$layer->setMaximumDrawableCount(int $count): void
$layer->setContentsScale(float $scale): void
```

### Metal\Drawable
```php
$drawable->getTexture(): Metal\Texture  // backing texture for render pass
```

### Key Pattern (per frame)
```php
$drawable = $layer->nextDrawable();
$texture = $drawable->getTexture();
$passDesc = new Metal\RenderPassDescriptor();
$passDesc->setColorAttachmentTexture(0, $texture);
// ... encode draws ...
$cmdBuffer->presentDrawable($drawable);
$cmdBuffer->commit();
```

## Buffer Index Mapping (MSL ↔ PHP)

| Index | Stage | MSL Binding | PHP Method | Content |
|-------|-------|-------------|------------|---------|
| 0 | Vertex | `[[buffer(0)]]` | `setVertexBuffer($vb, 0, 0)` | Mesh vertex data (32 bytes/vert) |
| 2 | Vertex | `[[buffer(2)]]` | `setVertexBuffer($ubo, 0, 2)` | Frame UBO (192 bytes) |
| 3 | Vertex | `[[buffer(3)]]` | `setVertexBytes($data, 3)` | Model matrix (64 bytes) |
| 0 | Fragment | `[[buffer(0)]]` | `setFragmentBuffer($ubo, 0, 0)` | Lighting UBO (1040 bytes) |
| 1 | Fragment | `[[buffer(1)]]` | `setFragmentBytes($data, 1)` | Material constants (32 bytes) |

## MSL Shader Conventions

- Shaders stored as `.metal` source files, compiled at runtime via `createLibraryWithSource()`
- Function names: `mesh3d_vertex`, `mesh3d_fragment`, `shadow_vertex`, etc.
- Vertex input via `[[attribute(N)]]` struct + `VertexDescriptor`
- UBOs as `constant StructName& name [[buffer(N)]]`
- Per-draw data via `setVertexBytes`/`setFragmentBytes` (max 4KB, replaces Vulkan push constants)
- `sampler2DShadow` → `depth2d<float>` with separate `sampler` using `compare_func::less_equal`

## Vertex Format

32 bytes per vertex, interleaved:
```
Offset 0:  float3 position  (12 bytes)
Offset 12: float3 normal    (12 bytes)
Offset 24: float2 uv        (8 bytes)
```

## Simplifications vs Vulkan

- No swapchain management (CAMetalLayer handles drawable pool)
- No image layout transitions
- No descriptor sets (buffers/textures bound directly)
- No pipeline layout (push constants → setVertexBytes/setFragmentBytes)
- Resize = `setDrawableSize()` + recreate depth texture

## Frame Sync

- MAX_FRAMES_IN_FLIGHT = 2
- Wait on oldest command buffer via `waitUntilCompleted()` before reusing frame resources
- CAMetalLayer manages triple buffering internally (maximumDrawableCount = 3)

## Engine Integration

- Backend auto-detection: Metal preferred on macOS when `Metal\Device` + `glfwGetCocoaWindow` available
- Falls back to Vulkan → OpenGL if Metal unavailable
- Both Metal and Vulkan use `GLFW_NO_API` (no GL context)
- `NullRenderer2D` used for 2D overlay (no NanoVG without GL context)

## TODO (Not Yet Implemented)

- Shadow passes (MetalShadowMapRenderer)
- Cloud shadow passes (MetalCloudShadowRenderer)
- Post-processing pipeline (MetalPostProcessPipeline)
- GPU instancing (currently uses per-draw loop)
- All 25 procedural material modes (proc_mode) from OpenGL shader
- Wave animation in vertex shader
- Weather effects (rain, snow, temperature)
