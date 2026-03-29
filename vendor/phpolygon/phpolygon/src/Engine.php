<?php

declare(strict_types=1);

namespace PHPolygon;

use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\GLFWAudioBackend;
use PHPolygon\ECS\World;
use PHPolygon\Event\EventDispatcher;
use PHPolygon\Locale\LocaleManager;
use PHPolygon\Rendering\Camera2D;
use PHPolygon\Rendering\NullRenderer2D;
use PHPolygon\Rendering\NullRenderer3D;
use PHPolygon\Rendering\OpenGLRenderer3D;
use PHPolygon\Rendering\VulkanRenderer3D;
use PHPolygon\Rendering\Renderer2D;
use PHPolygon\Rendering\Renderer2DInterface;
use PHPolygon\Rendering\RenderCommandList;
use PHPolygon\Rendering\Renderer3DInterface;
use PHPolygon\Rendering\TextureManager;
use PHPolygon\Testing\NullTextureManager;
use PHPolygon\Runtime\Clock;
use PHPolygon\Runtime\GameLoop;
use PHPolygon\Runtime\Input;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\Runtime\NullWindow;
use PHPolygon\Runtime\Window;
use PHPolygon\SaveGame\SaveManager;
use PHPolygon\Scene\SceneManager;
use PHPolygon\Scene\SceneManagerInterface;
use PHPolygon\Thread\NullThreadScheduler;
use PHPolygon\Thread\ThreadScheduler;
use PHPolygon\Thread\ThreadSchedulerFactory;

class Engine
{
    public readonly World $world;
    public readonly Window $window;
    public readonly InputInterface $input;
    public readonly Camera2D $camera2D;
    public readonly TextureManager $textures;
    public readonly EventDispatcher $events;
    public readonly GameLoop $gameLoop;
    public readonly Clock $clock;
    public readonly SceneManagerInterface $scenes;
    public readonly AudioManager $audio;
    public readonly LocaleManager $locale;
    public readonly SaveManager $saves;

    public Renderer2DInterface $renderer2D;
    public ?Renderer3DInterface $renderer3D;
    public readonly ?RenderCommandList $commandList3D;
    public readonly ThreadScheduler|NullThreadScheduler $scheduler;

    private bool $running = false;
    private bool $headless;

    /** @var callable|null */
    private $onUpdate = null;

    /** @var callable|null */
    private $onRender = null;

    /** @var callable|null */
    private $onInit = null;

    public function __construct(
        private readonly EngineConfig $config = new EngineConfig(),
    ) {
        $this->headless = $config->headless;

        // Use config dimensions as placeholder — resolved after window init if 0
        $initW = max(1, $config->width);
        $initH = max(1, $config->height);

        $this->world = new World();
        $this->input = new Input();
        $this->events = new EventDispatcher();
        $this->clock = new Clock();
        $this->camera2D = new Camera2D($initW, $initH);
        $this->textures = $this->headless
            ? new NullTextureManager($config->assetsPath)
            : new TextureManager($config->assetsPath);
        $this->gameLoop = new GameLoop($config->targetTickRate);
        $this->scenes = new SceneManager($this);
        $this->audio = new AudioManager(
            $this->headless ? null : new GLFWAudioBackend(),
        );
        $this->locale = new LocaleManager($config->defaultLocale, $config->fallbackLocale);
        $this->saves = new SaveManager($config->savePath, $config->maxSaveSlots);
        $this->scheduler = ThreadSchedulerFactory::create($config);

        if ($config->is3D) {
            $this->commandList3D = new RenderCommandList();
            // Non-headless GPU renderers require a GL/Vulkan context — initialized in run()
            if ($this->headless || $config->renderBackend3D === 'null') {
                $this->renderer3D = new NullRenderer3D($initW, $initH);
            } else {
                $this->renderer3D = null;
            }
        } else {
            $this->commandList3D = null;
            $this->renderer3D = null;
        }

        if ($this->headless) {
            $this->window = new NullWindow($initW, $initH, $config->title);
            $this->renderer2D = new NullRenderer2D($initW, $initH);
        } else {
            $this->window = new Window(
                $config->width,
                $config->height,
                $config->title,
                $config->vsync,
                $config->resizable,
            );
        }
    }

    public function onUpdate(callable $callback): self
    {
        $this->onUpdate = $callback;
        return $this;
    }

    public function onRender(callable $callback): self
    {
        $this->onRender = $callback;
        return $this;
    }

    public function onInit(callable $callback): self
    {
        $this->onInit = $callback;
        return $this;
    }

    public function run(): void
    {
        $this->window->initialize($this->input);

        // Auto-size: maximize window before creating renderers so they get the right dimensions
        if ($this->config->width <= 0 || $this->config->height <= 0) {
            $this->window->detectAndResize();
        }

        // Use framebuffer dimensions for rendering (accounts for HiDPI/Retina scaling)
        $renderWidth = $this->window->getFramebufferWidth() ?: $this->window->getWidth();
        $renderHeight = $this->window->getFramebufferHeight() ?: $this->window->getHeight();

        // Create GPU-backed renderers after window is initialized (need graphics context)
        if (!$this->headless && $this->config->is3D) {
            $backend = $this->config->renderBackend3D;

            // Auto-detect: prefer Vulkan (MoltenVK on macOS) → OpenGL fallback
            if ($backend === 'auto') {
                $backend = 'opengl'; // safe default
                if (class_exists(\Vk\Instance::class)) {
                    // Check if Vulkan/MoltenVK is available
                    $hasVulkanLib = false;
                    if (PHP_OS_FAMILY === 'Darwin') {
                        foreach (['/opt/homebrew/lib', '/usr/local/lib'] as $dir) {
                            if (file_exists("{$dir}/libvulkan.dylib")) { $hasVulkanLib = true; break; }
                        }
                    } else {
                        $hasVulkanLib = true; // Linux/Windows: assume Vulkan available if extension loaded
                    }
                    if ($hasVulkanLib) {
                        $backend = 'vulkan';
                    }
                }
                fprintf(STDERR, "[Engine] Auto-detected render backend: %s\n", $backend);
            }

            $this->renderer3D = match ($backend) {
                'vulkan' => new VulkanRenderer3D(
                    $renderWidth,
                    $renderHeight,
                    $this->window->getHandle(),
                ),
                default => new OpenGLRenderer3D($renderWidth, $renderHeight),
            };
        }

        // Create Renderer2D after window is initialized (needs GL context)
        if (!$this->headless) {
            $this->renderer2D = new Renderer2D($this->window);

            // Auto-load bundled fonts so text renders without manual setup
            $fontDir = __DIR__ . '/../resources/fonts';
            if (is_dir($fontDir)) {
                $this->renderer2D->loadFont('regular',  $fontDir . '/Inter-Regular.ttf');
                $this->renderer2D->loadFont('semibold', $fontDir . '/Inter-SemiBold.ttf');
                $this->renderer2D->setFont('regular');
            }
        }

        if ($this->onInit !== null) {
            ($this->onInit)($this);
        }

        $this->scheduler->boot();
        $this->running = true;

        $isPipelined = $this->scheduler instanceof ThreadScheduler
            && count($this->scheduler->getSubsystems()) > 0;

        if ($isPipelined) {
            $fixedDt = $this->gameLoop->getFixedDeltaTime();
            $this->gameLoop->runPipelined(
                prepareAndSend: function () use ($fixedDt) {
                    $this->scheduler->sendAll($this->world, $fixedDt);
                },
                update: function (float $dt) {
                    $this->world->updateMainThread($dt);

                    if ($this->onUpdate !== null) {
                        ($this->onUpdate)($this, $dt);
                    }
                },
                render: function (float $interpolation) {
                    $this->renderer2D->beginFrame();

                    $this->world->render();

                    if ($this->onRender !== null) {
                        ($this->onRender)($this, $interpolation);
                    }

                    $this->renderer2D->endFrame();
                    $this->window->swapBuffers();

                    $this->input->endFrame();
                    $this->window->pollEvents();
                },
                recvAndApply: function () {
                    $this->scheduler->recvAll($this->world);
                    $this->world->updatePostThread($this->gameLoop->getFixedDeltaTime());
                },
                shouldStop: function (): bool {
                    return !$this->running || $this->window->shouldClose();
                },
            );
        } else {
            $this->gameLoop->run(
                update: function (float $dt) {
                    $this->world->update($dt);

                    if ($this->onUpdate !== null) {
                        ($this->onUpdate)($this, $dt);
                    }
                },
                render: function (float $interpolation) {
                    $this->renderer2D->beginFrame();

                    $this->world->render();

                    if ($this->onRender !== null) {
                        ($this->onRender)($this, $interpolation);
                    }

                    $this->renderer2D->endFrame();
                    $this->window->swapBuffers();

                    $this->input->endFrame();
                    $this->window->pollEvents();
                },
                shouldStop: function (): bool {
                    return !$this->running || $this->window->shouldClose();
                },
            );
        }

        $this->shutdown();
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function getConfig(): EngineConfig
    {
        return $this->config;
    }

    private function shutdown(): void
    {
        $this->scheduler->shutdown();
        $this->audio->dispose();
        $this->textures->clear();
        $this->world->clear();
        $this->window->destroy();
    }
}
