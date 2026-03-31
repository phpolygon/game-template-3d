<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\AddPointLight;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetCamera;
use PHPolygon\Rendering\Command\SetDirectionalLight;
use PHPolygon\Rendering\Command\SetEnvironmentMap;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\Command\SetSkybox;
use PHPolygon\Rendering\Command\SetSkyColors;
use PHPolygon\Rendering\Command\SetWaveAnimation;
use PHPolygon\Rendering\Command\SetWeatherUniforms;
use Vk\Buffer;
use Vk\CommandPool;
use Vk\DescriptorPool;
use Vk\DescriptorSet;
use Vk\DescriptorSetLayout;
use Vk\Device;
use Vk\DeviceMemory;
use Vk\Fence;
use Vk\Framebuffer;
use Vk\Image;
use Vk\ImageView;
use Vk\Instance;
use Vk\PhysicalDevice;
use Vk\Pipeline;
use Vk\PipelineLayout;
use Vk\Queue;
use Vk\RenderPass;
use Vk\Semaphore;
use Vk\ShaderModule;
use Vk\Surface;
use Vk\Swapchain;
use Vk\Sampler;

/**
 * Vulkan 1.0 3D renderer with full feature parity to OpenGL backend.
 * Translates RenderCommandList into Vulkan draw calls via MoltenVK on macOS.
 *
 * Render flow:
 *   1. Shadow depth pass (offscreen 2048²)
 *   2. Cloud shadow pass (offscreen 1024²)
 *   3. Scene pass → HDR FBO (opaque then transparent)
 *   4. Present pass → swapchain (post-process fullscreen triangle)
 */
class VulkanRenderer3D implements Renderer3DInterface
{
    private int $width;
    private int $height;

    private Instance $instance;
    private PhysicalDevice $gpu;
    private Surface $surface;
    private Device $device;
    private Queue $queue;
    private int $graphicsFamily;
    private Swapchain $swapchain;
    private int $surfaceFormat;
    private RenderPass $renderPass; // Swapchain present pass
    private Pipeline $opaquePipeline;
    private Pipeline $transparentPipeline;
    private PipelineLayout $pipelineLayout;
    private DescriptorSetLayout $descriptorSetLayout;
    private DescriptorPool $descriptorPool;
    private DescriptorSet $descriptorSet;
    private CommandPool $commandPool;
    private const MAX_FRAMES_IN_FLIGHT = 2;
    /** @var \Vk\CommandBuffer[] */
    private array $commandBuffers = [];
    /** @var Fence[] */
    private array $inFlightFences = [];
    /** @var Semaphore[] */
    private array $imageAvailableSems = [];
    /** @var Semaphore[] */
    private array $renderFinishedSems = [];
    private int $currentFrame = 0;

    // Shadow renderers
    private ?VulkanShadowMapRenderer $shadowMap = null;
    private ?VulkanCloudShadowRenderer $cloudShadow = null;

    // Post-processing
    private ?VulkanPostProcessPipeline $postProcess = null;

    /** @var Image[] */
    private array $swapImages = [];
    /** @var ImageView[] */
    private array $swapImageViews = [];
    /** @var Framebuffer[] */
    private array $framebuffers = [];
    // Per-swapchain-image depth buffers — prevents race when frames overlap on GPU
    /** @var Image[] */
    private array $swapDepthImages = [];
    /** @var DeviceMemory[] */
    private array $swapDepthMems = [];
    /** @var ImageView[] */
    private array $swapDepthViews = [];
    /** @var array<array<mixed>> */
    private array $memTypes = [];
    // Debug color-only render pass (no depth)
    private ?RenderPass $debugColorOnlyRenderPass = null;
    /** @var Framebuffer[] */
    private array $debugColorOnlyFramebuffers = [];

    // Per-frame UBOs (one per swapchain image to avoid GPU read/write race)
    /** @var Buffer[] */
    private array $frameUbos = [];
    /** @var DeviceMemory[] */
    private array $frameUboMems = [];
    /** @var Buffer[] */
    private array $lightingUbos = [];
    /** @var DeviceMemory[] */
    private array $lightingUboMems = [];
    /** @var DescriptorSet[] One descriptor set per swapchain image */
    private array $descriptorSets = [];

    // Dummy images for descriptor bindings when shadow/cloud/env not active
    private Image $dummyShadowImage;
    private DeviceMemory $dummyShadowMem;
    private ImageView $dummyShadowView;
    private Sampler $dummyShadowSampler;
    private Image $dummyCloudImage;
    private DeviceMemory $dummyCloudMem;
    private ImageView $dummyCloudView;
    private Sampler $dummyCloudSampler;
    // Note: env cubemap dummy omitted — php-vulkan Image lacks arrayLayers for cubemaps

    private int $currentImageIndex = 0;
    private float $clearR = 0.0;
    private float $clearG = 0.0;
    private float $clearB = 0.0;

    // Frame state — accumulated from commands
    /** @var float[] */
    private array $viewMatrix = [];
    /** @var float[] */
    private array $projMatrix = [];
    /** @var float[] */
    private array $cameraPos = [0.0, 0.0, 0.0];
    /** @var float[] [r, g, b, intensity] */
    private array $ambient = [1.0, 1.0, 1.0, 0.1];
    /** @var array<int, array{dir: float[], color: float[], intensity: float}> */
    private array $dirLights = [];
    /** @var array<int, array{pos: float[], color: float[], intensity: float, radius: float}> */
    private array $pointLights = [];
    /** @var float[] [r, g, b, near, far] */
    private array $fog = [0.5, 0.5, 0.5, 50.0, 200.0];
    /** @var float[] [r, g, b] */
    private array $skyColor = [0.3, 0.5, 0.8];
    /** @var float[] [r, g, b] */
    private array $horizonColor = [0.7, 0.8, 0.9];
    /** @var float[] [r, g, b] */
    private array $seasonTint = [1.0, 1.0, 1.0];

    // Material state
    /** @var float[] */
    private array $albedo = [0.8, 0.8, 0.8];
    /** @var float[] */
    private array $emission = [0.0, 0.0, 0.0];
    private float $roughness = 0.5;
    private float $metallic = 0.0;
    private float $alpha = 1.0;
    private int $procMode = 0;
    private float $moonPhase = 0.5;

    // Weather state
    private float $rainIntensity = 0.0;
    private float $snowCoverage = 0.0;
    private float $temperature = 20.0;
    private float $dewWetness = 0.0;
    private float $stormIntensity = 0.0;

    // Shadow
    private int $hasShadowMap = 0;
    private int $hasCloudShadow = 0;
    private int $hasEnvMap = 0;
    /** @var float[] */
    private array $lightSpaceMatrix = [];

    // Wave animation
    private float $waveAmplitude = 0.0;
    private float $waveFrequency = 0.0;
    private float $wavePhase = 0.0;
    private int $vertexAnim = 0;

    private float $time = 0.0;

    // Mesh GPU buffer cache
    /** @var array<string, array{vb: Buffer, vbMem: DeviceMemory, ib: Buffer, ibMem: DeviceMemory, count: int}> */
    private array $meshCache = [];

    // Offscreen render target — drawIndexed to swapchain images is unreliable on MoltenVK
    private ?Image $offscreenImage = null;
    private ?DeviceMemory $offscreenMem = null;
    private ?ImageView $offscreenView = null;

    // Instance buffer for GPU instancing (reused per frame)
    private ?Buffer $instanceBuffer = null;
    private ?DeviceMemory $instanceBufferMem = null;
    private int $instanceBufferCapacity = 0;
    /** @var array<Buffer|DeviceMemory> Keep old buffers alive until frame submit completes */
    private array $retiredBuffers = [];

    // Proc mode cache (same as OpenGL)
    /** @var array<string, int> */
    private static array $procModeCache = [];

    private const VERT_SPV = __DIR__ . '/../../resources/shaders/compiled/mesh3d_vk.vert.spv';
    private const FRAG_SPV = __DIR__ . '/../../resources/shaders/compiled/mesh3d_vk.frag.spv';

    // Frame UBO: 2× mat4 + time + temp + 2 ints + 3 floats + pad + vec3 + pad = 192 bytes
    private const FRAME_UBO_SIZE = 192;

    // Lighting UBO: ambient(16) + material(48) + fog(32) + sky(48) + weather(32) + shadow_mat(64) + dir_lights(528) + point_lights(272) = 1040
    private const LIGHTING_UBO_SIZE = 1040;

    // Vk constants
    private const VK_CMD_ONE_TIME_SUBMIT       = 1;
    private const VK_PIPELINE_BIND_GRAPHICS    = 0;
    private const VK_SHADER_STAGE_VERTEX       = 1;
    private const VK_SHADER_STAGE_FRAGMENT     = 16;
    private const VK_INDEX_TYPE_UINT32         = 1;
    private const VK_IMAGE_USAGE_COLOR         = 16;
    private const VK_IMAGE_USAGE_DEPTH         = 32;
    private const VK_SHARING_EXCLUSIVE         = 0;
    private const VK_SAMPLE_COUNT_1            = 1;
    private const VK_LOAD_OP_CLEAR             = 1;
    private const VK_LOAD_OP_DONT_CARE         = 2;
    private const VK_STORE_OP_STORE            = 0;
    private const VK_STORE_OP_DONT_CARE        = 1;
    private const VK_LAYOUT_UNDEFINED          = 0;
    private const VK_LAYOUT_PRESENT_SRC        = 1000001002;
    private const VK_LAYOUT_COLOR_ATTACHMENT   = 2;
    private const VK_LAYOUT_DEPTH_ATTACHMENT   = 3;
    private const VK_LAYOUT_SHADER_READ        = 5;
    private const VK_LAYOUT_DEPTH_READ_ONLY    = 1000117000;
    private const VK_ASPECT_COLOR              = 1;
    private const VK_ASPECT_DEPTH              = 2;
    private const VK_FORMAT_D32_SFLOAT         = 126;
    private const VK_FORMAT_R8_UNORM           = 9;
    private const VK_FORMAT_R8G8B8A8_UNORM     = 37;
    private const VK_FORMAT_R32G32B32_SFLOAT   = 106;
    private const VK_FORMAT_R32G32B32A32_SFLOAT = 109;
    private const VK_FORMAT_R32G32_SFLOAT      = 103;
    private const VK_BUFFER_USAGE_VERTEX       = 128;
    private const VK_BUFFER_USAGE_INDEX        = 64;
    private const VK_BUFFER_USAGE_UNIFORM      = 16;
    private const VK_DESCRIPTOR_UNIFORM_BUFFER = 6;
    private const VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER = 1;
    private const VK_IMAGE_USAGE_SAMPLED       = 4;
    private const VK_VERTEX_INPUT_RATE_VERTEX  = 0;
    private const VK_VERTEX_INPUT_RATE_INSTANCE = 1;
    private const VK_CULL_MODE_BACK            = 2;
    private const VK_FRONT_FACE_CCW            = 0;
    private const VK_CMD_POOL_RESET_CMD_BUFFER = 2;
    private const VK_PRESENT_MODE_FIFO         = 2;
    private const VK_BLEND_FACTOR_SRC_ALPHA         = 6;
    private const VK_BLEND_FACTOR_ONE_MINUS_SRC_ALPHA = 7;
    private const VK_BLEND_OP_ADD              = 0;
    private const VK_FILTER_LINEAR             = 1;
    private const VK_FILTER_NEAREST            = 0;
    private const VK_SAMPLER_ADDRESS_CLAMP     = 2;
    private const VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER = 3;
    private const VK_BORDER_COLOR_FLOAT_OPAQUE_WHITE = 1;
    private const VK_BORDER_COLOR_FLOAT_TRANSPARENT_BLACK = 0;
    private const VK_COMPARE_OP_LESS_OR_EQUAL  = 3;
    private const VK_PIPELINE_STAGE_TOP        = 1;
    private const VK_PIPELINE_STAGE_EARLY_FRAG = 0x00000100;
    private const VK_PIPELINE_STAGE_COLOR_OUTPUT = 0x00000400;
    private const VK_PIPELINE_STAGE_FRAGMENT   = 128;
    private const VK_ACCESS_COLOR_WRITE        = 0x00000100;
    private const VK_ACCESS_DEPTH_WRITE        = 0x00000400;
    private const VK_ACCESS_TRANSFER_WRITE     = 0x00000400;
    private const VK_SUBPASS_EXTERNAL          = 0xFFFFFFFF;
    private const VK_LAYOUT_TRANSFER_SRC       = 6;
    private const VK_LAYOUT_TRANSFER_DST       = 7;
    private const VK_PIPELINE_STAGE_TRANSFER   = 0x00001000;
    private const VK_ACCESS_TRANSFER_READ      = 0x00000800;

    private \GLFWwindow $windowHandle;

    public function __construct(int $width, int $height, \GLFWwindow $windowHandle)
    {
        $this->width = $width;
        $this->height = $height;
        $this->windowHandle = $windowHandle;
        $this->lightSpaceMatrix = Mat4::identity()->toArray();
        $this->initVulkan($windowHandle);
    }

    public function __destruct()
    {
        // Ensure GPU is idle before PHP GC destroys Vulkan objects in arbitrary order
        try {
            $this->device->waitIdle();
        } catch (\Throwable) {
            // Device may already be destroyed
        }
    }

    private int $debugFrameNum = 0;

    public function beginFrame(): void
    {
        $this->debugFrameNum++;
        $this->dirLights = [];
        $this->pointLights = [];
        $this->time += 1.0 / 60.0;

        $f = $this->currentFrame;

        if ($this->debugFrameNum <= 10 || $this->debugFrameNum % 120 === 0) {
            fprintf(STDERR, "[VK:beginFrame] frame=%d cf=%d waiting fence[%d]...\n", $this->debugFrameNum, $f, $f);
        }

        $this->inFlightFences[$f]->wait(1_000_000_000);
        $this->inFlightFences[$f]->reset();

        $this->retiredBuffers = [];

        $this->currentImageIndex = $this->swapchain->acquireNextImage(
            $this->imageAvailableSems[$f], null, 1_000_000_000,
        );

        if ($this->debugFrameNum <= 10 || $this->debugFrameNum % 120 === 0) {
            fprintf(STDERR, "[VK:beginFrame] frame=%d cf=%d imgIdx=%d swapImages=%d\n",
                $this->debugFrameNum, $f, $this->currentImageIndex, count($this->swapImages));
        }

        $this->commandBuffers[$f]->reset(0);
        $this->commandBuffers[$f]->begin(self::VK_CMD_ONE_TIME_SUBMIT);
    }

    public function endFrame(): void
    {
        $f = $this->currentFrame;

        $this->commandBuffers[$f]->end();

        if ($this->debugFrameNum <= 10 || $this->debugFrameNum % 120 === 0) {
            fprintf(STDERR, "[VK:endFrame] frame=%d cf=%d imgIdx=%d submitting...\n",
                $this->debugFrameNum, $f, $this->currentImageIndex);
        }

        $this->queue->submit(
            [$this->commandBuffers[$f]],
            $this->inFlightFences[$f],
            [$this->imageAvailableSems[$f]],
            [$this->renderFinishedSems[$f]],
        );

        $this->queue->present(
            [$this->swapchain],
            [$this->currentImageIndex],
            [$this->renderFinishedSems[$f]],
        );

        $this->currentFrame = ($f + 1) % self::MAX_FRAMES_IN_FLIGHT;
    }

    public function clear(Color $color): void
    {
        $this->clearR = $color->r;
        $this->clearG = $color->g;
        $this->clearB = $color->b;
    }

    public function setViewport(int $x, int $y, int $width, int $height): void
    {
        if ($width !== $this->width || $height !== $this->height) {
            $this->width = $width;
            $this->height = $height;
            $this->recreateSwapchain();
        }
    }

    private function recreateSwapchain(): void
    {
        $this->device->waitIdle();

        $this->framebuffers = [];
        $this->swapImageViews = [];
        $this->swapDepthImages = [];
        $this->swapDepthMems = [];
        $this->swapDepthViews = [];

        $this->createSwapchain();
        $this->createRenderPass();

        // Recreate offscreen target for new dimensions
        $this->createOffscreenTarget();

        // Recreate post-process pipeline for new dimensions
        if ($this->postProcess !== null) {
            $findHostMem = fn(array $req) => $this->findMemory($req, true);
            $findDeviceMem = fn(array $req) => $this->findMemory($req, false);

            $this->postProcess = new VulkanPostProcessPipeline($this->device, $this->width, $this->height);
            $this->postProcess->initialize($findHostMem, $findDeviceMem, $this->renderPass);

            // Recreate scene pipelines for new post-process render pass
            $this->createScenePipelines();
        }
    }

    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }

    // =========================================================================
    // Render — full 5-pass flow
    // =========================================================================

    public function render(RenderCommandList $commandList): void
    {
        // Reset per-frame state
        $identity = Mat4::identity()->toArray();
        $this->viewMatrix = $identity;
        $this->projMatrix = $identity;
        $this->ambient = [1.0, 1.0, 1.0, 0.1];
        $this->fog = [0.5, 0.5, 0.5, 50.0, 200.0];
        $this->cameraPos = [0.0, 0.0, 0.0];
        $this->albedo = [0.8, 0.8, 0.8];
        $this->emission = [0.0, 0.0, 0.0];
        $this->roughness = 0.5;
        $this->metallic = 0.0;
        $this->alpha = 1.0;
        $this->procMode = 0;
        $this->moonPhase = 0.5;
        $this->seasonTint = [1.0, 1.0, 1.0];
        $this->rainIntensity = 0.0;
        $this->snowCoverage = 0.0;
        $this->temperature = 20.0;
        $this->dewWetness = 0.0;
        $this->stormIntensity = 0.0;
        $this->vertexAnim = 0;
        $this->hasShadowMap = 0;
        $this->hasCloudShadow = 0;
        $this->hasEnvMap = 0;

        // Pass 1: Collect state from non-draw commands
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetCamera) {
                $this->viewMatrix = $command->viewMatrix->toArray();
                // Z-remap only: OpenGL NDC depth [-1,1] → Vulkan [0,1]
                // Y-flip is handled by blitImage when copying offscreen → swapchain
                $zRemap = new Mat4([
                    1.0, 0.0, 0.0, 0.0,
                    0.0, 1.0, 0.0, 0.0,
                    0.0, 0.0, 0.5, 0.0,
                    0.0, 0.0, 0.5, 1.0,
                ]);
                $this->projMatrix = $zRemap->multiply($command->projectionMatrix)->toArray();
                if ($this->debugFrameNum === 1) {
                    $projAspect = abs($this->projMatrix[5]) / $this->projMatrix[0];
                    $vpAspect = (float)$this->width / (float)$this->height;
                    fprintf(STDERR, "[VK:aspect] renderer=%dx%d vpAspect=%.4f projAspect=%.4f MATCH=%s\n",
                        $this->width, $this->height, $vpAspect, $projAspect,
                        abs($vpAspect - $projAspect) < 0.01 ? 'YES' : '*** NO ***');
                    fprintf(STDERR, "[VK:aspect] swapImages=%d offscreen=%s\n",
                        count($this->swapImages), $this->offscreenImage !== null ? 'yes' : 'no');
                }
                $camPos = $command->viewMatrix->inverse()->getTranslation();
                $this->cameraPos = [$camPos->x, $camPos->y, $camPos->z];
            } elseif ($command instanceof SetAmbientLight) {
                $this->ambient = [$command->color->r, $command->color->g, $command->color->b, $command->intensity];
            } elseif ($command instanceof SetDirectionalLight) {
                if (count($this->dirLights) < 16) {
                    $this->dirLights[] = [
                        'dir' => [$command->direction->x, $command->direction->y, $command->direction->z],
                        'color' => [$command->color->r, $command->color->g, $command->color->b],
                        'intensity' => $command->intensity,
                    ];
                }
            } elseif ($command instanceof AddPointLight) {
                if (count($this->pointLights) < 8) {
                    $this->pointLights[] = [
                        'pos' => [$command->position->x, $command->position->y, $command->position->z],
                        'color' => [$command->color->r, $command->color->g, $command->color->b],
                        'intensity' => $command->intensity,
                        'radius' => $command->radius,
                    ];
                }
            } elseif ($command instanceof SetFog) {
                $this->fog = [$command->color->r, $command->color->g, $command->color->b, $command->near, $command->far];
            } elseif ($command instanceof SetSkyColors) {
                $this->skyColor = [$command->skyColor->r, $command->skyColor->g, $command->skyColor->b];
                $this->horizonColor = [$command->horizonColor->r, $command->horizonColor->g, $command->horizonColor->b];
            } elseif ($command instanceof SetWeatherUniforms) {
                $this->rainIntensity = $command->rainIntensity;
                $this->snowCoverage = $command->snowCoverage;
                $this->temperature = $command->temperature;
                $this->dewWetness = $command->dewWetness;
                $this->stormIntensity = $command->stormIntensity;
            } elseif ($command instanceof SetWaveAnimation) {
                $this->waveAmplitude = $command->amplitude;
                $this->waveFrequency = $command->frequency;
                $this->wavePhase = $command->phase;
                $this->vertexAnim = 1;
            }
        }

        $this->uploadFrameUbo();
        $this->uploadLightingUbo();

        // Pre-allocate instance buffer
        $maxInstanceMatrices = 0;
        foreach ($commandList->getCommands() as $cmd) {
            if ($cmd instanceof DrawMeshInstanced) {
                $maxInstanceMatrices = max($maxInstanceMatrices, count($cmd->matrices));
            }
        }
        $this->ensureInstanceBuffer(max($maxInstanceMatrices * 64, 64));

        // Pre-upload all meshes BEFORE render pass (MoltenVK dislikes buffer creation during recording)
        foreach ($commandList->getCommands() as $cmd) {
            if ($cmd instanceof DrawMesh || $cmd instanceof DrawMeshInstanced) {
                if (!isset($this->meshCache[$cmd->meshId])) {
                    $meshData = MeshRegistry::get($cmd->meshId);
                    if ($meshData !== null) {
                        $this->uploadMesh($cmd->meshId, $meshData);
                    }
                }
            }
        }

        $isDebugFrame = ($this->debugFrameNum <= 10 || $this->debugFrameNum % 120 === 0);

        if ($isDebugFrame) {
            fprintf(STDERR, "[VK:render] frame=%d cf=%d imgIdx=%d fbCount=%d dsCount=%d clear=(%.2f,%.2f,%.2f)\n",
                $this->debugFrameNum, $this->currentFrame, $this->currentImageIndex,
                count($this->framebuffers), count($this->descriptorSets),
                $this->clearR, $this->clearG, $this->clearB);
            fprintf(STDERR, "[VK:render] cam=(%.1f,%.1f,%.1f) dirLights=%d pointLights=%d fog=(%.1f-%.1f)\n",
                $this->cameraPos[0], $this->cameraPos[1], $this->cameraPos[2],
                count($this->dirLights), count($this->pointLights),
                $this->fog[3], $this->fog[4]);
        }

        $cmd = $this->commandBuffers[$this->currentFrame];

        // =====================================================================
        // Shadow + Cloud shadow passes (before scene rendering)
        // =====================================================================
        $this->renderShadowPasses($commandList, $cmd);

        // Update descriptor sets with real shadow/cloud images (or keep dummies)
        $ds = $this->descriptorSets[$this->currentImageIndex];
        if ($this->hasShadowMap && $this->shadowMap !== null && $this->shadowMap->isInitialized()) {
            $ds->writeImage(2, self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER,
                $this->shadowMap->getDepthImageView(), $this->shadowMap->getDepthSampler(),
                self::VK_LAYOUT_DEPTH_READ_ONLY);
        }
        if ($this->hasCloudShadow && $this->cloudShadow !== null && $this->cloudShadow->isInitialized()) {
            $ds->writeImage(3, self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER,
                $this->cloudShadow->getColorImageView(), $this->cloudShadow->getColorSampler(),
                self::VK_LAYOUT_SHADER_READ);
        }

        // Re-upload lighting UBO with updated shadow/cloud flags
        $this->uploadLightingUbo();

        $img = $this->swapImages[$this->currentImageIndex];

        // === OFFSCREEN RENDERING ===
        // MoltenVK workaround: render to offscreen image, then copy to swapchain.
        // Direct drawIndexed to swapchain images causes flickering on MoltenVK.
        $offImg = $this->offscreenImage;
        $depthImg = $this->swapDepthImages[$this->currentImageIndex];

        // Track layouts for MoltenVK (UNDEFINED as oldLayout causes issues after first use)
        static $offscreenInitialized = false;
        $offOldLayout = $offscreenInitialized ? self::VK_LAYOUT_TRANSFER_SRC : self::VK_LAYOUT_UNDEFINED;
        $offscreenInitialized = true;

        static $swapImageInitialized = [];
        $swapOldLayout = isset($swapImageInitialized[$this->currentImageIndex])
            ? self::VK_LAYOUT_PRESENT_SRC
            : self::VK_LAYOUT_UNDEFINED;
        $swapImageInitialized[$this->currentImageIndex] = true;

        // 1. Clear offscreen color + depth
        $cmd->imageMemoryBarrier(
            $offImg, $offOldLayout, self::VK_LAYOUT_TRANSFER_DST,
            0, self::VK_ACCESS_TRANSFER_WRITE,
            self::VK_PIPELINE_STAGE_TOP, self::VK_PIPELINE_STAGE_TRANSFER,
            self::VK_ASPECT_COLOR,
        );
        $cmd->clearColorImage($offImg, self::VK_LAYOUT_TRANSFER_DST,
            $this->clearR, $this->clearG, $this->clearB, 1.0);

        $cmd->imageMemoryBarrier(
            $depthImg, self::VK_LAYOUT_UNDEFINED, self::VK_LAYOUT_TRANSFER_DST,
            0, self::VK_ACCESS_TRANSFER_WRITE,
            self::VK_PIPELINE_STAGE_TOP, self::VK_PIPELINE_STAGE_TRANSFER,
            self::VK_ASPECT_DEPTH,
        );
        $cmd->clearDepthStencilImage($depthImg, self::VK_LAYOUT_TRANSFER_DST, 1.0, 0);

        // 2. Transition to rendering layouts
        $cmd->imageMemoryBarrier(
            $offImg, self::VK_LAYOUT_TRANSFER_DST, self::VK_LAYOUT_COLOR_ATTACHMENT,
            self::VK_ACCESS_TRANSFER_WRITE, self::VK_ACCESS_COLOR_WRITE,
            self::VK_PIPELINE_STAGE_TRANSFER, self::VK_PIPELINE_STAGE_COLOR_OUTPUT,
            self::VK_ASPECT_COLOR,
        );
        $cmd->imageMemoryBarrier(
            $depthImg, self::VK_LAYOUT_TRANSFER_DST, self::VK_LAYOUT_DEPTH_ATTACHMENT,
            self::VK_ACCESS_TRANSFER_WRITE, self::VK_ACCESS_DEPTH_WRITE,
            self::VK_PIPELINE_STAGE_TRANSFER, self::VK_PIPELINE_STAGE_EARLY_FRAG,
            self::VK_ASPECT_DEPTH,
        );

        // 3. Dynamic Rendering to offscreen image
        $cmd->beginRendering($this->width, $this->height, [
            [
                'imageView' => $this->offscreenView,
                'imageLayout' => self::VK_LAYOUT_COLOR_ATTACHMENT,
                'loadOp' => self::VK_LOAD_OP_DONT_CARE,
                'storeOp' => self::VK_STORE_OP_STORE,
            ],
        ], [
            'imageView' => $this->swapDepthViews[$this->currentImageIndex],
            'imageLayout' => self::VK_LAYOUT_DEPTH_ATTACHMENT,
            'loadOp' => self::VK_LOAD_OP_DONT_CARE,
            'storeOp' => self::VK_STORE_OP_DONT_CARE,
        ]);

        // Negative viewport height: Y-flip at rasterizer level (VK_KHR_maintenance1)
        // Viewport starts at Y=height and goes -height upward, flipping the image
        $cmd->setViewport(0.0, (float) $this->height, (float) $this->width, -(float) $this->height, 0.0, 1.0);
        $cmd->setScissor(0, 0, $this->width, $this->height);

        // 4. Bind pipeline and descriptors
        $ds = $this->descriptorSets[$this->currentImageIndex];
        $cmd->bindPipeline(self::VK_PIPELINE_BIND_GRAPHICS, $this->opaquePipeline);
        $cmd->bindDescriptorSets(self::VK_PIPELINE_BIND_GRAPHICS, $this->pipelineLayout, 0, [$ds]);

        // 5. Draw calls
        $drawCount = 0;
        $instancedCount = 0;
        $loggedDraws = 0;
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetWaveAnimation) {
                $this->waveAmplitude = $command->amplitude;
                $this->waveFrequency = $command->frequency;
                $this->wavePhase = $command->phase;
                $this->vertexAnim = 1;
                $this->uploadFrameUbo();
            } elseif ($command instanceof DrawMesh) {
                $this->applyMaterial($command->materialId);
                if ($this->debugFrameNum === 1 && $loggedDraws < 5) {
                    $m = $command->modelMatrix->toArray();
                    fprintf(STDERR, "[VK:draw#%d] mesh=%s mat=%s pos=(%.1f,%.1f,%.1f) scale=(%.2f,%.2f,%.2f)\n",
                        $loggedDraws, $command->meshId, $command->materialId,
                        $m[12], $m[13], $m[14], // translation from column 3
                        sqrt($m[0]*$m[0]+$m[1]*$m[1]+$m[2]*$m[2]),
                        sqrt($m[4]*$m[4]+$m[5]*$m[5]+$m[6]*$m[6]),
                        sqrt($m[8]*$m[8]+$m[9]*$m[9]+$m[10]*$m[10]));
                    $loggedDraws++;
                }
                $this->drawMeshCommand($command->meshId, $command->modelMatrix);
                $drawCount++;
            } elseif ($command instanceof DrawMeshInstanced) {
                $this->applyMaterial($command->materialId);
                $this->drawMeshInstancedCommand($command->meshId, $command->matrices);
                $instancedCount++;
            }
        }

        // 6. End rendering
        $cmd->endRendering();

        // 7. Copy offscreen → swapchain
        $cmd->imageMemoryBarrier(
            $offImg, self::VK_LAYOUT_COLOR_ATTACHMENT, self::VK_LAYOUT_TRANSFER_SRC,
            self::VK_ACCESS_COLOR_WRITE, self::VK_ACCESS_TRANSFER_READ,
            self::VK_PIPELINE_STAGE_COLOR_OUTPUT, self::VK_PIPELINE_STAGE_TRANSFER,
            self::VK_ASPECT_COLOR,
        );
        $cmd->imageMemoryBarrier(
            $img, $swapOldLayout, self::VK_LAYOUT_TRANSFER_DST,
            0, self::VK_ACCESS_TRANSFER_WRITE,
            self::VK_PIPELINE_STAGE_TOP, self::VK_PIPELINE_STAGE_TRANSFER,
            self::VK_ASPECT_COLOR,
        );
        $cmd->copyImage(
            $offImg, self::VK_LAYOUT_TRANSFER_SRC,
            $img, self::VK_LAYOUT_TRANSFER_DST,
            $this->width, $this->height,
        );

        // 8. Swapchain → PRESENT_SRC
        $cmd->imageMemoryBarrier(
            $img, self::VK_LAYOUT_TRANSFER_DST, self::VK_LAYOUT_PRESENT_SRC,
            self::VK_ACCESS_TRANSFER_WRITE, 0,
            self::VK_PIPELINE_STAGE_TRANSFER, self::VK_PIPELINE_STAGE_TOP,
            self::VK_ASPECT_COLOR,
        );

        if ($isDebugFrame) {
            fprintf(STDERR, "[VK:render] frame=%d draws=%d instanced=%d meshCache=%d\n",
                $this->debugFrameNum, $drawCount, $instancedCount, count($this->meshCache));
        }
    }

    // =========================================================================
    // Shadow + Cloud Shadow passes
    // =========================================================================

    private bool $shadowDescriptorsUpdated = false;

    private function renderShadowPasses(RenderCommandList $commandList, \Vk\CommandBuffer $cmd): void
    {
        if ($this->shadowMap === null || !$this->shadowMap->isInitialized()) return;

        // Find brightest directional light
        $lightDir = null;
        $lightIntensity = 0.0;
        foreach ($this->dirLights as $dl) {
            if ($dl['intensity'] > $lightIntensity) {
                $lightDir = new Vec3($dl['dir'][0], $dl['dir'][1], $dl['dir'][2]);
                $lightIntensity = $dl['intensity'];
            }
        }

        if ($lightDir === null || $lightIntensity < 0.05) return;

        // Update light-space matrix
        $this->shadowMap->updateLightMatrix($lightDir);
        $lsm = $this->shadowMap->getLightSpaceMatrix();
        $this->lightSpaceMatrix = $lsm->toArray();

        // Collect shadow-casting draws (opaque, non-sky/cloud/precipitation)
        $shadowDraws = [];
        $cloudDraws = [];
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof DrawMesh) {
                $matId = $command->materialId;
                $mat = MaterialRegistry::get($matId);

                // Cloud draws → cloud shadow pass
                if (str_starts_with($matId, 'cloud_')) {
                    if ($mat !== null) {
                        $opacity = (1.0 - ($mat->alpha ?? 1.0)) * 0.5 + 0.1;
                        $cloudDraws[] = ['cmd' => $command, 'opacity' => $opacity];
                    }
                    continue;
                }

                // Skip non-shadow-casting materials
                if (str_starts_with($matId, 'sky_') || str_starts_with($matId, 'sun_')
                    || str_starts_with($matId, 'moon_') || $matId === 'precipitation') {
                    continue;
                }

                // Only opaque geometry casts shadows
                if ($mat !== null && ($mat->alpha ?? 1.0) >= 0.9) {
                    $shadowDraws[] = $command;
                }
            } elseif ($command instanceof DrawMeshInstanced) {
                $matId = $command->materialId;
                if (str_starts_with($matId, 'sky_') || str_starts_with($matId, 'cloud_')
                    || str_starts_with($matId, 'sun_') || str_starts_with($matId, 'moon_')) {
                    continue;
                }
                $mat = MaterialRegistry::get($matId);
                if ($mat !== null && ($mat->alpha ?? 1.0) >= 0.9) {
                    $shadowDraws[] = $command;
                }
            }
        }

        // === Shadow depth pass ===
        if (!empty($shadowDraws)) {
            $this->shadowMap->recordShadowPass(
                $cmd,
                $this->meshCache,
                $shadowDraws,
                $this->instanceBuffer,
            );
            $this->hasShadowMap = 1;
        }

        // === Cloud shadow pass ===
        if (!empty($cloudDraws) && $this->cloudShadow !== null && $this->cloudShadow->isInitialized()) {
            $this->cloudShadow->recordCloudPass(
                $cmd,
                $lsm,
                $this->meshCache,
                $cloudDraws,
            );
            $this->hasCloudShadow = 1;
        }
    }

    // =========================================================================
    // Material
    // =========================================================================

    private function applyMaterial(string $materialId): void
    {
        $this->procMode = self::$procModeCache[$materialId] ?? $this->resolveProcMode($materialId);

        $material = MaterialRegistry::get($materialId);
        if ($material !== null) {
            $this->albedo = [$material->albedo->r, $material->albedo->g, $material->albedo->b];
            $this->emission = [$material->emission->r, $material->emission->g, $material->emission->b];
            $this->roughness = $material->roughness;
            $this->metallic = $material->metallic;
            $this->alpha = $material->alpha;
        } else {
            $this->albedo = [0.8, 0.8, 0.8];
            $this->emission = [0.0, 0.0, 0.0];
            $this->roughness = 0.5;
            $this->metallic = 0.0;
            $this->alpha = 1.0;
        }

        // Moon phase encoded in roughness
        if ($this->procMode === 9 && $material !== null) {
            $this->moonPhase = $material->roughness;
        }

        // Sand terrain: albedo as seasonal tint
        if ($this->procMode === 1 && $material !== null) {
            $this->seasonTint = [
                $material->albedo->r / max(0.01, 0.77),
                $material->albedo->g / max(0.01, 0.66),
                $material->albedo->b / max(0.01, 0.41),
            ];
        }
    }

    private function resolveProcMode(string $materialId): int
    {
        $prefix = strtok($materialId, '0123456789');

        $mode = match (true) {
            str_starts_with($prefix, 'sand_terrain') => 1,
            str_starts_with($prefix, 'water_') => 2,
            str_starts_with($prefix, 'rock') => 3,
            str_starts_with($prefix, 'palm_trunk') => 4,
            str_starts_with($prefix, 'palm_branch'),
            str_starts_with($prefix, 'palm_leaves'),
            str_starts_with($prefix, 'palm_leaf'),
            str_starts_with($prefix, 'palm_canopy'),
            str_starts_with($prefix, 'palm_frond') => 5,
            str_starts_with($prefix, 'cloud_') => 6,
            str_starts_with($prefix, 'hut_wood'),
            str_starts_with($prefix, 'hut_door'),
            str_starts_with($prefix, 'hut_table'),
            str_starts_with($prefix, 'hut_chair'),
            str_starts_with($prefix, 'hut_floor'),
            str_starts_with($prefix, 'hut_window') => 7,
            str_starts_with($prefix, 'hut_thatch') => 8,
            str_starts_with($prefix, 'moon_disc') => 9,
            str_starts_with($prefix, 'rainbow') => 10,
            str_starts_with($prefix, 'glass'),
            str_starts_with($prefix, 'crystal'),
            str_starts_with($prefix, 'window_glass') => 11,
            str_starts_with($prefix, 'chrome'),
            str_starts_with($prefix, 'steel'),
            str_starts_with($prefix, 'copper'),
            str_starts_with($prefix, 'gold'),
            str_starts_with($prefix, 'iron'),
            str_starts_with($prefix, 'polished_metal') => 12,
            str_starts_with($prefix, 'fabric'),
            str_starts_with($prefix, 'cloth'),
            str_starts_with($prefix, 'canvas'),
            str_starts_with($prefix, 'silk'),
            str_starts_with($prefix, 'cotton'),
            str_starts_with($prefix, 'wool') => 13,
            str_starts_with($prefix, 'fire'),
            str_starts_with($prefix, 'flame'),
            str_starts_with($prefix, 'torch') => 14,
            str_starts_with($prefix, 'lava'),
            str_starts_with($prefix, 'magma'),
            str_starts_with($prefix, 'molten') => 15,
            str_starts_with($prefix, 'ice'),
            str_starts_with($prefix, 'frost'),
            str_starts_with($prefix, 'frozen') => 16,
            str_starts_with($prefix, 'grass'),
            str_starts_with($prefix, 'lawn'),
            str_starts_with($prefix, 'vegetation') => 17,
            str_starts_with($prefix, 'neon'),
            str_starts_with($prefix, 'glow'),
            str_starts_with($prefix, 'led') => 18,
            str_starts_with($prefix, 'concrete'),
            str_starts_with($prefix, 'asphalt'),
            str_starts_with($prefix, 'cement') => 19,
            str_starts_with($prefix, 'brick'),
            str_starts_with($prefix, 'masonry') => 20,
            str_starts_with($prefix, 'tile'),
            str_starts_with($prefix, 'ceramic'),
            str_starts_with($prefix, 'porcelain') => 21,
            str_starts_with($prefix, 'leather'),
            str_starts_with($prefix, 'hide') => 22,
            str_starts_with($prefix, 'skin'),
            str_starts_with($prefix, 'flesh'),
            str_starts_with($prefix, 'organic') => 23,
            str_starts_with($prefix, 'particle'),
            str_starts_with($prefix, 'smoke'),
            str_starts_with($prefix, 'dust') => 24,
            str_starts_with($prefix, 'hologram'),
            str_starts_with($prefix, 'holo'),
            str_starts_with($prefix, 'cyber') => 25,
            default => 0,
        };

        self::$procModeCache[$materialId] = $mode;
        return $mode;
    }

    /**
     * Build 64 bytes of material data for push constants (offset 64-127).
     * Layout matches the fragment shader PC block:
     *   vec3 albedo + float roughness        (16 bytes)
     *   vec3 emission + float metallic       (16 bytes)
     *   float alpha + int procMode + float moonPhase + float time (16 bytes)
     *   vec3 seasonTint + float pad          (16 bytes)
     */
    private function packMaterialConstants(): string
    {
        return pack('f4', $this->albedo[0], $this->albedo[1], $this->albedo[2], $this->roughness)
            . pack('f4', $this->emission[0], $this->emission[1], $this->emission[2], $this->metallic)
            . pack('f1', $this->alpha) . pack('i1', $this->procMode) . pack('f2', $this->moonPhase, $this->time)
            . pack('f4', $this->seasonTint[0], $this->seasonTint[1], $this->seasonTint[2], 0.0);
    }

    private static function isSkyMaterial(string $matId): bool
    {
        return str_starts_with($matId, 'sky_')
            || str_starts_with($matId, 'sun_')
            || str_starts_with($matId, 'moon_')
            || str_starts_with($matId, 'cloud_')
            || $matId === 'precipitation';
    }

    // =========================================================================
    // Draw
    // =========================================================================

    private function drawMeshCommand(string $meshId, Mat4 $modelMatrix): void
    {
        $meshData = MeshRegistry::get($meshId);
        if ($meshData === null) return;
        if (!isset($this->meshCache[$meshId])) {
            $this->uploadMesh($meshId, $meshData);
        }

        $pcData = pack('f16', ...$modelMatrix->toArray()) . $this->packMaterialConstants();
        $this->commandBuffers[$this->currentFrame]->pushConstants(
            $this->pipelineLayout, self::VK_SHADER_STAGE_VERTEX | self::VK_SHADER_STAGE_FRAGMENT, 0, $pcData,
        );
        // Write identity matrix to instance buffer (prevents garbage data on MoltenVK)
        static $identityBytes = null;
        if ($identityBytes === null) {
            $identityBytes = pack('f16', ...Mat4::identity()->toArray());
        }
        $this->instanceBufferMem->write($identityBytes, 0);

        $this->commandBuffers[$this->currentFrame]->bindVertexBuffers(0, [$this->meshCache[$meshId]['vb'], $this->instanceBuffer], [0, 0]);
        $this->commandBuffers[$this->currentFrame]->bindIndexBuffer($this->meshCache[$meshId]['ib'], 0, self::VK_INDEX_TYPE_UINT32);
        $this->commandBuffers[$this->currentFrame]->drawIndexed($this->meshCache[$meshId]['count'], 1, 0, 0, 0);
    }

    /**
     * @param Mat4[] $matrices
     */
    private function drawMeshInstancedCommand(string $meshId, array $matrices): void
    {
        if (!isset($this->meshCache[$meshId]) || empty($matrices)) return;

        $instanceCount = count($matrices);
        $matrixData = '';
        foreach ($matrices as $matrix) {
            $matrixData .= pack('f16', ...$matrix->toArray());
        }

        $requiredSize = $instanceCount * 64;
        $this->ensureInstanceBuffer($requiredSize);

        if ($this->instanceBuffer === null) {
            // Fallback to loop
            foreach ($matrices as $matrix) {
                $this->drawMeshCommand($meshId, $matrix);
            }
            return;
        }

        $this->instanceBufferMem->write($matrixData, 0);

        // Push full 128 bytes (identity model + material) in one call
        $pcData = pack('f16', ...Mat4::identity()->toArray()) . $this->packMaterialConstants();
        $this->commandBuffers[$this->currentFrame]->pushConstants(
            $this->pipelineLayout, self::VK_SHADER_STAGE_VERTEX | self::VK_SHADER_STAGE_FRAGMENT, 0, $pcData,
        );
        $this->commandBuffers[$this->currentFrame]->bindVertexBuffers(0, [$this->meshCache[$meshId]['vb'], $this->instanceBuffer], [0, 0]);
        $this->commandBuffers[$this->currentFrame]->bindIndexBuffer($this->meshCache[$meshId]['ib'], 0, self::VK_INDEX_TYPE_UINT32);
        $this->commandBuffers[$this->currentFrame]->drawIndexed($this->meshCache[$meshId]['count'], $instanceCount, 0, 0, 0);
    }

    /**
     * Pre-upload instance matrices for shadow/cloud passes that use instanced draws.
     * @param array<DrawMesh|DrawMeshInstanced> $draws
     */
    private function prepareInstanceBuffer(array $draws): void
    {
        $maxInstances = 0;
        foreach ($draws as $cmd) {
            if ($cmd instanceof DrawMeshInstanced) {
                $maxInstances = max($maxInstances, count($cmd->matrices));
            }
        }
        if ($maxInstances > 0) {
            $this->ensureInstanceBuffer($maxInstances * 64);
        }
    }

    private function ensureInstanceBuffer(int $requiredSize): void
    {
        if ($this->instanceBuffer !== null && $this->instanceBufferCapacity >= $requiredSize) return;

        // Retire old buffer — keep alive until frame submit completes (command buffer references it)
        if ($this->instanceBuffer !== null) {
            $this->retiredBuffers[] = $this->instanceBuffer;
            $this->retiredBuffers[] = $this->instanceBufferMem;
        }

        $newCapacity = max($requiredSize, 4096);
        $this->instanceBuffer = new Buffer(
            $this->device, $newCapacity,
            self::VK_BUFFER_USAGE_VERTEX, self::VK_SHARING_EXCLUSIVE,
        );
        $req = $this->instanceBuffer->getMemoryRequirements();
        $reqSize = $req['size'];
        if (!is_int($reqSize)) {
            $this->instanceBuffer = null;
            return;
        }
        $this->instanceBufferMem = new DeviceMemory(
            $this->device, $reqSize, $this->findMemory($req, true),
        );
        $this->instanceBuffer->bindMemory($this->instanceBufferMem, 0);
        $this->instanceBufferMem->map(0, null);
        $this->instanceBufferCapacity = $newCapacity;
    }

    // =========================================================================
    // UBO Upload
    // =========================================================================

    private function uploadFrameUbo(): void
    {
        $data = pack('f16', ...$this->viewMatrix) . pack('f16', ...$this->projMatrix);
        $data .= pack('f2i2', $this->time, $this->temperature, 0, $this->vertexAnim);
        $data .= pack('f4', $this->waveAmplitude, $this->waveFrequency, $this->wavePhase, 0.0);
        $data .= pack('f4', $this->cameraPos[0], $this->cameraPos[1], $this->cameraPos[2], 0.0);
        $data .= str_repeat("\0", self::FRAME_UBO_SIZE - strlen($data));
        $this->frameUboMems[$this->currentImageIndex]->write($data, 0);
    }

    private function uploadLightingUbo(): void
    {
        // Ambient: vec3 + float = 16
        $data = pack('f4', $this->ambient[0], $this->ambient[1], $this->ambient[2], $this->ambient[3]);

        // Material: albedo(vec3)+roughness, emission(vec3)+metallic, alpha+time+proc_mode+moon = 48
        $data .= pack('f4', $this->albedo[0], $this->albedo[1], $this->albedo[2], $this->roughness);
        $data .= pack('f4', $this->emission[0], $this->emission[1], $this->emission[2], $this->metallic);
        $data .= pack('f2', $this->alpha, $this->time);
        $data .= pack('i1', $this->procMode);
        $data .= pack('f1', $this->moonPhase);

        // Fog: vec3+near, vec3_camera+far = 32
        $data .= pack('f4', $this->fog[0], $this->fog[1], $this->fog[2], $this->fog[3]);
        $data .= pack('f4', $this->cameraPos[0], $this->cameraPos[1], $this->cameraPos[2], $this->fog[4]);

        // Sky: skyColor(vec3)+hasEnvMap, horizonColor(vec3)+hasShadow, seasonTint(vec3)+hasCloud = 48
        $data .= pack('f3i1', $this->skyColor[0], $this->skyColor[1], $this->skyColor[2], $this->hasEnvMap);
        $data .= pack('f3i1', $this->horizonColor[0], $this->horizonColor[1], $this->horizonColor[2], $this->hasShadowMap);
        $data .= pack('f3i1', $this->seasonTint[0], $this->seasonTint[1], $this->seasonTint[2], $this->hasCloudShadow);

        // Weather: 5 floats + 3 pad = 32
        $data .= pack('f8', $this->rainIntensity, $this->snowCoverage, $this->temperature,
            $this->dewWetness, $this->stormIntensity, 0.0, 0.0, 0.0);

        // Shadow matrix: mat4 = 64
        $data .= pack('f16', ...$this->lightSpaceMatrix);

        // Dir lights: count + 3 pad (16) + 16 lights × 32 bytes = 528
        $dlCount = count($this->dirLights);
        $data .= pack('i4', $dlCount, 0, 0, 0);
        for ($i = 0; $i < 16; $i++) {
            if ($i < $dlCount) {
                $dl = $this->dirLights[$i];
                $data .= pack('f4', $dl['dir'][0], $dl['dir'][1], $dl['dir'][2], 0.0);
                $data .= pack('f4', $dl['color'][0], $dl['color'][1], $dl['color'][2], $dl['intensity']);
            } else {
                $data .= pack('f8', 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
            }
        }

        // Point lights: count + 3 pad (16) + 8 lights × 32 bytes = 272
        $plCount = count($this->pointLights);
        $data .= pack('i4', $plCount, 0, 0, 0);
        for ($i = 0; $i < 8; $i++) {
            if ($i < $plCount) {
                $pl = $this->pointLights[$i];
                $data .= pack('f4', $pl['pos'][0], $pl['pos'][1], $pl['pos'][2], $pl['intensity']);
                $data .= pack('f4', $pl['color'][0], $pl['color'][1], $pl['color'][2], $pl['radius']);
            } else {
                $data .= pack('f8', 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0);
            }
        }

        if (strlen($data) < self::LIGHTING_UBO_SIZE) {
            $data .= str_repeat("\0", self::LIGHTING_UBO_SIZE - strlen($data));
        }

        $this->lightingUboMems[$this->currentImageIndex]->write($data, 0);
    }

    // =========================================================================
    // Mesh Upload
    // =========================================================================

    private function uploadMesh(string $meshId, MeshData $meshData): void
    {
        $vertexCount = $meshData->vertexCount();
        $vertexData = '';
        for ($i = 0; $i < $vertexCount; $i++) {
            $vertexData .= pack('f8',
                $meshData->vertices[$i * 3], $meshData->vertices[$i * 3 + 1], $meshData->vertices[$i * 3 + 2],
                $meshData->normals[$i * 3], $meshData->normals[$i * 3 + 1], $meshData->normals[$i * 3 + 2],
                $meshData->uvs[$i * 2], $meshData->uvs[$i * 2 + 1],
            );
        }

        $vb = new Buffer($this->device, strlen($vertexData), self::VK_BUFFER_USAGE_VERTEX, self::VK_SHARING_EXCLUSIVE);
        $vbReq = $vb->getMemoryRequirements();
        $vbSize = $vbReq['size'];
        if (!is_int($vbSize)) throw new \RuntimeException('Invalid vertex buffer memory requirements');
        $vbMem = new DeviceMemory($this->device, $vbSize, $this->findMemory($vbReq, true));
        $vb->bindMemory($vbMem, 0);
        $vbMem->map(0, null);
        $vbMem->write($vertexData, 0);

        $indexData = '';
        foreach ($meshData->indices as $idx) {
            $indexData .= pack('V', $idx);
        }
        $ib = new Buffer($this->device, strlen($indexData), self::VK_BUFFER_USAGE_INDEX, self::VK_SHARING_EXCLUSIVE);
        $ibReq = $ib->getMemoryRequirements();
        $ibSize = $ibReq['size'];
        if (!is_int($ibSize)) throw new \RuntimeException('Invalid index buffer memory requirements');
        $ibMem = new DeviceMemory($this->device, $ibSize, $this->findMemory($ibReq, true));
        $ib->bindMemory($ibMem, 0);
        $ibMem->map(0, null);
        $ibMem->write($indexData, 0);

        $this->meshCache[$meshId] = [
            'vb' => $vb, 'vbMem' => $vbMem,
            'ib' => $ib, 'ibMem' => $ibMem,
            'count' => count($meshData->indices),
        ];
    }

    // =========================================================================
    // Vulkan Initialization
    // =========================================================================

    private function initVulkan(\GLFWwindow $windowHandle): void
    {
        $this->ensureMacOSVulkanEnv();

        $extensions = ['VK_KHR_surface', 'VK_KHR_portability_enumeration'];
        if (PHP_OS_FAMILY === 'Darwin') {
            $extensions[] = 'VK_EXT_metal_surface';
            $extensions[] = 'VK_MVK_macos_surface';
        } elseif (PHP_OS_FAMILY === 'Linux') {
            $extensions[] = 'VK_KHR_xcb_surface';
            $extensions[] = 'VK_KHR_xlib_surface';
            $extensions[] = 'VK_KHR_wayland_surface';
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $extensions[] = 'VK_KHR_win32_surface';
        }

        fprintf(STDERR, "[Vulkan] Instance extensions: %s\n", implode(', ', $extensions));

        $this->instance = new Instance('PHPolygon', 1, 'PHPolygon', 1, null, false, $extensions);
        $this->surface = new Surface($this->instance, $windowHandle);

        $rawDevices = $this->instance->getPhysicalDevices();
        $firstDevice = $rawDevices[0] ?? null;
        if (!$firstDevice instanceof PhysicalDevice) {
            throw new \RuntimeException('No Vulkan physical devices found');
        }
        $this->gpu = $firstDevice;

        $rawMemProps = $this->gpu->getMemoryProperties();
        $rawTypes = $rawMemProps['types'] ?? [];
        if (is_array($rawTypes)) {
            foreach ($rawTypes as $t) {
                $this->memTypes[] = is_array($t) ? $t : [];
            }
        }

        $this->graphicsFamily = $this->selectQueueFamily();

        $this->device = new Device(
            $this->gpu,
            [['familyIndex' => $this->graphicsFamily, 'count' => 1]],
            ['VK_KHR_swapchain', 'VK_KHR_dynamic_rendering'],
            null,
        );
        $this->queue = $this->device->getQueue($this->graphicsFamily, 0);

        $this->createSwapchain();
        $this->createRenderPass();
        $this->createUBOs();
        $this->createCommandObjects();
        $this->createSyncObjects();

        $findHostMem = fn(array $req) => $this->findMemory($req, true);
        $findDeviceMem = fn(array $req) => $this->findMemory($req, false);

        // Create offscreen render target (drawIndexed to swapchain images unreliable on MoltenVK)
        $this->createOffscreenTarget();

        // Create dummy 1×1 images for shadow/cloud descriptor bindings, then transition
        // to shader-readable layouts. Must happen before createDescriptors() writes them.
        $this->createDummyImages($findHostMem, $findDeviceMem);
        $this->transitionDummyImages();

        fprintf(STDERR, "[Vulkan] Init: pipeline...\n");
        $this->createPipeline();
        $this->createDescriptors();
        $this->createScenePipelines();

        // Initialize shadow/cloud renderers (after pipeline — they need the device ready)
        $this->shadowMap = new VulkanShadowMapRenderer($this->device);
        $this->shadowMap->initialize($findHostMem, $findDeviceMem);
        $this->cloudShadow = new VulkanCloudShadowRenderer($this->device);
        $this->cloudShadow->initialize($findHostMem, $findDeviceMem);
        fprintf(STDERR, "[Vulkan] Init: shadow+cloud renderers ready\n");

        fprintf(STDERR, "[Vulkan] Init: complete!\n");
    }

    private function selectQueueFamily(): int
    {
        $queueFamilies = $this->gpu->getQueueFamilies();
        if (!is_array($queueFamilies)) throw new \RuntimeException('getQueueFamilies() did not return an array');
        foreach ($queueFamilies as $qf) {
            if (!is_array($qf) || empty($qf['graphics'])) continue;
            $idx = $qf['index'];
            if (!is_int($idx)) continue;
            if ($this->gpu->getSurfaceSupport($idx, $this->surface)) return $idx;
        }
        throw new \RuntimeException('No Vulkan graphics+present queue family found');
    }

    private function createSwapchain(): void
    {
        $caps = $this->surface->getCapabilities($this->gpu);
        $rawFormats = $this->surface->getFormats($this->gpu);

        // Use surface's preferred format — log available formats to diagnose color differences.
        // NOTE: If format is SRGB (50), hardware applies gamma on write. The fragment shader
        // also does manual pow(1/2.2) → possible double gamma (washed-out colors vs OpenGL).
        // VK_FORMAT_B8G8R8A8_UNORM = 44, VK_FORMAT_B8G8R8A8_SRGB = 50
        $firstFormat = is_array($rawFormats) ? ($rawFormats[0] ?? []) : [];
        $format = is_array($firstFormat) ? ($firstFormat['format'] ?? 44) : 44;
        $colorSpace = is_array($firstFormat) ? ($firstFormat['colorSpace'] ?? 0) : 0;
        // Prefer UNORM if available (avoids double gamma with manual shader correction)
        $foundUnorm = false;
        if (is_array($rawFormats)) {
            foreach ($rawFormats as $candidate) {
                if (!is_array($candidate)) continue;
                $candidateFormat = (int)($candidate['format'] ?? 0);
                if ($candidateFormat === 44 || $candidateFormat === 37) {
                    $format = $candidateFormat;
                    $colorSpace = (int)($candidate['colorSpace'] ?? 0);
                    $foundUnorm = true;
                    break;
                }
            }
        }
        $this->surfaceFormat = is_int($format) ? $format : (int) $format;
        fprintf(STDERR, "[Vulkan] Surface format: %d (%s) colorSpace=%d available=[%s]\n",
            $this->surfaceFormat, $foundUnorm ? 'UNORM' : ($this->surfaceFormat === 50 ? 'SRGB⚠' : 'unknown'),
            $colorSpace,
            is_array($rawFormats) ? implode(',', array_map(fn($f) => is_array($f) ? (string)($f['format'] ?? '?') : '?', $rawFormats)) : 'none');

        $minCount = is_array($caps) ? ($caps['minImageCount'] ?? 2) : 2;
        $maxCount = is_array($caps) ? ($caps['maxImageCount'] ?? 3) : 3;
        $transform = is_array($caps) ? ($caps['currentTransform'] ?? 1) : 1;
        // Force 2 images — MoltenVK has issues with triple buffering
        $imageCount = 2;

        $this->swapchain = new Swapchain($this->device, $this->surface, [
            'minImageCount' => $imageCount,
            'imageFormat' => $this->surfaceFormat,
            'imageColorSpace' => is_int($colorSpace) ? $colorSpace : (int) $colorSpace,
            'imageExtent' => ['width' => $this->width, 'height' => $this->height],
            'imageArrayLayers' => 1,
            'imageUsage' => self::VK_IMAGE_USAGE_COLOR | 2, // + VK_IMAGE_USAGE_TRANSFER_DST_BIT for clears
            'imageSharingMode' => self::VK_SHARING_EXCLUSIVE,
            'preTransform' => is_int($transform) ? $transform : (int) $transform,
            'compositeAlpha' => 1,
            'presentMode' => self::VK_PRESENT_MODE_FIFO,
            'clipped' => true,
        ]);

        // Read ACTUAL surface extent (flat keys from C extension, not nested)
        $surfaceW = is_array($caps) ? ($caps['currentWidth'] ?? 0) : 0;
        $surfaceH = is_array($caps) ? ($caps['currentHeight'] ?? 0) : 0;
        $maxW = is_array($caps) ? ($caps['maxWidth'] ?? 0) : 0;
        $maxH = is_array($caps) ? ($caps['maxHeight'] ?? 0) : 0;
        fprintf(STDERR, "[Vulkan] Surface: actual=%dx%d max=%dx%d requested=%dx%d\n",
            $surfaceW, $surfaceH, $maxW, $maxH, $this->width, $this->height);

        // Use surface extent if available (MoltenVK reports actual drawable size)
        if ($surfaceW > 0 && $surfaceH > 0 && ($surfaceW !== $this->width || $surfaceH !== $this->height)) {
            fprintf(STDERR, "[Vulkan] *** MISMATCH! Adjusting renderer from %dx%d to surface %dx%d ***\n",
                $this->width, $this->height, $surfaceW, $surfaceH);
            $this->width = (int)$surfaceW;
            $this->height = (int)$surfaceH;
        }

        fprintf(STDERR, "[Vulkan] Swapchain: format=%d images=%d final=%dx%d\n",
            $this->surfaceFormat, $imageCount, $this->width, $this->height);

        $this->swapImages = $this->swapchain->getImages();
        if (!is_array($this->swapImages)) throw new \RuntimeException('getImages() did not return an array');
        fprintf(STDERR, "[Vulkan] Swapchain: actual images=%d\n", count($this->swapImages));
        foreach ($this->swapImages as $img) {
            if (!$img instanceof Image) throw new \RuntimeException('Swapchain image is not a Vk\\Image');
            $this->swapImageViews[] = new ImageView($this->device, $img, $this->surfaceFormat, self::VK_ASPECT_COLOR, 1);
        }
    }

    private function createRenderPass(): void
    {
        // Render pass: color uses LOAD (explicit clearColorImage before render pass),
        // depth uses CLEAR. MoltenVK workaround: loadOp=CLEAR on swapchain images is unreliable.
        $this->renderPass = new RenderPass(
            $this->device,
            [
                [
                    'format' => $this->surfaceFormat,
                    'samples' => self::VK_SAMPLE_COUNT_1,
                    'loadOp' => 0, // VK_ATTACHMENT_LOAD_OP_LOAD — preserve explicit clear
                    'storeOp' => self::VK_STORE_OP_STORE,
                    'stencilLoadOp' => self::VK_LOAD_OP_DONT_CARE,
                    'stencilStoreOp' => self::VK_STORE_OP_DONT_CARE,
                    'initialLayout' => self::VK_LAYOUT_COLOR_ATTACHMENT,
                    'finalLayout' => self::VK_LAYOUT_PRESENT_SRC,
                ],
                [
                    'format' => self::VK_FORMAT_D32_SFLOAT,
                    'samples' => self::VK_SAMPLE_COUNT_1,
                    'loadOp' => 0, // VK_ATTACHMENT_LOAD_OP_LOAD — explicit clear before render pass
                    'storeOp' => self::VK_STORE_OP_DONT_CARE,
                    'stencilLoadOp' => self::VK_LOAD_OP_DONT_CARE,
                    'stencilStoreOp' => self::VK_STORE_OP_DONT_CARE,
                    'initialLayout' => self::VK_LAYOUT_DEPTH_ATTACHMENT,
                    'finalLayout' => self::VK_LAYOUT_DEPTH_ATTACHMENT,
                ],
            ],
            [
                [
                    'pipelineBindPoint' => self::VK_PIPELINE_BIND_GRAPHICS,
                    'colorAttachments' => [['attachment' => 0, 'layout' => self::VK_LAYOUT_COLOR_ATTACHMENT]],
                    'depthAttachment' => ['attachment' => 1, 'layout' => self::VK_LAYOUT_DEPTH_ATTACHMENT],
                ],
            ],
            // Subpass dependency: wait for swapchain image to be available before writing
            [[
                'srcSubpass' => self::VK_SUBPASS_EXTERNAL,
                'dstSubpass' => 0,
                'srcStageMask' => self::VK_PIPELINE_STAGE_COLOR_OUTPUT | self::VK_PIPELINE_STAGE_EARLY_FRAG,
                'dstStageMask' => self::VK_PIPELINE_STAGE_COLOR_OUTPUT | self::VK_PIPELINE_STAGE_EARLY_FRAG,
                'srcAccessMask' => 0,
                'dstAccessMask' => self::VK_ACCESS_COLOR_WRITE | self::VK_ACCESS_DEPTH_WRITE,
            ]],
        );

        // Create one depth buffer PER swapchain image to prevent race conditions
        // when frames-in-flight overlap on the GPU (both would clear/write the same depth)
        $this->swapDepthImages = [];
        $this->swapDepthMems = [];
        $this->swapDepthViews = [];

        foreach ($this->swapImageViews as $i => $colorView) {
            $depthImg = new Image($this->device, $this->width, $this->height,
                self::VK_FORMAT_D32_SFLOAT, self::VK_IMAGE_USAGE_DEPTH | 2, 0, self::VK_SAMPLE_COUNT_1); // +TRANSFER_DST for explicit clear
            $depthReq = $depthImg->getMemoryRequirements();
            $depthSize = $depthReq['size'];
            if (!is_int($depthSize)) throw new \RuntimeException('Invalid depth image memory requirements');
            $depthMem = new DeviceMemory($this->device, $depthSize, $this->findMemory($depthReq, false));
            $depthImg->bindMemory($depthMem, 0);
            $depthView = new ImageView($this->device, $depthImg, self::VK_FORMAT_D32_SFLOAT, self::VK_ASPECT_DEPTH, 1);

            $this->swapDepthImages[$i] = $depthImg;
            $this->swapDepthMems[$i] = $depthMem;
            $this->swapDepthViews[$i] = $depthView;

            $this->framebuffers[] = new Framebuffer(
                $this->device, $this->renderPass,
                [$colorView, $depthView],
                $this->width, $this->height, 1,
            );
        }

        // DEBUG: Color-only render pass (no depth) to test if depth causes flicker
        $this->debugColorOnlyRenderPass = new RenderPass(
            $this->device,
            [
                [
                    'format' => $this->surfaceFormat,
                    'samples' => self::VK_SAMPLE_COUNT_1,
                    'loadOp' => self::VK_LOAD_OP_CLEAR,
                    'storeOp' => self::VK_STORE_OP_STORE,
                    'stencilLoadOp' => self::VK_LOAD_OP_DONT_CARE,
                    'stencilStoreOp' => self::VK_STORE_OP_DONT_CARE,
                    'initialLayout' => self::VK_LAYOUT_UNDEFINED,
                    'finalLayout' => self::VK_LAYOUT_PRESENT_SRC,
                ],
            ],
            [
                [
                    'pipelineBindPoint' => self::VK_PIPELINE_BIND_GRAPHICS,
                    'colorAttachments' => [['attachment' => 0, 'layout' => self::VK_LAYOUT_COLOR_ATTACHMENT]],
                ],
            ],
            [],
        );
        $this->debugColorOnlyFramebuffers = [];
        foreach ($this->swapImageViews as $colorView) {
            $this->debugColorOnlyFramebuffers[] = new Framebuffer(
                $this->device, $this->debugColorOnlyRenderPass,
                [$colorView],
                $this->width, $this->height, 1,
            );
        }
    }

    private function createOffscreenTarget(): void
    {
        // Offscreen color image — same format as swapchain, device-local, used as color attachment + transfer src
        $this->offscreenImage = new Image(
            $this->device, $this->width, $this->height,
            $this->surfaceFormat,
            self::VK_IMAGE_USAGE_COLOR | 1 | 2 | self::VK_IMAGE_USAGE_SAMPLED, // COLOR_ATTACHMENT | TRANSFER_SRC | TRANSFER_DST | SAMPLED
            0, self::VK_SAMPLE_COUNT_1,
        );
        $req = $this->offscreenImage->getMemoryRequirements();
        $size = $req['size'];
        if (!is_int($size)) throw new \RuntimeException('Invalid offscreen image memory');
        $this->offscreenMem = new DeviceMemory($this->device, $size, $this->findMemory($req, false));
        $this->offscreenImage->bindMemory($this->offscreenMem, 0);
        $this->offscreenView = new ImageView(
            $this->device, $this->offscreenImage, $this->surfaceFormat, self::VK_ASPECT_COLOR, 1,
        );
        fprintf(STDERR, "[Vulkan] Offscreen target: %dx%d format=%d\n", $this->width, $this->height, $this->surfaceFormat);
    }

    private function createPipeline(): void
    {
        // Descriptor set layout: 2 UBOs + 2 combined image samplers (shadow + cloud)
        $this->descriptorSetLayout = new DescriptorSetLayout($this->device, [
            ['binding' => 0, 'descriptorType' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'stageFlags' => self::VK_SHADER_STAGE_VERTEX | self::VK_SHADER_STAGE_FRAGMENT],
            ['binding' => 1, 'descriptorType' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'stageFlags' => self::VK_SHADER_STAGE_FRAGMENT],
            ['binding' => 2, 'descriptorType' => self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER, 'stageFlags' => self::VK_SHADER_STAGE_FRAGMENT],
            ['binding' => 3, 'descriptorType' => self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER, 'stageFlags' => self::VK_SHADER_STAGE_FRAGMENT],
        ]);

        // Single push constant range visible to both vertex and fragment stages.
        // Split ranges caused issues with php-vulkan — unified range is safer.
        $this->pipelineLayout = new PipelineLayout(
            $this->device,
            [$this->descriptorSetLayout],
            [['stageFlags' => self::VK_SHADER_STAGE_VERTEX | self::VK_SHADER_STAGE_FRAGMENT, 'offset' => 0, 'size' => 128]],
        );
    }

    /**
     * Create opaque and transparent scene pipelines against the post-process HDR render pass.
     */
    private function createScenePipelines(): void
    {
        $vertModule = ShaderModule::createFromFile($this->device, self::VERT_SPV);
        $fragModule = ShaderModule::createFromFile($this->device, self::FRAG_SPV);

        // Dynamic rendering: specify formats directly
        $baseConfig = [
            'colorFormats' => [$this->surfaceFormat],
            'depthFormat' => self::VK_FORMAT_D32_SFLOAT,
            'layout' => $this->pipelineLayout,
            'vertexShader' => $vertModule,
            'fragmentShader' => $fragModule,
            'vertexBindings' => [
                ['binding' => 0, 'stride' => 32, 'inputRate' => self::VK_VERTEX_INPUT_RATE_VERTEX],
                ['binding' => 1, 'stride' => 64, 'inputRate' => self::VK_VERTEX_INPUT_RATE_INSTANCE],
            ],
            'vertexAttributes' => [
                ['location' => 0, 'binding' => 0, 'format' => self::VK_FORMAT_R32G32B32_SFLOAT, 'offset' => 0],
                ['location' => 1, 'binding' => 0, 'format' => self::VK_FORMAT_R32G32B32_SFLOAT, 'offset' => 12],
                ['location' => 2, 'binding' => 0, 'format' => self::VK_FORMAT_R32G32_SFLOAT, 'offset' => 24],
                ['location' => 3, 'binding' => 1, 'format' => self::VK_FORMAT_R32G32B32A32_SFLOAT, 'offset' => 0],
                ['location' => 4, 'binding' => 1, 'format' => self::VK_FORMAT_R32G32B32A32_SFLOAT, 'offset' => 16],
                ['location' => 5, 'binding' => 1, 'format' => self::VK_FORMAT_R32G32B32A32_SFLOAT, 'offset' => 32],
                ['location' => 6, 'binding' => 1, 'format' => self::VK_FORMAT_R32G32B32A32_SFLOAT, 'offset' => 48],
            ],
            'cullMode' => 0, // VK_CULL_MODE_NONE — matches OpenGL (glDisable(GL_CULL_FACE))
            'frontFace' => 0, // VK_FRONT_FACE_COUNTER_CLOCKWISE — Y-flip now via blitImage, winding preserved
        ];

        // Opaque pipeline: depth test+write ON, blend ON (alpha for semi-transparent like OpenGL)
        $this->opaquePipeline = Pipeline::createGraphics($this->device, array_merge($baseConfig, [
            'depthTest' => true,
            'depthWrite' => true,
            'blendEnable' => true,
            'srcColorBlendFactor' => self::VK_BLEND_FACTOR_SRC_ALPHA,
            'dstColorBlendFactor' => self::VK_BLEND_FACTOR_ONE_MINUS_SRC_ALPHA,
            'colorBlendOp' => self::VK_BLEND_OP_ADD,
            'srcAlphaBlendFactor' => self::VK_BLEND_FACTOR_SRC_ALPHA,
            'dstAlphaBlendFactor' => self::VK_BLEND_FACTOR_ONE_MINUS_SRC_ALPHA,
            'alphaBlendOp' => self::VK_BLEND_OP_ADD,
        ]));

        // Transparent pipeline: depth test ON, depth write OFF, blend ON
        $this->transparentPipeline = Pipeline::createGraphics($this->device, array_merge($baseConfig, [
            'depthTest' => true,
            'depthWrite' => false,
            'blendEnable' => true,
            'srcColorBlendFactor' => self::VK_BLEND_FACTOR_SRC_ALPHA,
            'dstColorBlendFactor' => self::VK_BLEND_FACTOR_ONE_MINUS_SRC_ALPHA,
            'colorBlendOp' => self::VK_BLEND_OP_ADD,
            'srcAlphaBlendFactor' => self::VK_BLEND_FACTOR_SRC_ALPHA,
            'dstAlphaBlendFactor' => self::VK_BLEND_FACTOR_ONE_MINUS_SRC_ALPHA,
            'alphaBlendOp' => self::VK_BLEND_OP_ADD,
        ]));
    }

    private function createUBOs(): void
    {
        // Create one UBO pair per swapchain image (triple-buffered)
        $imageCount = max(3, count($this->swapImages));
        for ($i = 0; $i < $imageCount; $i++) {
            $fUbo = new Buffer($this->device, self::FRAME_UBO_SIZE, self::VK_BUFFER_USAGE_UNIFORM, self::VK_SHARING_EXCLUSIVE);
            $req = $fUbo->getMemoryRequirements();
            $reqSize = $req['size'];
            if (!is_int($reqSize)) throw new \RuntimeException('Invalid frame UBO memory size');
            $fMem = new DeviceMemory($this->device, $reqSize, $this->findMemory($req, true));
            $fUbo->bindMemory($fMem, 0);
            $fMem->map(0, null);
            $this->frameUbos[$i] = $fUbo;
            $this->frameUboMems[$i] = $fMem;

            $lUbo = new Buffer($this->device, self::LIGHTING_UBO_SIZE, self::VK_BUFFER_USAGE_UNIFORM, self::VK_SHARING_EXCLUSIVE);
            $req2 = $lUbo->getMemoryRequirements();
            $req2Size = $req2['size'];
            if (!is_int($req2Size)) throw new \RuntimeException('Invalid lighting UBO memory size');
            $lMem = new DeviceMemory($this->device, $req2Size, $this->findMemory($req2, true));
            $lUbo->bindMemory($lMem, 0);
            $lMem->map(0, null);
            $this->lightingUbos[$i] = $lUbo;
            $this->lightingUboMems[$i] = $lMem;
        }
    }

    /**
     * @param callable(array<mixed>): int $findHostMemory
     * @param callable(array<mixed>): int $findDeviceMemory
     */
    private function createDummyImages(callable $findHostMemory, callable $findDeviceMemory): void
    {
        // Dummy shadow map: 1×1 D32 depth image
        $this->dummyShadowImage = new Image($this->device, 1, 1,
            self::VK_FORMAT_D32_SFLOAT, self::VK_IMAGE_USAGE_DEPTH | self::VK_IMAGE_USAGE_SAMPLED, 0, self::VK_SAMPLE_COUNT_1);
        $req = $this->dummyShadowImage->getMemoryRequirements();
        $size = $req['size'];
        if (!is_int($size)) throw new \RuntimeException('Invalid dummy shadow image memory');
        $this->dummyShadowMem = new DeviceMemory($this->device, $size, $findDeviceMemory($req));
        $this->dummyShadowImage->bindMemory($this->dummyShadowMem, 0);
        $this->dummyShadowView = new ImageView($this->device, $this->dummyShadowImage, self::VK_FORMAT_D32_SFLOAT, self::VK_ASPECT_DEPTH, 1);
        $this->dummyShadowSampler = new Sampler($this->device, [
            'magFilter' => self::VK_FILTER_LINEAR,
            'minFilter' => self::VK_FILTER_LINEAR,
            'addressModeU' => self::VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER,
            'addressModeV' => self::VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER,
            'borderColor' => self::VK_BORDER_COLOR_FLOAT_OPAQUE_WHITE,
            'compareEnable' => true,
            'compareOp' => self::VK_COMPARE_OP_LESS_OR_EQUAL,
        ]);

        // Dummy cloud shadow: 1×1 R8 color image
        $this->dummyCloudImage = new Image($this->device, 1, 1,
            self::VK_FORMAT_R8_UNORM, self::VK_IMAGE_USAGE_COLOR | self::VK_IMAGE_USAGE_SAMPLED, 0, self::VK_SAMPLE_COUNT_1);
        $req = $this->dummyCloudImage->getMemoryRequirements();
        $size = $req['size'];
        if (!is_int($size)) throw new \RuntimeException('Invalid dummy cloud image memory');
        $this->dummyCloudMem = new DeviceMemory($this->device, $size, $findDeviceMemory($req));
        $this->dummyCloudImage->bindMemory($this->dummyCloudMem, 0);
        $this->dummyCloudView = new ImageView($this->device, $this->dummyCloudImage, self::VK_FORMAT_R8_UNORM, self::VK_ASPECT_COLOR, 1);
        $this->dummyCloudSampler = new Sampler($this->device, [
            'magFilter' => self::VK_FILTER_LINEAR,
            'minFilter' => self::VK_FILTER_LINEAR,
            'addressModeU' => self::VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER,
            'addressModeV' => self::VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER,
            'borderColor' => self::VK_BORDER_COLOR_FLOAT_TRANSPARENT_BLACK,
        ]);

        // Note: Environment cubemap (binding 4) omitted — php-vulkan Image lacks arrayLayers for cubemaps.
        // When cubemap support is added to php-vulkan, add binding 4 back.
    }

    /**
     * Transition dummy images from UNDEFINED to shader-readable layouts.
     */
    private function transitionDummyImages(): void
    {
        $rawCmds = $this->commandPool->allocateBuffers(1, true);
        $oneShot = $rawCmds[0] ?? null;
        if (!$oneShot instanceof \Vk\CommandBuffer) return;

        $oneShot->begin(self::VK_CMD_ONE_TIME_SUBMIT);

        // Shadow: UNDEFINED → DEPTH_STENCIL_READ_ONLY_OPTIMAL
        $oneShot->imageMemoryBarrier(
            $this->dummyShadowImage,
            self::VK_LAYOUT_UNDEFINED,
            self::VK_LAYOUT_DEPTH_READ_ONLY,
            0,
            0x00000020, // VK_ACCESS_SHADER_READ_BIT
            self::VK_PIPELINE_STAGE_TOP,
            self::VK_PIPELINE_STAGE_FRAGMENT,
            self::VK_ASPECT_DEPTH,
        );

        // Cloud: UNDEFINED → SHADER_READ_ONLY_OPTIMAL
        $oneShot->imageMemoryBarrier(
            $this->dummyCloudImage,
            self::VK_LAYOUT_UNDEFINED,
            self::VK_LAYOUT_SHADER_READ,
            0,
            0x00000020,
            self::VK_PIPELINE_STAGE_TOP,
            self::VK_PIPELINE_STAGE_FRAGMENT,
            self::VK_ASPECT_COLOR,
        );

        $oneShot->end();
        $this->queue->submit([$oneShot], null, [], []);
        $this->device->waitIdle();
    }

    private function createDescriptors(): void
    {
        $imageCount = count($this->frameUbos);

        $this->descriptorPool = new DescriptorPool($this->device, $imageCount, [
            ['type' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'count' => $imageCount * 2],
            ['type' => self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER, 'count' => $imageCount * 2],
        ]);

        // Allocate one descriptor set per swapchain image
        $layouts = array_fill(0, $imageCount, $this->descriptorSetLayout);
        $rawSets = $this->descriptorPool->allocateSets($layouts);

        for ($i = 0; $i < $imageCount; $i++) {
            $set = $rawSets[$i] ?? null;
            if (!$set instanceof DescriptorSet) throw new \RuntimeException("Failed to allocate descriptor set {$i}");
            $this->descriptorSets[$i] = $set;
            $set->writeBuffer(0, $this->frameUbos[$i], 0, self::FRAME_UBO_SIZE, self::VK_DESCRIPTOR_UNIFORM_BUFFER);
            $set->writeBuffer(1, $this->lightingUbos[$i], 0, self::LIGHTING_UBO_SIZE, self::VK_DESCRIPTOR_UNIFORM_BUFFER);
            // Bind dummy shadow/cloud samplers (replaced with real ones after first shadow pass)
            $set->writeImage(2, self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER,
                $this->dummyShadowView, $this->dummyShadowSampler, self::VK_LAYOUT_DEPTH_READ_ONLY);
            $set->writeImage(3, self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER,
                $this->dummyCloudView, $this->dummyCloudSampler, self::VK_LAYOUT_SHADER_READ);
        }
    }

    private function createCommandObjects(): void
    {
        $this->commandPool = new CommandPool($this->device, $this->graphicsFamily, self::VK_CMD_POOL_RESET_CMD_BUFFER);
        $rawCmds = $this->commandPool->allocateBuffers(self::MAX_FRAMES_IN_FLIGHT, true);
        for ($i = 0; $i < self::MAX_FRAMES_IN_FLIGHT; $i++) {
            $cmd = $rawCmds[$i] ?? null;
            if (!$cmd instanceof \Vk\CommandBuffer) throw new \RuntimeException("Failed to allocate command buffer {$i}");
            $this->commandBuffers[$i] = $cmd;
        }
    }

    private function createSyncObjects(): void
    {
        for ($i = 0; $i < self::MAX_FRAMES_IN_FLIGHT; $i++) {
            $this->imageAvailableSems[$i] = new Semaphore($this->device, false, 0);
            $this->renderFinishedSems[$i] = new Semaphore($this->device, false, 0);
            $this->inFlightFences[$i] = new Fence($this->device, true);
        }
    }

    /** @param array<mixed> $memReqs */
    private function findMemory(array $memReqs, bool $hostVisible): int
    {
        $bitsRaw = $memReqs['memoryTypeBits'] ?? 0;
        $bits = is_int($bitsRaw) ? $bitsRaw : (int) $bitsRaw;

        foreach ($this->memTypes as $i => $t) {
            if (!($bits & (1 << $i))) continue;
            if ($hostVisible) {
                if (!empty($t['hostVisible']) && !empty($t['hostCoherent'])) return $i;
            } else {
                if (!empty($t['deviceLocal'])) return $i;
            }
        }
        throw new \RuntimeException('No suitable Vulkan memory type found');
    }

    private function ensureMacOSVulkanEnv(): void
    {
        if (PHP_OS_FAMILY !== 'Darwin') return;
        foreach (['/opt/homebrew/lib', '/usr/local/lib'] as $libDir) {
            if (file_exists("{$libDir}/libvulkan.dylib")) {
                $icd = dirname($libDir) . '/etc/vulkan/icd.d/MoltenVK_icd.json';
                if (!getenv('DYLD_LIBRARY_PATH')) putenv("DYLD_LIBRARY_PATH={$libDir}");
                if (!getenv('VK_ICD_FILENAMES') && file_exists($icd)) putenv("VK_ICD_FILENAMES={$icd}");
                return;
            }
        }
    }
}
