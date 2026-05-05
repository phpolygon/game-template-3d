<?php

declare(strict_types=1);

namespace App;

use App\System\AmbientAudioSystem;
use App\System\AmbientLightSystem;
use App\System\CoconutSystem;
use App\System\FirstPersonCameraSystem;
use App\System\FootprintSystem;
use App\System\PalmSwaySystem;
use App\System\PlayerBodySystem;
use PHPolygon\System\InstancedTerrainSystem;
use App\System\WaveSystem;
use PHPolygon\System\WindSystem;
use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\Backend\PHPGLFWAudioBackend;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\DayNightSystem;
use PHPolygon\System\DoorSystem;
use PHPolygon\System\AtmosphericEnvironmentalSystem;
use PHPolygon\System\PrecipitationSystem;
use PHPolygon\System\Physics3DSystem;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\System\RigidBody3DSystem;
use PHPolygon\Rendering\Color;
use PHPolygon\System\Transform3DSystem;

class Game
{
    private static float $loadingAlpha = 1.0;
    private static string $loadingStatus = '';
    private static string $renderBackendName = '';
    private static float $minimumDisplayTime = 0.0;

    public static function run(): void
    {
        $engine = new Engine(new EngineConfig(
            title: 'PHPolygon 3D — Beach',
            width: 1280,
            height: 720,
            targetTickRate: 60.0,
            assetsPath: __DIR__ . '/../assets',
            is3D: true,
            renderBackend3D: 'metal',
            vioBackend: 'auto',
            useNative3D: true,
        ));

        $engine->onInit(function (Engine $engine) {
            $commandList = $engine->commandList3D;
            $config = $engine->getConfig();

            $w = $engine->window->getWidth();
            $h = $engine->window->getHeight();
            $fbW = $engine->window->getFramebufferWidth();
            $fbH = $engine->window->getFramebufferHeight();
            $scale = $engine->window->getContentScaleX();

            $backend = $engine->buildRendererInfo();

            self::$renderBackendName = $backend;

            $sysInfo = [
                'Engine'     => 'PHPolygon 3D',
                'Renderer'   => $backend,
                'Display'    => "{$w}x{$h}" . ($scale > 1.0 ? " ({$fbW}x{$fbH} Retina)" : ''),
                'Platform'   => PHP_OS_FAMILY . ' ' . php_uname('m'),
                'PHP'        => PHP_VERSION,
                'Memory'     => ini_get('memory_limit'),
            ];

            // =====================================================================
            // STARTUP SCREEN — interactive, wait for click
            // =====================================================================
            $startClicked = true;
            $buttonX = (float)($w / 2 - 120);
            $buttonY = (float)($h / 2 + 80);
            $buttonW = 240.0;
            $buttonH = 50.0;

            while (!$engine->window->shouldClose() && !$startClicked) {
                $engine->window->pollEvents();
                $engine->input->endFrame(); // process input state

                // Check mouse click on Start button
                $mx = $engine->input->getMouseX();
                $my = $engine->input->getMouseY();
                $hover = $mx >= $buttonX && $mx <= $buttonX + $buttonW
                      && $my >= $buttonY && $my <= $buttonY + $buttonH;

                if ($hover && $engine->input->isMouseButtonPressed(VIO_MOUSE_LEFT)) {
                    $startClicked = true;
                }

                // Draw startup screen
                $r = $engine->renderer2D;
                $r->beginFrame();

                // Background
                $r->drawRect(0, 0, (float)$w, (float)$h, new Color(0.02, 0.04, 0.08));

                // Title
                $r->drawTextCentered('PHPolygon 3D', (float)($w / 2), 60.0, 48.0,
                    new Color(0.85, 0.78, 0.55));
                $r->drawTextCentered('Beach Demo', (float)($w / 2), 100.0, 20.0,
                    new Color(0.5, 0.55, 0.6));

                // System info panel
                $panelX = (float)($w / 2 - 200);
                $panelY = 140.0;
                $r->drawRoundedRect($panelX, $panelY, 400.0, (float)(count($sysInfo) * 28 + 20), 8.0,
                    new Color(0.06, 0.08, 0.14));

                $lineY = $panelY + 18.0;
                foreach ($sysInfo as $label => $value) {
                    $r->drawText($label, $panelX + 20.0, $lineY, 15.0, new Color(0.4, 0.45, 0.5));
                    $r->drawText($value, $panelX + 140.0, $lineY, 15.0, new Color(0.8, 0.82, 0.85));
                    $lineY += 28.0;
                }

                // Start button
                $btnColor = $hover
                    ? new Color(0.2, 0.5, 0.8)
                    : new Color(0.12, 0.35, 0.6);
                $r->drawRoundedRect($buttonX, $buttonY, $buttonW, $buttonH, 10.0, $btnColor);
                $r->drawTextCentered('Start', (float)($w / 2), $buttonY + 32.0, 22.0,
                    new Color(1.0, 1.0, 1.0));

                // Version info bottom
                $r->drawTextCentered($backend . '  |  ' . $w . 'x' . $h,
                    (float)($w / 2), (float)($h - 20), 12.0, new Color(0.3, 0.35, 0.4));

                $r->endFrame();
                if (!($engine->renderer3D instanceof \PHPolygon\Rendering\VulkanRenderer3D)) {
                    $engine->window->swapBuffers();
                }

                usleep(16666); // ~60fps
            }

            // =====================================================================
            // LOADING PHASE — systems + scene
            // =====================================================================
            self::renderLoadingFrame($engine, $w, $h, 'Initializing systems...');

            // Audio with real backend
            $audioBackend = PHPGLFWAudioBackend::isAvailable()
                ? new PHPGLFWAudioBackend()
                : null;
            $audioManager = $audioBackend
                ? new AudioManager($audioBackend)
                : $engine->audio;

            $engine->world->addSystem(new FirstPersonCameraSystem($engine->input, $engine->window));
            $engine->world->addSystem(new PlayerBodySystem());
            $engine->world->addSystem(new WindSystem());
            $engine->world->addSystem(new PalmSwaySystem());
            $engine->world->addSystem(new CoconutSystem());
            $engine->world->addSystem(new WaveSystem());
            $engine->world->addSystem(new FootprintSystem());
            $engine->world->addSystem(new AmbientAudioSystem($audioManager, $config->assetsPath));
            $engine->world->addSystem(new AtmosphericEnvironmentalSystem());
            $engine->world->addSystem(new PrecipitationSystem());
            $engine->world->addSystem(new DayNightSystem($commandList));
            $engine->world->addSystem(new DoorSystem());
            $engine->world->addSystem(new Transform3DSystem());
            $engine->world->addSystem(new Physics3DSystem(groundPlaneY: -5.0));
            $engine->world->addSystem(new RigidBody3DSystem());
            $fbW = $engine->window->getFramebufferWidth() ?: $w;
            $fbH = $engine->window->getFramebufferHeight() ?: $h;
            $engine->world->addSystem(new Camera3DSystem($commandList, $fbW, $fbH));
            $engine->world->addSystem(new InstancedTerrainSystem($commandList));
            $engine->world->addSystem(new Renderer3DSystem(
                $engine->renderer3D,
                $commandList,
            ));

            self::renderLoadingFrame($engine, $w, $h, 'Building terrain & vegetation...');

            $engine->scenes->register('playground', Scene\PlaygroundScene::class);
            $engine->scenes->loadScene('playground');

            self::renderLoadingFrame($engine, $w, $h, 'Building physics colliders...');

            // HeightmapCollider3D is populated during scene build — no BVH prebuild needed.
            // MeshCollider3D BVH (for rocks etc.) builds lazily on first physics tick.

            self::renderLoadingFrame($engine, $w, $h, 'Ready');

            // Hold loading screen for minimum display time
            self::$minimumDisplayTime = microtime(true) + 1.5;
            self::$loadingAlpha = 1.0;
            self::$loadingStatus = 'Ready';
        });

        $engine->onUpdate(function (Engine $engine, float $dt) {
            $fps = $engine->gameLoop->getAverageFps();
            $engine->window->setTitle(sprintf('PHPolygon 3D — Beach — %.0f FPS', $fps));

            // Fade out loading overlay
            if (self::$loadingAlpha > 0.0) {
                if (microtime(true) >= self::$minimumDisplayTime) {
                    self::$loadingAlpha -= $dt * 0.8; // ~1.25s fade
                    if (self::$loadingAlpha < 0.0) {
                        self::$loadingAlpha = 0.0;
                    }
                }
            }
        });

        $engine->onRender(function (Engine $engine, float $interpolation) {
            if (self::$loadingAlpha <= 0.01) return;

            $config = $engine->getConfig();
            $w = (float) $engine->window->getWidth();
            $h = (float) $engine->window->getHeight();
            $a = self::$loadingAlpha;

            $r = $engine->renderer2D;

            // Dark overlay fading out
            $r->drawRect(0, 0, $w, $h, new Color(0.02, 0.05, 0.12, $a));

            // Title fades with overlay
            $r->drawTextCentered('PHPolygon 3D', $w / 2, $h / 2 - 40, 42.0,
                new Color(0.85, 0.78, 0.55, $a));

            $r->drawTextCentered(self::$loadingStatus, $w / 2, $h / 2 + 20, 18.0,
                new Color(0.5, 0.55, 0.6, $a));

            // Render backend info (bottom)
            if (self::$renderBackendName !== '') {
                $r->drawTextCentered(self::$renderBackendName, $w / 2, $h - 40, 14.0,
                    new Color(0.35, 0.4, 0.45, $a * 0.7));
            }
        });

        $engine->run();
    }

    private static function renderLoadingFrame(Engine $engine, int $w, int $h, string $status): void
    {
        $r = $engine->renderer2D;
        $r->beginFrame();

        // Dark ocean-blue background
        $r->drawRect(0, 0, (float) $w, (float) $h, new Color(0.02, 0.05, 0.12));

        // Title
        $r->drawTextCentered('PHPolygon 3D', (float) ($w / 2), (float) ($h / 2 - 40), 42.0, new Color(0.85, 0.78, 0.55));

        // Status text
        $r->drawTextCentered($status, (float) ($w / 2), (float) ($h / 2 + 20), 18.0, new Color(0.5, 0.55, 0.6));

        // Render backend (bottom)
        if (self::$renderBackendName !== '') {
            $r->drawTextCentered(self::$renderBackendName, (float) ($w / 2), (float) ($h - 40), 14.0,
                new Color(0.35, 0.4, 0.45));
        }

        $r->endFrame();
        // Vulkan: no swap buffers (no GL context), present handled by Vulkan renderer
        if (!($engine->renderer3D instanceof \PHPolygon\Rendering\VulkanRenderer3D)) {
            $engine->window->swapBuffers();
        }
        $engine->window->pollEvents();
    }
}
