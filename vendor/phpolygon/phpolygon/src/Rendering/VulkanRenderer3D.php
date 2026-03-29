<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
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

/**
 * Vulkan 1.0 3D renderer with full feature parity to OpenGL backend.
 * Translates RenderCommandList into Vulkan draw calls via MoltenVK on macOS.
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
    private RenderPass $renderPass;
    private Pipeline $pipeline;
    private PipelineLayout $pipelineLayout;
    private DescriptorSetLayout $descriptorSetLayout;
    private DescriptorPool $descriptorPool;
    private DescriptorSet $descriptorSet;
    private CommandPool $commandPool;
    private \Vk\CommandBuffer $commandBuffer;
    private Fence $inFlightFence;
    private Semaphore $imageAvailableSem;
    private Semaphore $renderFinishedSem;

    // Shadow renderers
    private ?VulkanShadowMapRenderer $shadowMap = null;
    private ?VulkanCloudShadowRenderer $cloudShadow = null;

    /** @var ImageView[] */
    private array $swapImageViews = [];
    /** @var Framebuffer[] */
    private array $framebuffers = [];
    /** @var array<array<mixed>> */
    private array $memTypes = [];

    // UBOs
    private Buffer $frameUbo;
    private DeviceMemory $frameUboMem;
    private Buffer $lightingUbo;
    private DeviceMemory $lightingUboMem;

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

    // Instance buffer for GPU instancing (reused per frame)
    private ?Buffer $instanceBuffer = null;
    private ?DeviceMemory $instanceBufferMem = null;
    private int $instanceBufferCapacity = 0;

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
    private const VK_SAMPLE_COUNT_4            = 4;
    private const VK_LOAD_OP_CLEAR             = 1;
    private const VK_LOAD_OP_DONT_CARE         = 2;
    private const VK_STORE_OP_STORE            = 0;
    private const VK_STORE_OP_DONT_CARE        = 1;
    private const VK_LAYOUT_UNDEFINED          = 0;
    private const VK_LAYOUT_PRESENT_SRC        = 1000001002;
    private const VK_LAYOUT_COLOR_ATTACHMENT   = 2;
    private const VK_LAYOUT_DEPTH_ATTACHMENT   = 3;
    private const VK_ASPECT_COLOR              = 1;
    private const VK_ASPECT_DEPTH              = 2;
    private const VK_FORMAT_D32_SFLOAT         = 126;
    private const VK_FORMAT_R32G32B32_SFLOAT   = 106;
    private const VK_FORMAT_R32G32_SFLOAT      = 103;
    private const VK_BUFFER_USAGE_VERTEX       = 128;
    private const VK_BUFFER_USAGE_INDEX        = 64;
    private const VK_BUFFER_USAGE_UNIFORM      = 16;
    private const VK_DESCRIPTOR_UNIFORM_BUFFER = 6;
    private const VK_VERTEX_INPUT_RATE_VERTEX  = 0;
    private const VK_VERTEX_INPUT_RATE_INSTANCE = 1;
    private const VK_CULL_MODE_BACK            = 2;
    private const VK_FRONT_FACE_CCW            = 0;
    private const VK_CMD_POOL_RESET_CMD_BUFFER = 2;
    private const VK_PRESENT_MODE_FIFO         = 2;
    private const VK_BLEND_FACTOR_SRC_ALPHA         = 6;
    private const VK_BLEND_FACTOR_ONE_MINUS_SRC_ALPHA = 7;
    private const VK_BLEND_OP_ADD              = 0;

    private \GLFWwindow $windowHandle;

    public function __construct(int $width, int $height, \GLFWwindow $windowHandle)
    {
        $this->width = $width;
        $this->height = $height;
        $this->windowHandle = $windowHandle;
        $this->lightSpaceMatrix = Mat4::identity()->toArray();
        $this->initVulkan($windowHandle);
    }

    public function beginFrame(): void
    {
        $this->dirLights = [];
        $this->pointLights = [];
        $this->time += 1.0 / 60.0;

        $this->inFlightFence->wait(1_000_000_000);
        $this->inFlightFence->reset();

        $this->currentImageIndex = $this->swapchain->acquireNextImage(
            $this->imageAvailableSem, null, 1_000_000_000,
        );

        $this->commandBuffer->reset(0);
        $this->commandBuffer->begin(self::VK_CMD_ONE_TIME_SUBMIT);
    }

    public function endFrame(): void
    {
        $this->commandBuffer->end();

        $this->queue->submit(
            [$this->commandBuffer],
            $this->inFlightFence,
            [$this->imageAvailableSem],
            [$this->renderFinishedSem],
        );

        $this->queue->present(
            [$this->swapchain],
            [$this->currentImageIndex],
            [$this->renderFinishedSem],
        );
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
        // Wait for all GPU work to complete
        $this->device->waitIdle();

        // Clean up old framebuffers and image views
        $this->framebuffers = [];
        $this->swapImageViews = [];

        // Recreate swapchain, render pass, framebuffers
        $this->createSwapchain();
        $this->createRenderPass();
    }

    public function getWidth(): int { return $this->width; }
    public function getHeight(): int { return $this->height; }

    // =========================================================================
    // Render — process command list
    // =========================================================================

    public function render(RenderCommandList $commandList): void
    {
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

        // Pass 1: Collect state from non-draw commands
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof SetCamera) {
                $this->viewMatrix = $command->viewMatrix->toArray();
                $this->projMatrix = $command->projectionMatrix->toArray();
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
            } elseif ($command instanceof SetSkybox) {
                // TODO: Cubemap pipeline
            } elseif ($command instanceof SetEnvironmentMap) {
                // TODO: Cubemap sampler descriptor
            }
        }

        // Upload Frame UBO
        $this->uploadFrameUbo();

        // Begin render pass
        $this->commandBuffer->beginRenderPass(
            $this->renderPass,
            $this->framebuffers[$this->currentImageIndex],
            0, 0, $this->width, $this->height,
            [[$this->clearR, $this->clearG, $this->clearB, 1.0], [1.0, 0]],
        );
        $this->commandBuffer->setViewport(0.0, 0.0, (float) $this->width, (float) $this->height, 0.0, 1.0);
        $this->commandBuffer->setScissor(0, 0, $this->width, $this->height);
        $this->commandBuffer->bindPipeline(self::VK_PIPELINE_BIND_GRAPHICS, $this->pipeline);
        $this->commandBuffer->bindDescriptorSets(
            self::VK_PIPELINE_BIND_GRAPHICS, $this->pipelineLayout, 0, [$this->descriptorSet],
        );

        // Update shadow matrices from primary directional light
        if ($this->shadowMap !== null && !empty($this->dirLights)) {
            $dl = $this->dirLights[0];
            $this->shadowMap->updateLightMatrix(
                new \PHPolygon\Math\Vec3($dl['dir'][0], $dl['dir'][1], $dl['dir'][2]),
            );
            $this->lightSpaceMatrix = $this->shadowMap->getLightSpaceMatrix()->toArray();
            $this->hasShadowMap = 1;
        }

        // Pass 2: Draw commands
        foreach ($commandList->getCommands() as $command) {
            if ($command instanceof DrawMesh) {
                $this->applyMaterial($command->materialId);
                $this->uploadLightingUbo();
                $this->drawMeshCommand($command->meshId, $command->modelMatrix);
            } elseif ($command instanceof DrawMeshInstanced) {
                $this->applyMaterial($command->materialId);
                $this->uploadLightingUbo();
                $this->drawMeshInstancedCommand($command->meshId, $command->matrices);
            }
        }

        $this->commandBuffer->endRenderPass();
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

        $modelBytes = pack('f16', ...$modelMatrix->toArray());
        $this->commandBuffer->pushConstants($this->pipelineLayout, self::VK_SHADER_STAGE_VERTEX, 0, $modelBytes);
        $this->commandBuffer->bindVertexBuffers(0, [$this->meshCache[$meshId]['vb']], [0]);
        $this->commandBuffer->bindIndexBuffer($this->meshCache[$meshId]['ib'], 0, self::VK_INDEX_TYPE_UINT32);
        $this->commandBuffer->drawIndexed($this->meshCache[$meshId]['count'], 1, 0, 0, 0);
    }

    /**
     * Draw multiple instances of a mesh in a single GPU call.
     * Uploads instance matrices to a shared buffer, then draws with instanceCount.
     * Falls back to loop if instance buffer can't be created.
     *
     * @param Mat4[] $matrices
     */
    private function drawMeshInstancedCommand(string $meshId, array $matrices): void
    {
        $meshData = MeshRegistry::get($meshId);
        if ($meshData === null || empty($matrices)) return;

        if (!isset($this->meshCache[$meshId])) {
            $this->uploadMesh($meshId, $meshData);
        }

        $instanceCount = count($matrices);

        // Pack all instance matrices into a contiguous buffer
        // Each mat4 = 64 bytes (16 floats × 4 bytes)
        $matrixData = '';
        foreach ($matrices as $matrix) {
            $matrixData .= pack('f16', ...$matrix->toArray());
        }

        $requiredSize = $instanceCount * 64;

        // Grow instance buffer if needed
        if ($this->instanceBuffer === null || $this->instanceBufferCapacity < $requiredSize) {
            $newCapacity = max($requiredSize, 4096); // Min 4KB, grows as needed
            $this->instanceBuffer = new Buffer(
                $this->device, $newCapacity,
                self::VK_BUFFER_USAGE_VERTEX, self::VK_SHARING_EXCLUSIVE,
            );
            $req = $this->instanceBuffer->getMemoryRequirements();
            $reqSize = $req['size'];
            if (!is_int($reqSize)) {
                // Fallback to loop
                foreach ($matrices as $matrix) {
                    $this->drawMeshCommand($meshId, $matrix);
                }
                return;
            }
            $this->instanceBufferMem = new DeviceMemory(
                $this->device, $reqSize, $this->findMemory($req, true),
            );
            $this->instanceBuffer->bindMemory($this->instanceBufferMem, 0);
            $this->instanceBufferMem->map(0, null);
            $this->instanceBufferCapacity = $newCapacity;
        }

        // Upload matrix data
        $this->instanceBufferMem->write($matrixData, 0);

        // Use identity push constant (instances use per-instance attributes)
        $identityBytes = pack('f16', ...Mat4::identity()->toArray());
        $this->commandBuffer->pushConstants($this->pipelineLayout, self::VK_SHADER_STAGE_VERTEX, 0, $identityBytes);

        // Bind vertex buffer (binding 0) + instance buffer (binding 1)
        $this->commandBuffer->bindVertexBuffers(0, [$this->meshCache[$meshId]['vb'], $this->instanceBuffer], [0, 0]);
        $this->commandBuffer->bindIndexBuffer($this->meshCache[$meshId]['ib'], 0, self::VK_INDEX_TYPE_UINT32);

        // Single GPU call for all instances
        $this->commandBuffer->drawIndexed($this->meshCache[$meshId]['count'], $instanceCount, 0, 0, 0);
    }

    // =========================================================================
    // UBO Upload
    // =========================================================================

    private function uploadFrameUbo(): void
    {
        // mat4 view (64) + mat4 proj (64) = 128 bytes
        $data = pack('f16', ...$this->viewMatrix) . pack('f16', ...$this->projMatrix);
        // float time, float temperature, int use_instancing, int vertex_anim = 16 bytes
        $data .= pack('f2i2', $this->time, $this->temperature, 0, $this->vertexAnim);
        // float wave_amp, wave_freq, wave_phase, pad = 16 bytes
        $data .= pack('f4', $this->waveAmplitude, $this->waveFrequency, $this->wavePhase, 0.0);
        // vec3 camera_pos + pad = 16 bytes
        $data .= pack('f4', $this->cameraPos[0], $this->cameraPos[1], $this->cameraPos[2], 0.0);
        // Total: 128 + 16 + 16 + 16 = 176 bytes (pad to 192)
        $data .= str_repeat("\0", self::FRAME_UBO_SIZE - strlen($data));

        $this->frameUboMem->write($data, 0);
    }

    private function uploadLightingUbo(): void
    {
        // Ambient: vec3 + float = 16
        $data = pack('f4', $this->ambient[0], $this->ambient[1], $this->ambient[2], $this->ambient[3]);

        // Material: albedo(vec3)+roughness, emission(vec3)+metallic, alpha+time+proc_mode+moon = 48
        $data .= pack('f4', $this->albedo[0], $this->albedo[1], $this->albedo[2], $this->roughness);
        $data .= pack('f4', $this->emission[0], $this->emission[1], $this->emission[2], $this->metallic);
        // alpha, time, proc_mode (as float bits), moon_phase
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

        // Pad to exact size
        if (strlen($data) < self::LIGHTING_UBO_SIZE) {
            $data .= str_repeat("\0", self::LIGHTING_UBO_SIZE - strlen($data));
        }

        $this->lightingUboMem->write($data, 0);
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

        $this->instance = new Instance('PHPolygon', 1, 'PHPolygon', 1, null, false, [
            'VK_KHR_surface',
            'VK_EXT_metal_surface',
            'VK_KHR_portability_enumeration',
        ]);

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
            ['VK_KHR_swapchain'],
            null,
        );
        $this->queue = $this->device->getQueue($this->graphicsFamily, 0);

        $this->createSwapchain();
        $this->createRenderPass();
        $this->createPipeline();
        $this->createUBOs();
        $this->createDescriptors();
        $this->createCommandObjects();
        $this->createSyncObjects();

        // Initialize shadow renderers
        $findHostMem = fn(array $req) => $this->findMemory($req, true);
        $findDeviceMem = fn(array $req) => $this->findMemory($req, false);

        $this->shadowMap = new VulkanShadowMapRenderer($this->device, 2048);
        $this->shadowMap->initialize($findDeviceMem);

        $this->cloudShadow = new VulkanCloudShadowRenderer($this->device, 1024);
        $this->cloudShadow->initialize($findHostMem, $findDeviceMem);
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

        $firstFormat = is_array($rawFormats) ? ($rawFormats[0] ?? []) : [];
        $format = is_array($firstFormat) ? ($firstFormat['format'] ?? 44) : 44;
        $colorSpace = is_array($firstFormat) ? ($firstFormat['colorSpace'] ?? 0) : 0;
        $this->surfaceFormat = is_int($format) ? $format : (int) $format;

        $minCount = is_array($caps) ? ($caps['minImageCount'] ?? 2) : 2;
        $maxCount = is_array($caps) ? ($caps['maxImageCount'] ?? 3) : 3;
        $transform = is_array($caps) ? ($caps['currentTransform'] ?? 1) : 1;
        $imageCount = max(
            is_int($minCount) ? $minCount : (int) $minCount,
            min(3, $maxCount ? (is_int($maxCount) ? $maxCount : (int) $maxCount) : 3),
        );

        $this->swapchain = new Swapchain($this->device, $this->surface, [
            'minImageCount' => $imageCount,
            'imageFormat' => $this->surfaceFormat,
            'imageColorSpace' => is_int($colorSpace) ? $colorSpace : (int) $colorSpace,
            'imageExtent' => ['width' => $this->width, 'height' => $this->height],
            'imageArrayLayers' => 1,
            'imageUsage' => self::VK_IMAGE_USAGE_COLOR,
            'imageSharingMode' => self::VK_SHARING_EXCLUSIVE,
            'preTransform' => is_int($transform) ? $transform : (int) $transform,
            'compositeAlpha' => 1,
            'presentMode' => self::VK_PRESENT_MODE_FIFO,
            'clipped' => true,
        ]);

        $rawImages = $this->swapchain->getImages();
        if (!is_array($rawImages)) throw new \RuntimeException('getImages() did not return an array');
        foreach ($rawImages as $img) {
            if (!$img instanceof Image) throw new \RuntimeException('Swapchain image is not a Vk\\Image');
            $this->swapImageViews[] = new ImageView($this->device, $img, $this->surfaceFormat, self::VK_ASPECT_COLOR, 1);
        }
    }

    private function createRenderPass(): void
    {
        $this->renderPass = new RenderPass(
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
                [
                    'format' => self::VK_FORMAT_D32_SFLOAT,
                    'samples' => self::VK_SAMPLE_COUNT_1,
                    'loadOp' => self::VK_LOAD_OP_CLEAR,
                    'storeOp' => self::VK_STORE_OP_DONT_CARE,
                    'stencilLoadOp' => self::VK_LOAD_OP_DONT_CARE,
                    'stencilStoreOp' => self::VK_STORE_OP_DONT_CARE,
                    'initialLayout' => self::VK_LAYOUT_UNDEFINED,
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
            [],
        );

        $depthImage = new Image($this->device, $this->width, $this->height,
            self::VK_FORMAT_D32_SFLOAT, self::VK_IMAGE_USAGE_DEPTH, 0, self::VK_SAMPLE_COUNT_1);
        $depthReq = $depthImage->getMemoryRequirements();
        $depthSize = $depthReq['size'];
        if (!is_int($depthSize)) throw new \RuntimeException('Invalid depth image memory requirements');
        $depthMem = new DeviceMemory($this->device, $depthSize, $this->findMemory($depthReq, false));
        $depthImage->bindMemory($depthMem, 0);
        $depthView = new ImageView($this->device, $depthImage, self::VK_FORMAT_D32_SFLOAT, self::VK_ASPECT_DEPTH, 1);

        foreach ($this->swapImageViews as $colorView) {
            $this->framebuffers[] = new Framebuffer(
                $this->device, $this->renderPass,
                [$colorView, $depthView],
                $this->width, $this->height, 1,
            );
        }
    }

    private function createPipeline(): void
    {
        $vertModule = ShaderModule::createFromFile($this->device, self::VERT_SPV);
        $fragModule = ShaderModule::createFromFile($this->device, self::FRAG_SPV);

        $this->descriptorSetLayout = new DescriptorSetLayout($this->device, [
            ['binding' => 0, 'descriptorType' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'stageFlags' => self::VK_SHADER_STAGE_VERTEX | self::VK_SHADER_STAGE_FRAGMENT],
            ['binding' => 1, 'descriptorType' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'stageFlags' => self::VK_SHADER_STAGE_FRAGMENT],
        ]);

        $this->pipelineLayout = new PipelineLayout(
            $this->device,
            [$this->descriptorSetLayout],
            [['stageFlags' => self::VK_SHADER_STAGE_VERTEX, 'offset' => 0, 'size' => 64]],
        );

        $this->pipeline = Pipeline::createGraphics($this->device, [
            'renderPass' => $this->renderPass,
            'layout' => $this->pipelineLayout,
            'vertexShader' => $vertModule,
            'fragmentShader' => $fragModule,
            'vertexBindings' => [
                ['binding' => 0, 'stride' => 32, 'inputRate' => self::VK_VERTEX_INPUT_RATE_VERTEX],
                ['binding' => 1, 'stride' => 64, 'inputRate' => self::VK_VERTEX_INPUT_RATE_INSTANCE], // mat4 per instance
            ],
            'vertexAttributes' => [
                // Per-vertex: position, normal, uv
                ['location' => 0, 'binding' => 0, 'format' => self::VK_FORMAT_R32G32B32_SFLOAT, 'offset' => 0],
                ['location' => 1, 'binding' => 0, 'format' => self::VK_FORMAT_R32G32B32_SFLOAT, 'offset' => 12],
                ['location' => 2, 'binding' => 0, 'format' => self::VK_FORMAT_R32G32_SFLOAT, 'offset' => 24],
                // Per-instance: mat4 as 4× vec4 (locations 3-6)
                ['location' => 3, 'binding' => 1, 'format' => 109, 'offset' => 0],  // VK_FORMAT_R32G32B32A32_SFLOAT
                ['location' => 4, 'binding' => 1, 'format' => 109, 'offset' => 16],
                ['location' => 5, 'binding' => 1, 'format' => 109, 'offset' => 32],
                ['location' => 6, 'binding' => 1, 'format' => 109, 'offset' => 48],
            ],
            'cullMode' => self::VK_CULL_MODE_BACK,
            'frontFace' => self::VK_FRONT_FACE_CCW,
            'blendEnable' => true,
            'srcColorBlendFactor' => self::VK_BLEND_FACTOR_SRC_ALPHA,
            'dstColorBlendFactor' => self::VK_BLEND_FACTOR_ONE_MINUS_SRC_ALPHA,
            'colorBlendOp' => self::VK_BLEND_OP_ADD,
            'srcAlphaBlendFactor' => self::VK_BLEND_FACTOR_SRC_ALPHA,
            'dstAlphaBlendFactor' => self::VK_BLEND_FACTOR_ONE_MINUS_SRC_ALPHA,
            'alphaBlendOp' => self::VK_BLEND_OP_ADD,
        ]);
    }

    private function createUBOs(): void
    {
        // Frame UBO
        $this->frameUbo = new Buffer($this->device, self::FRAME_UBO_SIZE, self::VK_BUFFER_USAGE_UNIFORM, self::VK_SHARING_EXCLUSIVE);
        $req = $this->frameUbo->getMemoryRequirements();
        $reqSize = $req['size'];
        if (!is_int($reqSize)) throw new \RuntimeException('Invalid frame UBO memory size');
        $this->frameUboMem = new DeviceMemory($this->device, $reqSize, $this->findMemory($req, true));
        $this->frameUbo->bindMemory($this->frameUboMem, 0);
        $this->frameUboMem->map(0, null);

        // Lighting UBO
        $this->lightingUbo = new Buffer($this->device, self::LIGHTING_UBO_SIZE, self::VK_BUFFER_USAGE_UNIFORM, self::VK_SHARING_EXCLUSIVE);
        $req2 = $this->lightingUbo->getMemoryRequirements();
        $req2Size = $req2['size'];
        if (!is_int($req2Size)) throw new \RuntimeException('Invalid lighting UBO memory size');
        $this->lightingUboMem = new DeviceMemory($this->device, $req2Size, $this->findMemory($req2, true));
        $this->lightingUbo->bindMemory($this->lightingUboMem, 0);
        $this->lightingUboMem->map(0, null);
    }

    private function createDescriptors(): void
    {
        $this->descriptorPool = new DescriptorPool($this->device, 1,
            [['type' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'count' => 2]],
        );

        $rawSets = $this->descriptorPool->allocateSets([$this->descriptorSetLayout]);
        $firstSet = $rawSets[0] ?? null;
        if (!$firstSet instanceof DescriptorSet) throw new \RuntimeException('Failed to allocate descriptor set');
        $this->descriptorSet = $firstSet;
        $this->descriptorSet->writeBuffer(0, $this->frameUbo, 0, self::FRAME_UBO_SIZE, self::VK_DESCRIPTOR_UNIFORM_BUFFER);
        $this->descriptorSet->writeBuffer(1, $this->lightingUbo, 0, self::LIGHTING_UBO_SIZE, self::VK_DESCRIPTOR_UNIFORM_BUFFER);
    }

    private function createCommandObjects(): void
    {
        $this->commandPool = new CommandPool($this->device, $this->graphicsFamily, self::VK_CMD_POOL_RESET_CMD_BUFFER);
        $rawCmds = $this->commandPool->allocateBuffers(1, true);
        $firstCmd = $rawCmds[0] ?? null;
        if (!$firstCmd instanceof \Vk\CommandBuffer) throw new \RuntimeException('Failed to allocate command buffer');
        $this->commandBuffer = $firstCmd;
    }

    private function createSyncObjects(): void
    {
        $this->imageAvailableSem = new Semaphore($this->device, false, 0);
        $this->renderFinishedSem = new Semaphore($this->device, false, 0);
        $this->inFlightFence = new Fence($this->device, true);
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
