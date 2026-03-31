<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Mat4;
use PHPolygon\Rendering\Command\DrawMesh;
use Vk\Buffer;
use Vk\CommandBuffer;
use Vk\DescriptorPool;
use Vk\DescriptorSet;
use Vk\DescriptorSetLayout;
use Vk\Device;
use Vk\DeviceMemory;
use Vk\Framebuffer;
use Vk\Image;
use Vk\ImageView;
use Vk\Pipeline;
use Vk\PipelineLayout;
use Vk\RenderPass;
use Vk\Sampler;
use Vk\ShaderModule;

/**
 * Vulkan cloud shadow renderer — renders cloud opacity from sun's perspective.
 * Produces an R8 texture sampled by the main pass for soft cloud shadows.
 * Uses additive blending so overlapping clouds accumulate opacity.
 */
class VulkanCloudShadowRenderer
{
    private bool $initialized = false;

    private Image $colorImage;
    private DeviceMemory $colorImageMem;
    private ImageView $colorImageView;
    private Sampler $colorSampler;
    private Image $depthImage;
    private DeviceMemory $depthImageMem;
    private ImageView $depthImageView;
    private RenderPass $renderPass;
    private Framebuffer $framebuffer;
    private Pipeline $pipeline;
    private PipelineLayout $pipelineLayout;
    private DescriptorSetLayout $descriptorLayout;
    private DescriptorPool $descriptorPool;
    private DescriptorSet $descriptorSet;

    // Cloud UBO: light view (mat4) + light projection (mat4) = 128 bytes
    private Buffer $cloudUbo;
    private DeviceMemory $cloudUboMem;

    private const CLOUD_UBO_SIZE = 128;

    private const FORMAT_R8 = 9;       // VK_FORMAT_R8_UNORM
    private const FORMAT_D32 = 126;    // VK_FORMAT_D32_SFLOAT
    private const USAGE_COLOR = 16;    // VK_IMAGE_USAGE_COLOR_ATTACHMENT_BIT
    private const USAGE_DEPTH = 32;    // VK_IMAGE_USAGE_DEPTH_STENCIL_ATTACHMENT_BIT
    private const USAGE_SAMPLED = 4;   // VK_IMAGE_USAGE_SAMPLED_BIT
    private const ASPECT_COLOR = 1;
    private const ASPECT_DEPTH = 2;
    private const SAMPLE_COUNT_1 = 1;
    private const LOAD_CLEAR = 1;
    private const LOAD_DONT_CARE = 2;
    private const STORE_STORE = 0;
    private const STORE_DONT_CARE = 1;
    private const LAYOUT_UNDEFINED = 0;
    private const LAYOUT_COLOR_ATTACHMENT = 2;
    private const LAYOUT_DEPTH_ATTACHMENT = 3;
    private const LAYOUT_SHADER_READ = 5; // VK_IMAGE_LAYOUT_SHADER_READ_ONLY_OPTIMAL
    private const PIPELINE_BIND_GRAPHICS = 0;
    private const SHADER_STAGE_VERTEX = 1;
    private const CULL_BACK = 2;
    private const FRONT_CCW = 0;
    private const VK_FORMAT_R32G32B32_SFLOAT = 106;
    private const VK_FORMAT_R32G32B32A32_SFLOAT = 109;
    private const VK_FORMAT_R32G32_SFLOAT = 103;
    private const VK_BUFFER_USAGE_UNIFORM = 16;
    private const VK_SHARING_EXCLUSIVE = 0;
    private const VK_DESCRIPTOR_UNIFORM_BUFFER = 6;
    private const VK_FILTER_LINEAR = 1;
    private const VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER = 3;
    private const VK_BORDER_COLOR_FLOAT_TRANSPARENT_BLACK = 0;
    private const VK_INDEX_TYPE_UINT32 = 1;
    private const VK_VERTEX_INPUT_RATE_VERTEX = 0;
    private const VK_VERTEX_INPUT_RATE_INSTANCE = 1;
    private const VK_BLEND_FACTOR_ONE = 1;
    private const VK_BLEND_OP_ADD = 0;

    private const VERT_SPV = __DIR__ . '/../../resources/shaders/compiled/cloud_shadow_vk.vert.spv';
    private const FRAG_SPV = __DIR__ . '/../../resources/shaders/compiled/cloud_shadow_vk.frag.spv';

    public function __construct(
        private readonly Device $device,
        private readonly int $resolution = 1024,
    ) {}

    public function getColorImageView(): ImageView
    {
        return $this->colorImageView;
    }

    public function getColorSampler(): Sampler
    {
        return $this->colorSampler;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * @param callable(array<mixed>): int $findHostMemory
     * @param callable(array<mixed>): int $findDeviceMemory
     */
    public function initialize(callable $findHostMemory, callable $findDeviceMemory): void
    {
        if ($this->initialized) return;

        // R8 color image for cloud opacity
        $this->colorImage = new Image(
            $this->device, $this->resolution, $this->resolution,
            self::FORMAT_R8, self::USAGE_COLOR | self::USAGE_SAMPLED, 0, self::SAMPLE_COUNT_1,
        );
        $req = $this->colorImage->getMemoryRequirements();
        $size = $req['size'];
        if (!is_int($size)) throw new \RuntimeException('Invalid cloud color image memory');
        $this->colorImageMem = new DeviceMemory($this->device, $size, $findDeviceMemory($req));
        $this->colorImage->bindMemory($this->colorImageMem, 0);
        $this->colorImageView = new ImageView($this->device, $this->colorImage, self::FORMAT_R8, self::ASPECT_COLOR, 1);

        // Depth image for cloud depth sorting
        $this->depthImage = new Image(
            $this->device, $this->resolution, $this->resolution,
            self::FORMAT_D32, self::USAGE_DEPTH, 0, self::SAMPLE_COUNT_1,
        );
        $dReq = $this->depthImage->getMemoryRequirements();
        $dSize = $dReq['size'];
        if (!is_int($dSize)) throw new \RuntimeException('Invalid cloud depth image memory');
        $this->depthImageMem = new DeviceMemory($this->device, $dSize, $findDeviceMemory($dReq));
        $this->depthImage->bindMemory($this->depthImageMem, 0);
        $this->depthImageView = new ImageView($this->device, $this->depthImage, self::FORMAT_D32, self::ASPECT_DEPTH, 1);

        // Linear sampler with border = 0 (no cloud shadow outside map)
        $this->colorSampler = new Sampler($this->device, [
            'magFilter' => self::VK_FILTER_LINEAR,
            'minFilter' => self::VK_FILTER_LINEAR,
            'addressModeU' => self::VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER,
            'addressModeV' => self::VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER,
            'borderColor' => self::VK_BORDER_COLOR_FLOAT_TRANSPARENT_BLACK,
        ]);

        // Render pass: color (R8, additive blend) + depth
        $this->renderPass = new RenderPass($this->device, [
            [
                'format' => self::FORMAT_R8,
                'samples' => self::SAMPLE_COUNT_1,
                'loadOp' => self::LOAD_CLEAR,
                'storeOp' => self::STORE_STORE,
                'stencilLoadOp' => self::LOAD_DONT_CARE,
                'stencilStoreOp' => self::STORE_DONT_CARE,
                'initialLayout' => self::LAYOUT_UNDEFINED,
                'finalLayout' => self::LAYOUT_SHADER_READ,
            ],
            [
                'format' => self::FORMAT_D32,
                'samples' => self::SAMPLE_COUNT_1,
                'loadOp' => self::LOAD_CLEAR,
                'storeOp' => self::STORE_DONT_CARE,
                'stencilLoadOp' => self::LOAD_DONT_CARE,
                'stencilStoreOp' => self::STORE_DONT_CARE,
                'initialLayout' => self::LAYOUT_UNDEFINED,
                'finalLayout' => self::LAYOUT_DEPTH_ATTACHMENT,
            ],
        ], [
            [
                'pipelineBindPoint' => self::PIPELINE_BIND_GRAPHICS,
                'colorAttachments' => [['attachment' => 0, 'layout' => self::LAYOUT_COLOR_ATTACHMENT]],
                'depthAttachment' => ['attachment' => 1, 'layout' => self::LAYOUT_DEPTH_ATTACHMENT],
            ],
        ], []);

        $this->framebuffer = new Framebuffer(
            $this->device, $this->renderPass,
            [$this->colorImageView, $this->depthImageView],
            $this->resolution, $this->resolution, 1,
        );

        // Cloud UBO (light view + projection = 128 bytes)
        $this->cloudUbo = new Buffer(
            $this->device, self::CLOUD_UBO_SIZE,
            self::VK_BUFFER_USAGE_UNIFORM, self::VK_SHARING_EXCLUSIVE,
        );
        $uReq = $this->cloudUbo->getMemoryRequirements();
        $uSize = $uReq['size'];
        if (!is_int($uSize)) throw new \RuntimeException('Invalid cloud UBO memory');
        $this->cloudUboMem = new DeviceMemory($this->device, $uSize, $findHostMemory($uReq));
        $this->cloudUbo->bindMemory($this->cloudUboMem, 0);
        $this->cloudUboMem->map(0, null);

        // Descriptor set layout: binding 0 = UBO (vertex stage)
        $this->descriptorLayout = new DescriptorSetLayout($this->device, [
            ['binding' => 0, 'descriptorType' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'stageFlags' => self::SHADER_STAGE_VERTEX],
        ]);

        $this->descriptorPool = new DescriptorPool($this->device, 1, [
            ['type' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'count' => 1],
        ]);

        $rawSets = $this->descriptorPool->allocateSets([$this->descriptorLayout]);
        $set = $rawSets[0] ?? null;
        if (!$set instanceof DescriptorSet) throw new \RuntimeException('Failed to allocate cloud descriptor set');
        $this->descriptorSet = $set;
        $this->descriptorSet->writeBuffer(0, $this->cloudUbo, 0, self::CLOUD_UBO_SIZE, self::VK_DESCRIPTOR_UNIFORM_BUFFER);

        // Cloud shadow pipeline — with additive blending
        $vertModule = ShaderModule::createFromFile($this->device, self::VERT_SPV);
        $fragModule = ShaderModule::createFromFile($this->device, self::FRAG_SPV);

        $this->pipelineLayout = new PipelineLayout(
            $this->device,
            [$this->descriptorLayout],
            // 68 bytes: mat4 model (64) + float opacity (4)
            [['stageFlags' => self::SHADER_STAGE_VERTEX, 'offset' => 0, 'size' => 68]],
        );

        $this->pipeline = Pipeline::createGraphics($this->device, [
            'renderPass' => $this->renderPass,
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
            'cullMode' => 0, // VK_CULL_MODE_NONE — clouds are often back-facing
            'frontFace' => self::FRONT_CCW,
            'depthTestEnable' => true,
            'depthWriteEnable' => true,
            'blendEnable' => true,
            'srcColorBlendFactor' => self::VK_BLEND_FACTOR_ONE,
            'dstColorBlendFactor' => self::VK_BLEND_FACTOR_ONE,
            'colorBlendOp' => self::VK_BLEND_OP_ADD,
            'srcAlphaBlendFactor' => self::VK_BLEND_FACTOR_ONE,
            'dstAlphaBlendFactor' => self::VK_BLEND_FACTOR_ONE,
            'alphaBlendOp' => self::VK_BLEND_OP_ADD,
        ]);

        $this->initialized = true;
    }

    /**
     * Record cloud shadow rendering commands into the given command buffer.
     *
     * @param CommandBuffer $cb Active command buffer
     * @param Mat4 $lightSpaceMatrix Light-space matrix from shadow map renderer
     * @param array<string, array{vb: Buffer, vbMem: DeviceMemory, ib: Buffer, ibMem: DeviceMemory, count: int}> $meshCache
     * @param array<array{cmd: DrawMesh, opacity: float}> $cloudDraws Pre-filtered cloud draws with computed opacity
     */
    public function recordCloudPass(
        CommandBuffer $cb,
        Mat4 $lightSpaceMatrix,
        array $meshCache,
        array $cloudDraws,
    ): void {
        if (!$this->initialized || empty($cloudDraws)) return;

        // Upload light-space matrix as view, identity as projection
        $lightView = $lightSpaceMatrix->toArray();
        $identity = Mat4::identity()->toArray();
        $data = pack('f16', ...$lightView) . pack('f16', ...$identity);
        $this->cloudUboMem->write($data, 0);

        // Begin cloud shadow render pass
        $cb->beginRenderPass(
            $this->renderPass,
            $this->framebuffer,
            0, 0, $this->resolution, $this->resolution,
            [[0.0, 0.0, 0.0, 0.0], [1.0, 0]], // clear color=0 (no shadow), depth=1.0
        );
        $cb->setViewport(0.0, 0.0, (float) $this->resolution, (float) $this->resolution, 0.0, 1.0);
        $cb->setScissor(0, 0, $this->resolution, $this->resolution);
        $cb->bindPipeline(self::PIPELINE_BIND_GRAPHICS, $this->pipeline);
        $cb->bindDescriptorSets(self::PIPELINE_BIND_GRAPHICS, $this->pipelineLayout, 0, [$this->descriptorSet]);

        foreach ($cloudDraws as $draw) {
            $cmd = $draw['cmd'];
            $opacity = $draw['opacity'];

            if (!isset($meshCache[$cmd->meshId])) continue;
            $cache = $meshCache[$cmd->meshId];

            // Push constants: mat4 model (64 bytes) + float opacity (4 bytes) = 68 bytes
            $pushData = pack('f16', ...$cmd->modelMatrix->toArray()) . pack('f', $opacity);
            $cb->pushConstants($this->pipelineLayout, self::SHADER_STAGE_VERTEX, 0, $pushData);
            $cb->bindVertexBuffers(0, [$cache['vb']], [0]);
            $cb->bindIndexBuffer($cache['ib'], 0, self::VK_INDEX_TYPE_UINT32);
            $cb->drawIndexed($cache['count'], 1, 0, 0, 0);
        }

        $cb->endRenderPass();
    }
}
