<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Mat4;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Command\DrawMesh;
use PHPolygon\Rendering\Command\DrawMeshInstanced;
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
    private DescriptorSetLayout $descriptorLayout;
    private DescriptorPool $descriptorPool;
    private DescriptorSet $descriptorSet;

    // Shadow UBO: light view (mat4) + light projection (mat4) = 128 bytes
    private Buffer $shadowUbo;
    private DeviceMemory $shadowUboMem;

    private const SHADOW_UBO_SIZE = 128;

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
    private const VK_FORMAT_R32G32B32A32_SFLOAT = 109;
    private const VK_FORMAT_R32G32_SFLOAT = 103;
    private const VK_BUFFER_USAGE_UNIFORM = 16;
    private const VK_BUFFER_USAGE_VERTEX = 128;
    private const VK_SHARING_EXCLUSIVE = 0;
    private const VK_DESCRIPTOR_UNIFORM_BUFFER = 6;
    private const VK_COMPARE_OP_LESS_OR_EQUAL = 3;
    private const VK_BORDER_COLOR_FLOAT_OPAQUE_WHITE = 1;
    private const VK_FILTER_LINEAR = 1;
    private const VK_SAMPLER_ADDRESS_CLAMP_TO_BORDER = 3;
    private const VK_INDEX_TYPE_UINT32 = 1;
    private const VK_VERTEX_INPUT_RATE_VERTEX = 0;
    private const VK_VERTEX_INPUT_RATE_INSTANCE = 1;

    private const VERT_SPV = __DIR__ . '/../../resources/shaders/compiled/shadow_vk.vert.spv';
    private const FRAG_SPV = __DIR__ . '/../../resources/shaders/compiled/shadow_vk.frag.spv';

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
     * @param callable(array<mixed>): int $findHostMemory
     * @param callable(array<mixed>): int $findDeviceMemory
     */
    public function initialize(callable $findHostMemory, callable $findDeviceMemory): void
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
        $this->depthImageMem = new DeviceMemory($this->device, $size, $findDeviceMemory($req));
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

        // Shadow UBO (light view + projection = 128 bytes)
        $this->shadowUbo = new Buffer(
            $this->device, self::SHADOW_UBO_SIZE,
            self::VK_BUFFER_USAGE_UNIFORM, self::VK_SHARING_EXCLUSIVE,
        );
        $uReq = $this->shadowUbo->getMemoryRequirements();
        $uSize = $uReq['size'];
        if (!is_int($uSize)) throw new \RuntimeException('Invalid shadow UBO memory');
        $this->shadowUboMem = new DeviceMemory($this->device, $uSize, $findHostMemory($uReq));
        $this->shadowUbo->bindMemory($this->shadowUboMem, 0);
        $this->shadowUboMem->map(0, null);

        // Descriptor set layout: binding 0 = UBO (vertex stage)
        $this->descriptorLayout = new DescriptorSetLayout($this->device, [
            ['binding' => 0, 'descriptorType' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'stageFlags' => self::SHADER_STAGE_VERTEX],
        ]);

        $this->descriptorPool = new DescriptorPool($this->device, 1, [
            ['type' => self::VK_DESCRIPTOR_UNIFORM_BUFFER, 'count' => 1],
        ]);

        $rawSets = $this->descriptorPool->allocateSets([$this->descriptorLayout]);
        $set = $rawSets[0] ?? null;
        if (!$set instanceof DescriptorSet) throw new \RuntimeException('Failed to allocate shadow descriptor set');
        $this->descriptorSet = $set;
        $this->descriptorSet->writeBuffer(0, $this->shadowUbo, 0, self::SHADOW_UBO_SIZE, self::VK_DESCRIPTOR_UNIFORM_BUFFER);

        // Shadow pipeline
        $vertModule = ShaderModule::createFromFile($this->device, self::VERT_SPV);
        $fragModule = ShaderModule::createFromFile($this->device, self::FRAG_SPV);

        $this->pipelineLayout = new PipelineLayout(
            $this->device,
            [$this->descriptorLayout],
            [['stageFlags' => self::SHADER_STAGE_VERTEX, 'offset' => 0, 'size' => 64]], // mat4 model
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
            'cullMode' => self::CULL_BACK,
            'frontFace' => self::FRONT_CCW,
            'depthTestEnable' => true,
            'depthWriteEnable' => true,
            'blendEnable' => false,
        ]);

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

    /**
     * Record shadow depth rendering commands into the given command buffer.
     *
     * @param CommandBuffer $cb Active command buffer (already begun)
     * @param array<string, array{vb: Buffer, vbMem: DeviceMemory, ib: Buffer, ibMem: DeviceMemory, count: int}> $meshCache
     * @param array<DrawMesh|DrawMeshInstanced> $shadowDraws Pre-filtered opaque non-sky draws
     * @param Buffer|null $instanceBuffer Shared instance buffer for instanced draws
     */
    public function recordShadowPass(
        CommandBuffer $cb,
        array $meshCache,
        array $shadowDraws,
        ?Buffer $instanceBuffer = null,
    ): void {
        if (!$this->initialized || empty($shadowDraws)) return;

        // Upload light-space matrix as view, identity as projection
        $lightView = $this->lightSpaceMatrix->toArray();
        $identity = Mat4::identity()->toArray();
        $data = pack('f16', ...$lightView) . pack('f16', ...$identity);
        $this->shadowUboMem->write($data, 0);

        // Begin shadow render pass
        $cb->beginRenderPass(
            $this->renderPass,
            $this->framebuffer,
            0, 0, $this->resolution, $this->resolution,
            [[1.0, 0]], // clear depth to 1.0
        );
        $cb->setViewport(0.0, 0.0, (float) $this->resolution, (float) $this->resolution, 0.0, 1.0);
        $cb->setScissor(0, 0, $this->resolution, $this->resolution);
        $cb->bindPipeline(self::PIPELINE_BIND_GRAPHICS, $this->pipeline);
        $cb->bindDescriptorSets(self::PIPELINE_BIND_GRAPHICS, $this->pipelineLayout, 0, [$this->descriptorSet]);

        foreach ($shadowDraws as $cmd) {
            if ($cmd instanceof DrawMesh) {
                if (!isset($meshCache[$cmd->meshId])) continue;
                $cache = $meshCache[$cmd->meshId];
                $modelBytes = pack('f16', ...$cmd->modelMatrix->toArray());
                $cb->pushConstants($this->pipelineLayout, self::SHADER_STAGE_VERTEX, 0, $modelBytes);
                $cb->bindVertexBuffers(0, [$cache['vb']], [0]);
                $cb->bindIndexBuffer($cache['ib'], 0, self::VK_INDEX_TYPE_UINT32);
                $cb->drawIndexed($cache['count'], 1, 0, 0, 0);
            } elseif ($cmd instanceof DrawMeshInstanced && $instanceBuffer !== null) {
                if (!isset($meshCache[$cmd->meshId]) || empty($cmd->matrices)) continue;
                $cache = $meshCache[$cmd->meshId];
                $identityBytes = pack('f16', ...Mat4::identity()->toArray());
                $cb->pushConstants($this->pipelineLayout, self::SHADER_STAGE_VERTEX, 0, $identityBytes);
                $cb->bindVertexBuffers(0, [$cache['vb'], $instanceBuffer], [0, 0]);
                $cb->bindIndexBuffer($cache['ib'], 0, self::VK_INDEX_TYPE_UINT32);
                $cb->drawIndexed($cache['count'], count($cmd->matrices), 0, 0, 0);
            }
        }

        $cb->endRenderPass();
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
