<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use Vk\Device;
use Vk\DeviceMemory;
use Vk\Framebuffer;
use Vk\Image;
use Vk\ImageView;
use Vk\RenderPass;
use Vk\Sampler;

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
    private const VK_FILTER_LINEAR = 1;
    private const VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER = 3;
    private const VK_BORDER_COLOR_FLOAT_TRANSPARENT_BLACK = 0;

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
     * @param callable(array<mixed>): int $findMemory
     * @param callable(array<mixed>): int $findDeviceMemory
     */
    public function initialize(callable $findMemory, callable $findDeviceMemory): void
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

        $this->initialized = true;
    }
}
