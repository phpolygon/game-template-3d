<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Vec3;
use Vk\Buffer;
use Vk\CommandPool;
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
 * Vulkan post-processing pipeline.
 * Renders scene to offscreen HDR image, then applies fullscreen post-processing
 * (SSAO, Bloom, God Rays, DOF, ACES Tone Mapping) via a second render pass.
 */
class VulkanPostProcessPipeline
{
    private bool $initialized = false;

    // Offscreen scene capture
    private Image $colorImage;
    private DeviceMemory $colorImageMem;
    private ImageView $colorImageView;
    private Image $depthImage;
    private DeviceMemory $depthImageMem;
    private ImageView $depthImageView;
    private Sampler $colorSampler;
    private Sampler $depthSampler;
    private RenderPass $sceneRenderPass;
    private Framebuffer $sceneFbo;

    // Post-process pass
    private Pipeline $postPipeline;
    private PipelineLayout $postPipelineLayout;
    private DescriptorSetLayout $postDescriptorLayout;
    private DescriptorPool $postDescriptorPool;
    private DescriptorSet $postDescriptorSet;

    // UBO for post-process parameters
    private Buffer $postUbo;
    private DeviceMemory $postUboMem;

    // Effect toggles
    private bool $ssaoEnabled = true;
    private bool $bloomEnabled = true;
    private bool $godRaysEnabled = true;
    private bool $dofEnabled = false;
    private float $dofFocusDistance = 15.0;
    private float $dofRange = 10.0;
    private Vec3 $sunDirection;
    private float $sunIntensity = 1.0;
    private float $time = 0.0;

    // Vulkan constants
    private const FORMAT_RGBA16F = 97;  // VK_FORMAT_R16G16B16A16_SFLOAT
    private const FORMAT_D32 = 126;     // VK_FORMAT_D32_SFLOAT
    private const USAGE_COLOR = 16;
    private const USAGE_DEPTH = 32;
    private const USAGE_SAMPLED = 4;
    private const USAGE_INPUT = 128;    // VK_IMAGE_USAGE_INPUT_ATTACHMENT_BIT
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
    private const LAYOUT_SHADER_READ = 5;
    private const PIPELINE_BIND_GRAPHICS = 0;
    private const SHADER_STAGE_FRAGMENT = 16;
    private const SHADER_STAGE_VERTEX = 1;
    private const VK_FILTER_LINEAR = 1;
    private const VK_FILTER_NEAREST = 0;
    private const VK_SAMPLER_ADDRESS_CLAMP = 2;
    private const VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER = 1;
    private const VK_DESCRIPTOR_UNIFORM_BUFFER = 6;
    private const VK_BUFFER_USAGE_UNIFORM = 16;
    private const VK_SHARING_EXCLUSIVE = 0;
    private const POST_UBO_SIZE = 64; // vec3+float + vec3+float + 4 ints + 2 floats = 64 bytes

    private const VERT_SPV = __DIR__ . '/../../resources/shaders/compiled/postprocess_vk.vert.spv';
    private const FRAG_SPV = __DIR__ . '/../../resources/shaders/compiled/postprocess_vk.frag.spv';

    public function __construct(
        private readonly Device $device,
        private int $width,
        private int $height,
    ) {
        $this->sunDirection = new Vec3(0.0, -1.0, 0.0);
    }

    public function isInitialized(): bool { return $this->initialized; }
    public function setSSAO(bool $enabled): void { $this->ssaoEnabled = $enabled; }
    public function setBloom(bool $enabled): void { $this->bloomEnabled = $enabled; }
    public function setGodRays(bool $enabled): void { $this->godRaysEnabled = $enabled; }

    public function setDOF(bool $enabled, float $focus = 15.0, float $range = 10.0): void
    {
        $this->dofEnabled = $enabled;
        $this->dofFocusDistance = $focus;
        $this->dofRange = $range;
    }

    public function setSunData(Vec3 $direction, float $intensity): void
    {
        $this->sunDirection = $direction;
        $this->sunIntensity = $intensity;
    }

    public function advanceTime(float $dt): void
    {
        $this->time += $dt;
    }

    public function getSceneRenderPass(): RenderPass { return $this->sceneRenderPass; }
    public function getSceneFramebuffer(): Framebuffer { return $this->sceneFbo; }

    /**
     * @param callable(array<mixed>): int $findHostMemory
     * @param callable(array<mixed>): int $findDeviceMemory
     */
    /**
     * @param callable(array<mixed>): int $findHostMemory
     * @param callable(array<mixed>): int $findDeviceMemory
     */
    public function initialize(
        callable $findHostMemory,
        callable $findDeviceMemory,
        RenderPass $presentRenderPass,
        ?\Vk\CommandPool $commandPool = null,
        ?\Vk\Queue $queue = null,
    ): void {
        if ($this->initialized) return;

        // HDR color image
        $this->colorImage = new Image($this->device, $this->width, $this->height,
            self::FORMAT_RGBA16F, self::USAGE_COLOR | self::USAGE_SAMPLED, 0, self::SAMPLE_COUNT_1);
        $req = $this->colorImage->getMemoryRequirements();
        $size = $req['size'];
        if (!is_int($size)) throw new \RuntimeException('Invalid post-process color image memory');
        $this->colorImageMem = new DeviceMemory($this->device, $size, $findDeviceMemory($req));
        $this->colorImage->bindMemory($this->colorImageMem, 0);
        $this->colorImageView = new ImageView($this->device, $this->colorImage, self::FORMAT_RGBA16F, self::ASPECT_COLOR, 1);

        // Depth image
        $this->depthImage = new Image($this->device, $this->width, $this->height,
            self::FORMAT_D32, self::USAGE_DEPTH | self::USAGE_SAMPLED, 0, self::SAMPLE_COUNT_1);
        $dReq = $this->depthImage->getMemoryRequirements();
        $dSize = $dReq['size'];
        if (!is_int($dSize)) throw new \RuntimeException('Invalid post-process depth image memory');
        $this->depthImageMem = new DeviceMemory($this->device, $dSize, $findDeviceMemory($dReq));
        $this->depthImage->bindMemory($this->depthImageMem, 0);
        $this->depthImageView = new ImageView($this->device, $this->depthImage, self::FORMAT_D32, self::ASPECT_DEPTH, 1);

        // MoltenVK requires images to be transitioned before descriptor writes
        if ($commandPool !== null && $queue !== null) {
            $rawCmds = $commandPool->allocateBuffers(1, true);
            $oneShot = $rawCmds[0] ?? null;
            if ($oneShot instanceof \Vk\CommandBuffer) {
                $oneShot->begin(1); // VK_CMD_ONE_TIME_SUBMIT
                $oneShot->imageMemoryBarrier(
                    $this->colorImage, 0, self::LAYOUT_SHADER_READ,
                    0, 0x00000020, 1, 128, self::ASPECT_COLOR,
                );
                $oneShot->imageMemoryBarrier(
                    $this->depthImage, 0, self::LAYOUT_SHADER_READ,
                    0, 0x00000020, 1, 128, self::ASPECT_DEPTH,
                );
                $oneShot->end();
                $queue->submit([$oneShot], null, [], []);
                $this->device->waitIdle();
            }
        }

        // Samplers
        $this->colorSampler = new Sampler($this->device, [
            'magFilter' => self::VK_FILTER_LINEAR,
            'minFilter' => self::VK_FILTER_LINEAR,
            'addressModeU' => self::VK_SAMPLER_ADDRESS_CLAMP,
            'addressModeV' => self::VK_SAMPLER_ADDRESS_CLAMP,
        ]);
        $this->depthSampler = new Sampler($this->device, [
            'magFilter' => self::VK_FILTER_NEAREST,
            'minFilter' => self::VK_FILTER_NEAREST,
            'addressModeU' => self::VK_SAMPLER_ADDRESS_CLAMP,
            'addressModeV' => self::VK_SAMPLER_ADDRESS_CLAMP,
        ]);

        // Scene render pass (HDR color + depth)
        $this->sceneRenderPass = new RenderPass($this->device, [
            [
                'format' => self::FORMAT_RGBA16F,
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
                'storeOp' => self::STORE_STORE,
                'stencilLoadOp' => self::LOAD_DONT_CARE,
                'stencilStoreOp' => self::STORE_DONT_CARE,
                'initialLayout' => self::LAYOUT_UNDEFINED,
                'finalLayout' => self::LAYOUT_SHADER_READ,
            ],
        ], [
            [
                'pipelineBindPoint' => self::PIPELINE_BIND_GRAPHICS,
                'colorAttachments' => [['attachment' => 0, 'layout' => self::LAYOUT_COLOR_ATTACHMENT]],
                'depthAttachment' => ['attachment' => 1, 'layout' => self::LAYOUT_DEPTH_ATTACHMENT],
            ],
        ], []);

        $this->sceneFbo = new Framebuffer($this->device, $this->sceneRenderPass,
            [$this->colorImageView, $this->depthImageView],
            $this->width, $this->height, 1);

        // Post-process UBO
        $this->postUbo = new \Vk\Buffer($this->device, self::POST_UBO_SIZE,
            self::VK_BUFFER_USAGE_UNIFORM, self::VK_SHARING_EXCLUSIVE);
        $uReq = $this->postUbo->getMemoryRequirements();
        $uSize = $uReq['size'];
        if (!is_int($uSize)) throw new \RuntimeException('Invalid post-process UBO memory');
        $this->postUboMem = new DeviceMemory($this->device, $uSize, $findHostMemory($uReq));
        $this->postUbo->bindMemory($this->postUboMem, 0);
        $this->postUboMem->map(0, null);

        // Post-process descriptor layout: 2 samplers + 1 UBO
        $this->postDescriptorLayout = new DescriptorSetLayout($this->device, [
            ['binding' => 0, 'descriptorType' => self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER, 'stageFlags' => self::SHADER_STAGE_FRAGMENT],
            ['binding' => 1, 'descriptorType' => self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER, 'stageFlags' => self::SHADER_STAGE_FRAGMENT],
            ['binding' => 2, 'descriptorType' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'stageFlags' => self::SHADER_STAGE_FRAGMENT],
        ]);

        $this->postDescriptorPool = new DescriptorPool($this->device, 1, [
            ['type' => self::VK_DESCRIPTOR_COMBINED_IMAGE_SAMPLER, 'count' => 2],
            ['type' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'count' => 1],
        ]);

        $rawSets = $this->postDescriptorPool->allocateSets([$this->postDescriptorLayout]);
        $set = $rawSets[0] ?? null;
        if (!$set instanceof DescriptorSet) throw new \RuntimeException('Failed to allocate post-process descriptor set');
        $this->postDescriptorSet = $set;

        // Write descriptors
        fprintf(STDERR, "[PostProcess] writeImage binding 0 (color)...\n");
        $this->postDescriptorSet->writeImage(
            0, $this->colorImageView, $this->colorSampler, self::LAYOUT_SHADER_READ,
        );
        fprintf(STDERR, "[PostProcess] writeImage binding 1 (depth)...\n");
        $this->postDescriptorSet->writeImage(
            1, $this->depthImageView, $this->depthSampler, self::LAYOUT_SHADER_READ,
        );
        fprintf(STDERR, "[PostProcess] writeBuffer binding 2 (UBO)...\n");
        $this->postDescriptorSet->writeBuffer(
            2, $this->postUbo, 0, self::POST_UBO_SIZE, self::VK_DESCRIPTOR_UNIFORM_BUFFER,
        );
        fprintf(STDERR, "[PostProcess] descriptors done!\n");

        // Post-process pipeline
        $this->postPipelineLayout = new PipelineLayout($this->device, [$this->postDescriptorLayout], []);

        $vertModule = ShaderModule::createFromFile($this->device, self::VERT_SPV);
        $fragModule = ShaderModule::createFromFile($this->device, self::FRAG_SPV);

        $this->postPipeline = Pipeline::createGraphics($this->device, [
            'renderPass' => $presentRenderPass,
            'layout' => $this->postPipelineLayout,
            'vertexShader' => $vertModule,
            'fragmentShader' => $fragModule,
            'vertexBindings' => [],
            'vertexAttributes' => [],
            'cullMode' => 0, // VK_CULL_MODE_NONE — fullscreen triangle
            'frontFace' => 0,
            'depthTestEnable' => false,
            'depthWriteEnable' => false,
        ]);

        $this->initialized = true;
    }

    /**
     * Upload post-process UBO data and record draw commands.
     * Call within the present render pass command buffer.
     */
    public function recordPostProcessCommands(\Vk\CommandBuffer $commandBuffer): void
    {
        if (!$this->initialized) return;

        // Upload UBO
        $data = pack('f4',
            $this->sunDirection->x, $this->sunDirection->y, $this->sunDirection->z, $this->time);
        $data .= pack('f4',
            $this->sunDirection->x, $this->sunDirection->y, $this->sunDirection->z, $this->sunIntensity);
        $data .= pack('i4',
            $this->width, $this->height,
            $this->ssaoEnabled ? 1 : 0, $this->bloomEnabled ? 1 : 0);
        $data .= pack('i2f2',
            $this->godRaysEnabled ? 1 : 0, $this->dofEnabled ? 1 : 0,
            $this->dofFocusDistance, $this->dofRange);

        if (strlen($data) < self::POST_UBO_SIZE) {
            $data .= str_repeat("\0", self::POST_UBO_SIZE - strlen($data));
        }
        $this->postUboMem->write($data, 0);

        // Bind post-process pipeline + descriptors
        $commandBuffer->bindPipeline(self::PIPELINE_BIND_GRAPHICS, $this->postPipeline);
        $commandBuffer->bindDescriptorSets(
            self::PIPELINE_BIND_GRAPHICS, $this->postPipelineLayout, 0, [$this->postDescriptorSet],
        );

        // Fullscreen triangle (3 vertices, no vertex buffer)
        $commandBuffer->draw(3, 1, 0, 0);
    }
}
