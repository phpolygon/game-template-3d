---
description: "PHP Vulkan Extension API Reference — complete method signatures for all Vk\\ classes. Use when writing Vulkan rendering code in PHPolygon."
---

# php-vulkan Extension API Reference

Source: `/Users/hendrikmennen/PhpstormProjects/php-vulkan/src/`

## Vk\Instance

```php
new Instance(
    string $appName, int $appVersion,
    string $engineName, int $engineVersion,
    ?string $validationLayer = null,   // e.g. "VK_LAYER_KHRONOS_validation"
    bool $enableValidation = false,
    array $extensions = [],            // e.g. ['VK_KHR_surface', 'VK_KHR_portability_enumeration']
): Instance

$instance->getPhysicalDevices(): array<PhysicalDevice>
```

## Vk\PhysicalDevice

```php
$gpu->getProperties(): array    // ['deviceName'=>string, 'deviceType'=>int, 'apiVersion'=>int, ...]
$gpu->getMemoryProperties(): array  // ['types' => [['deviceLocal'=>bool, 'hostVisible'=>bool, 'hostCoherent'=>bool], ...]]
$gpu->getQueueFamilies(): array     // [['index'=>int, 'count'=>int, 'graphics'=>bool, 'compute'=>bool, 'transfer'=>bool], ...]
$gpu->getSurfaceSupport(int $queueFamilyIndex, Surface $surface): bool
```

## Vk\Surface

```php
new Surface(Instance $instance, \GLFWwindow $window): Surface

$surface->getCapabilities(PhysicalDevice $gpu): array
// ['minImageCount'=>int, 'maxImageCount'=>int, 'currentExtent'=>['width'=>int,'height'=>int],
//  'minImageExtent'=>[...], 'maxImageExtent'=>[...], 'currentTransform'=>int]

$surface->getFormats(PhysicalDevice $gpu): array
// [['format'=>int, 'colorSpace'=>int], ...]
```

## Vk\Device

```php
new Device(
    PhysicalDevice $gpu,
    array $queueCreateInfos,    // [['familyIndex'=>int, 'count'=>int], ...]
    array $extensions = [],     // ['VK_KHR_swapchain', 'VK_KHR_dynamic_rendering', ...]
    ?string $validationLayer = null,
): Device

$device->getQueue(int $familyIndex, int $queueIndex): Queue
$device->waitIdle(): void
```

## Vk\Queue

```php
$queue->submit(
    array $commandBuffers,           // [CommandBuffer, ...]
    ?Fence $fence = null,
    array $waitSemaphores = [],      // [Semaphore, ...]  — wait stage: ALL_COMMANDS_BIT (hardcoded)
    array $signalSemaphores = [],    // [Semaphore, ...]
): void

$queue->present(
    array $swapchains,       // [Swapchain]
    array $imageIndices,     // [int]
    array $waitSemaphores = [],
): int  // VkResult

$queue->waitIdle(): void
```

## Vk\Swapchain

```php
new Swapchain(Device $device, Surface $surface, array $config): Swapchain
// config keys: minImageCount, imageFormat, imageColorSpace, imageExtent(['width','height']),
//   imageArrayLayers, imageUsage, imageSharingMode, preTransform, compositeAlpha,
//   presentMode, clipped

$swapchain->getImages(): array<Image>
$swapchain->acquireNextImage(
    Semaphore $semaphore,
    ?Fence $fence = null,
    int $timeout = -1,      // -1 = UINT64_MAX
): int  // image index
```

## Vk\CommandPool

```php
new CommandPool(Device $device, int $queueFamilyIndex, int $flags = 0): CommandPool
// flags: 2 = VK_COMMAND_POOL_CREATE_RESET_COMMAND_BUFFER_BIT

$pool->allocateBuffers(int $count, bool $primary = true): array<CommandBuffer>
```

## Vk\CommandBuffer

### Lifecycle
```php
$cmd->begin(int $flags = 0): void          // 1 = VK_COMMAND_BUFFER_USAGE_ONE_TIME_SUBMIT_BIT
$cmd->end(): void
$cmd->reset(int $flags = 0): void
```

### Render Pass (traditional)
```php
$cmd->beginRenderPass(
    RenderPass $renderPass,
    Framebuffer $framebuffer,
    int $x, int $y, int $width, int $height,
    array $clearValues = [],    // [[r,g,b,a], [depth,stencil], ...]  — 4 elem = color, 2 elem = depth
): void
$cmd->endRenderPass(): void
```

### Dynamic Rendering (VK_KHR_dynamic_rendering)
```php
$cmd->beginRendering(
    int $width, int $height,
    array $colorAttachments,     // [['imageView'=>ImageView, 'imageLayout'=>int, 'loadOp'=>int, 'storeOp'=>int, 'clearValue'=>[r,g,b,a]], ...]
    ?array $depthAttachment = null,   // ['imageView'=>ImageView, 'imageLayout'=>int, 'loadOp'=>int, 'storeOp'=>int, 'clearValue'=>[depth,stencil]]
    ?array $stencilAttachment = null,
    int $layerCount = 1,
    int $viewMask = 0,
    int $flags = 0,
): void
$cmd->endRendering(): void
```

### Pipeline & Descriptors
```php
$cmd->bindPipeline(int $bindPoint, Pipeline $pipeline): void    // bindPoint: 0=GRAPHICS, 1=COMPUTE
$cmd->bindDescriptorSets(int $bindPoint, PipelineLayout $layout, int $firstSet, array $sets): void
$cmd->pushConstants(PipelineLayout $layout, int $stageFlags, int $offset, string $data): void
```

### Vertex/Index Buffers
```php
$cmd->bindVertexBuffers(int $firstBinding, array $buffers, array $offsets = []): void
$cmd->bindIndexBuffer(Buffer $buffer, int $offset, int $indexType): void
// indexType: 0=UINT16, 1=UINT32
```

### Draw
```php
$cmd->draw(int $vertexCount, int $instanceCount = 1, int $firstVertex = 0, int $firstInstance = 0): void
$cmd->drawIndexed(int $indexCount, int $instanceCount = 1, int $firstIndex = 0, int $vertexOffset = 0, int $firstInstance = 0): void
```

### Viewport & Scissor (dynamic state)
```php
$cmd->setViewport(float $x, float $y, float $width, float $height, float $minDepth = 0.0, float $maxDepth = 1.0): void
$cmd->setScissor(int $x, int $y, int $width, int $height): void
```

### Image Operations
```php
$cmd->clearColorImage(Image $image, int $layout, float $r = 0, float $g = 0, float $b = 0, float $a = 1): void
$cmd->clearDepthStencilImage(Image $image, int $layout, float $depth = 1.0, int $stencil = 0): void
$cmd->copyImage(Image $src, int $srcLayout, Image $dst, int $dstLayout, int $width, int $height,
    int $srcX = 0, int $srcY = 0, int $dstX = 0, int $dstY = 0): void
$cmd->blitImage(Image $src, int $srcLayout, Image $dst, int $dstLayout,
    int $srcX0, int $srcY0, int $srcX1, int $srcY1,
    int $dstX0, int $dstY0, int $dstX1, int $dstY1,
    int $filter = 1): void  // 0=NEAREST, 1=LINEAR
$cmd->copyImageToBuffer(Image $image, int $imageLayout, Buffer $buffer,
    int $width, int $height, int $offsetX = 0, int $offsetY = 0): void
```

### Barriers
```php
$cmd->imageMemoryBarrier(
    Image $image,
    int $oldLayout, int $newLayout,
    int $srcAccessMask, int $dstAccessMask,
    int $srcStage, int $dstStage,
    int $aspectMask = 1,     // 1=COLOR, 2=DEPTH
): void
// Internally: VK_QUEUE_FAMILY_IGNORED for both queue families, subresourceRange covers mip 0, layer 0, count 1.

$cmd->pipelineBarrier(int $srcStage, int $dstStage,
    array $memoryBarriers = [], array $bufferBarriers = [], array $imageBarriers = []): void
```

### Compute
```php
$cmd->dispatch(int $groupCountX, int $groupCountY = 1, int $groupCountZ = 1): void
```

## Vk\RenderPass

```php
new RenderPass(
    Device $device,
    array $attachments,      // [['format'=>int, 'samples'=>int, 'loadOp'=>int, 'storeOp'=>int,
                             //   'stencilLoadOp'=>int, 'stencilStoreOp'=>int, 'initialLayout'=>int, 'finalLayout'=>int], ...]
    array $subpasses,        // [['pipelineBindPoint'=>int, 'colorAttachments'=>[['attachment'=>int,'layout'=>int],...],
                             //   'depthAttachment'=>['attachment'=>int,'layout'=>int]], ...]
    array $dependencies = [],// [['srcSubpass'=>int, 'dstSubpass'=>int, 'srcStageMask'=>int, 'dstStageMask'=>int,
                             //   'srcAccessMask'=>int, 'dstAccessMask'=>int], ...]
): RenderPass
```
**Defaults:** format=B8G8R8A8_SRGB, samples=1, loadOp=CLEAR, storeOp=STORE, stencil=DONT_CARE, initial=UNDEFINED, final=PRESENT_SRC.
**Dependency defaults:** srcSubpass=EXTERNAL, dstSubpass=0, stages=COLOR_OUTPUT, dstAccess=COLOR_WRITE.

## Vk\Framebuffer

```php
new Framebuffer(
    Device $device, RenderPass $renderPass,
    array $attachments,    // [ImageView, ...]
    int $width, int $height, int $layers = 1,
): Framebuffer
```

## Vk\Pipeline

```php
Pipeline::createGraphics(Device $device, array $config): Pipeline
```
**Required config keys:** `layout` (PipelineLayout), `vertexShader` (ShaderModule), `fragmentShader` (ShaderModule).
**Render pass OR dynamic rendering:** `renderPass` (RenderPass) OR `colorFormats` (array<int>) + `depthFormat` (int).
**Optional:** `vertexBindings`, `vertexAttributes`, `topology` (default TRIANGLE_LIST), `cullMode`, `frontFace`, `depthTest`, `depthWrite`, `blendEnable`, `srcColorBlendFactor`, `dstColorBlendFactor`, `colorBlendOp`, `srcAlphaBlendFactor`, `dstAlphaBlendFactor`, `alphaBlendOp`, `vertexEntryPoint`, `fragmentEntryPoint`, `dynamicStates`, `basePipeline`, `allowDerivatives`, `cache`.

**Dynamic states:** Always VK_DYNAMIC_STATE_VIEWPORT + VK_DYNAMIC_STATE_SCISSOR (hardcoded).

```php
Pipeline::createCompute(Device $device, ShaderModule $shader, PipelineLayout $layout,
    string $entryPoint = "main", ?PipelineCache $cache = null): Pipeline
```

## Vk\PipelineLayout

```php
new PipelineLayout(
    Device $device,
    array $setLayouts = [],      // [DescriptorSetLayout, ...]
    array $pushConstantRanges = [], // [['stageFlags'=>int, 'offset'=>int, 'size'=>int], ...]
): PipelineLayout
```

## Vk\ShaderModule

```php
ShaderModule::createFromFile(Device $device, string $path): ShaderModule   // loads SPIR-V binary
ShaderModule::createFromCode(Device $device, string $spirvBytes): ShaderModule
```

## Vk\Buffer

```php
new Buffer(Device $device, int $size, int $usage, int $sharingMode): Buffer
$buffer->getMemoryRequirements(): array  // ['size'=>int, 'alignment'=>int, 'memoryTypeBits'=>int]
$buffer->bindMemory(DeviceMemory $memory, int $offset): void
```

## Vk\DeviceMemory

```php
new DeviceMemory(Device $device, int $size, int $memoryTypeIndex): DeviceMemory
$memory->map(int $offset, ?int $size): void      // size=null → VK_WHOLE_SIZE
$memory->unmap(): void
$memory->write(string $data, int $offset): void   // writes to mapped pointer
```

## Vk\Image

```php
new Image(Device $device, int $width, int $height, int $format, int $usage,
    int $tiling = 0, int $samples = 1): Image
// tiling: 0=OPTIMAL, 1=LINEAR
$image->getMemoryRequirements(): array
$image->bindMemory(DeviceMemory $memory, int $offset): void
```
**Note:** Swapchain images have `owns_image=0` — they are NOT destroyed when the PHP object is freed.

## Vk\ImageView

```php
new ImageView(Device $device, Image $image, int $format, int $aspectMask, int $layerCount = 1): ImageView
// aspectMask: 1=COLOR, 2=DEPTH
```

## Vk\DescriptorSetLayout

```php
new DescriptorSetLayout(Device $device, array $bindings): DescriptorSetLayout
// bindings: [['binding'=>int, 'descriptorType'=>int, 'stageFlags'=>int, 'count'=>1], ...]
// descriptorType: 6=UNIFORM_BUFFER, 1=COMBINED_IMAGE_SAMPLER, 7=STORAGE_BUFFER
```

## Vk\DescriptorPool

```php
new DescriptorPool(Device $device, int $maxSets, array $poolSizes): DescriptorPool
// poolSizes: [['type'=>int, 'count'=>int], ...]
$pool->allocateSets(array $layouts): array<DescriptorSet>   // layouts: [DescriptorSetLayout, ...]
```

## Vk\DescriptorSet

```php
$set->writeBuffer(int $binding, Buffer $buffer, int $offset, int $range, int $descriptorType): void
$set->writeImage(int $binding, Sampler $sampler, ImageView $view, int $imageLayout, int $descriptorType = 1): void
```

## Vk\Sampler

```php
new Sampler(Device $device, array $config): Sampler
// config keys: magFilter, minFilter, addressModeU, addressModeV, addressModeW,
//   borderColor, compareEnable, compareOp, mipmapMode, minLod, maxLod, maxAnisotropy
```

## Vk\Fence

```php
new Fence(Device $device, bool $signaled = false): Fence
$fence->wait(int $timeout = -1): bool     // -1 = UINT64_MAX, returns true on success
$fence->reset(): void
```

## Vk\Semaphore

```php
new Semaphore(Device $device, bool $timeline = false, int $initialValue = 0): Semaphore
```

## Vk\PipelineCache

```php
new PipelineCache(Device $device, ?string $initialData = null): PipelineCache
$cache->getData(): string
```

---

## Key VK Constants

| Name | Value | Description |
|---|---|---|
| VK_FORMAT_B8G8R8A8_UNORM | 44 | Common swapchain format |
| VK_FORMAT_B8G8R8A8_SRGB | 50 | sRGB swapchain format |
| VK_FORMAT_D32_SFLOAT | 126 | 32-bit depth |
| VK_FORMAT_R32G32B32_SFLOAT | 106 | vec3 vertex attr |
| VK_FORMAT_R32G32B32A32_SFLOAT | 109 | vec4 vertex attr |
| VK_FORMAT_R32G32_SFLOAT | 103 | vec2 vertex attr |
| VK_IMAGE_LAYOUT_UNDEFINED | 0 | |
| VK_IMAGE_LAYOUT_PRESENT_SRC | 1000001002 | |
| VK_IMAGE_LAYOUT_COLOR_ATTACHMENT | 2 | |
| VK_IMAGE_LAYOUT_DEPTH_ATTACHMENT | 3 | |
| VK_IMAGE_LAYOUT_SHADER_READ | 5 | |
| VK_IMAGE_LAYOUT_TRANSFER_SRC | 6 | |
| VK_IMAGE_LAYOUT_TRANSFER_DST | 7 | |
| VK_ATTACHMENT_LOAD_OP_LOAD | 0 | |
| VK_ATTACHMENT_LOAD_OP_CLEAR | 1 | |
| VK_ATTACHMENT_LOAD_OP_DONT_CARE | 2 | |
| VK_ATTACHMENT_STORE_OP_STORE | 0 | |
| VK_ATTACHMENT_STORE_OP_DONT_CARE | 1 | |
| VK_IMAGE_USAGE_TRANSFER_SRC | 1 | |
| VK_IMAGE_USAGE_TRANSFER_DST | 2 | |
| VK_IMAGE_USAGE_SAMPLED | 4 | |
| VK_IMAGE_USAGE_COLOR_ATTACHMENT | 16 | |
| VK_IMAGE_USAGE_DEPTH_STENCIL_ATTACHMENT | 32 | |
| VK_PIPELINE_STAGE_TOP_OF_PIPE | 1 | |
| VK_PIPELINE_STAGE_VERTEX_SHADER | 8 | |
| VK_PIPELINE_STAGE_FRAGMENT_SHADER | 128 | |
| VK_PIPELINE_STAGE_EARLY_FRAGMENT_TESTS | 256 | |
| VK_PIPELINE_STAGE_COLOR_ATTACHMENT_OUTPUT | 1024 | |
| VK_PIPELINE_STAGE_TRANSFER | 4096 | |
| VK_PIPELINE_STAGE_ALL_COMMANDS | 0x10000 | |
| VK_ACCESS_TRANSFER_READ | 0x800 | |
| VK_ACCESS_TRANSFER_WRITE | 0x400 | |
| VK_ACCESS_COLOR_ATTACHMENT_WRITE | 0x100 | |
| VK_ACCESS_DEPTH_STENCIL_WRITE | 0x400 | |
| VK_ACCESS_SHADER_READ | 0x20 | |
| VK_SHADER_STAGE_VERTEX | 1 | |
| VK_SHADER_STAGE_FRAGMENT | 16 | |
| VK_SUBPASS_EXTERNAL | 0xFFFFFFFF | |
| VK_FILTER_NEAREST | 0 | |
| VK_FILTER_LINEAR | 1 | |

---

## MoltenVK Gotchas (macOS)

1. **Max 2 swapchain images** — triple buffering causes flickering
2. **Never use loadOp=CLEAR** — use explicit clearColorImage/clearDepthStencilImage + DONT_CARE
3. **Don't drawIndexed to swapchain images** — render to offscreen, copyImage to swapchain
4. **Track image layouts explicitly** — don't use UNDEFINED as oldLayout after first use
5. **Use VK_KHR_dynamic_rendering** — traditional VkRenderPass/VkFramebuffer has layout bugs
6. **PHP GC destroys Vulkan handles** — store all Vk objects as class properties to prevent collection
7. **Surface capabilities may return empty** — don't rely on currentExtent/min/max
8. **queue->submit wait stage** — hardcoded to ALL_COMMANDS_BIT (safe but suboptimal)
