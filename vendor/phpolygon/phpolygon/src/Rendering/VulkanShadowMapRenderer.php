<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use Vk\Buffer;
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
 * Vulkan shadow map renderer — renders scene depth from sun's perspective.
 * Produces a D32_SFLOAT depth image sampled by the main pass for PCF shadows.
 */
class VulkanShadowMapRenderer
{
    private bool $initialized = false;
    private Mat4 $lightSpaceMatrix;

    private Image $depthImage;
    private DeviceMemory $depthImageMem;
    private ImageView $depthImageView;
    private Sampler $depthSampler;
    private RenderPass $renderPass;
    private Framebuffer $framebuffer;
    private Pipeline $pipeline;
    private PipelineLayout $pipelineLayout;

    // Shadow UBO: light-space mat4
    private Buffer $shadowUbo;
    private DeviceMemory $shadowUboMem;

    private const FORMAT_D32 = 126;  // VK_FORMAT_D32_SFLOAT
    private const USAGE_DEPTH = 32;  // VK_IMAGE_USAGE_DEPTH_STENCIL_ATTACHMENT_BIT
    private const USAGE_SAMPLED = 4; // VK_IMAGE_USAGE_SAMPLED_BIT
    private const ASPECT_DEPTH = 2;
    private const SAMPLE_COUNT_1 = 1;
    private const LOAD_CLEAR = 1;
    private const STORE_STORE = 0;
    private const STORE_DONT_CARE = 1;
    private const LOAD_DONT_CARE = 2;
    private const LAYOUT_UNDEFINED = 0;
    private const LAYOUT_DEPTH_ATTACHMENT = 3;
    private const LAYOUT_DEPTH_READ_ONLY = 1000117000; // VK_IMAGE_LAYOUT_DEPTH_STENCIL_READ_ONLY_OPTIMAL
    private const PIPELINE_BIND_GRAPHICS = 0;
    private const SHADER_STAGE_VERTEX = 1;
    private const CULL_BACK = 2;
    private const FRONT_CCW = 0;
    private const VK_FORMAT_R32G32B32_SFLOAT = 106;
    private const VK_FORMAT_R32G32_SFLOAT = 103;
    private const VK_BUFFER_USAGE_UNIFORM = 16;
    private const VK_SHARING_EXCLUSIVE = 0;
    private const VK_DESCRIPTOR_UNIFORM_BUFFER = 6;
    private const VK_COMPARE_OP_LESS_OR_EQUAL = 3;
    private const VK_BORDER_COLOR_FLOAT_OPAQUE_WHITE = 1;
    private const VK_FILTER_LINEAR = 1;
    private const VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER = 3;

    public function __construct(
        private readonly Device $device,
        private readonly int $resolution = 2048,
        private readonly float $orthoSize = 60.0,
        private readonly float $nearPlane = 0.5,
        private readonly float $farPlane = 200.0,
    ) {
        $this->lightSpaceMatrix = Mat4::identity();
    }

    public function getLightSpaceMatrix(): Mat4
    {
        return $this->lightSpaceMatrix;
    }

    public function getDepthImageView(): ImageView
    {
        return $this->depthImageView;
    }

    public function getDepthSampler(): Sampler
    {
        return $this->depthSampler;
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    /**
     * @param callable(array<mixed>): int $findMemory
     */
    public function initialize(callable $findMemory): void
    {
        if ($this->initialized) return;

        // Depth image
        $this->depthImage = new Image(
            $this->device, $this->resolution, $this->resolution,
            self::FORMAT_D32, self::USAGE_DEPTH | self::USAGE_SAMPLED, 0, self::SAMPLE_COUNT_1,
        );
        $req = $this->depthImage->getMemoryRequirements();
        $size = $req['size'];
        if (!is_int($size)) throw new \RuntimeException('Invalid shadow depth image memory');
        $this->depthImageMem = new DeviceMemory($this->device, $size, $findMemory($req));
        $this->depthImage->bindMemory($this->depthImageMem, 0);
        $this->depthImageView = new ImageView($this->device, $this->depthImage, self::FORMAT_D32, self::ASPECT_DEPTH, 1);

        // Comparison sampler for PCF
        $this->depthSampler = new Sampler($this->device, [
            'magFilter' => self::VK_FILTER_LINEAR,
            'minFilter' => self::VK_FILTER_LINEAR,
            'addressModeU' => self::VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER,
            'addressModeV' => self::VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER,
            'borderColor' => self::VK_BORDER_COLOR_FLOAT_OPAQUE_WHITE,
            'compareEnable' => true,
            'compareOp' => self::VK_COMPARE_OP_LESS_OR_EQUAL,
        ]);

        // Depth-only render pass
        $this->renderPass = new RenderPass($this->device, [
            [
                'format' => self::FORMAT_D32,
                'samples' => self::SAMPLE_COUNT_1,
                'loadOp' => self::LOAD_CLEAR,
                'storeOp' => self::STORE_STORE,
                'stencilLoadOp' => self::LOAD_DONT_CARE,
                'stencilStoreOp' => self::STORE_DONT_CARE,
                'initialLayout' => self::LAYOUT_UNDEFINED,
                'finalLayout' => self::LAYOUT_DEPTH_READ_ONLY,
            ],
        ], [
            [
                'pipelineBindPoint' => self::PIPELINE_BIND_GRAPHICS,
                'depthAttachment' => ['attachment' => 0, 'layout' => self::LAYOUT_DEPTH_ATTACHMENT],
                'colorAttachments' => [],
            ],
        ], []);

        $this->framebuffer = new Framebuffer(
            $this->device, $this->renderPass,
            [$this->depthImageView],
            $this->resolution, $this->resolution, 1,
        );

        $this->initialized = true;
    }

    public function updateLightMatrix(Vec3 $sunDirection): void
    {
        $len = sqrt($sunDirection->x ** 2 + $sunDirection->y ** 2 + $sunDirection->z ** 2);
        if ($len < 0.001) return;
        $dx = $sunDirection->x / $len;
        $dy = $sunDirection->y / $len;
        $dz = $sunDirection->z / $len;

        $lightPos = new Vec3(-$dx * 80.0, -$dy * 80.0, -$dz * 80.0);
        $target = Vec3::zero();
        $up = abs($dy) > 0.9 ? new Vec3(0.0, 0.0, 1.0) : new Vec3(0.0, 1.0, 0.0);

        $lightView = self::lookAt($lightPos, $target, $up);
        $s = $this->orthoSize;
        $lightProj = Mat4::orthographic(-$s, $s, -$s, $s, $this->nearPlane, $this->farPlane);

        $this->lightSpaceMatrix = $lightProj->multiply($lightView);
    }

    private static function lookAt(Vec3 $eye, Vec3 $target, Vec3 $up): Mat4
    {
        $f = $target->sub($eye)->normalize();
        $s = $f->cross($up)->normalize();
        $u = $s->cross($f);
        return new Mat4([
            $s->x, $u->x, -$f->x, 0.0,
            $s->y, $u->y, -$f->y, 0.0,
            $s->z, $u->z, -$f->z, 0.0,
            -$s->dot($eye), -$u->dot($eye), $f->dot($eye), 1.0,
        ]);
    }
}
