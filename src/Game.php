<?php

declare(strict_types=1);

namespace App;

use App\System\AmbientAudioSystem;
use App\System\AmbientLightSystem;
use App\System\CloudSystem;
use App\System\CoconutSystem;
use App\System\FirstPersonCameraSystem;
use App\System\FootprintSystem;
use App\System\PalmSwaySystem;
use App\System\PlayerBodySystem;
use PHPolygon\System\InstancedTerrainSystem;
use App\System\WaveSystem;
use App\System\WindSystem;
use PHPolygon\Audio\AudioManager;
use PHPolygon\Audio\Backend\PHPGLFWAudioBackend;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\DayNightSystem;
use PHPolygon\System\DoorSystem;
use App\System\AtmosphericEnvironmentalSystem;
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
    private static float $minimumDisplayTime = 0.0;

    public static function run(): void
    {
        $engine = new Engine(new EngineConfig(
            title: 'PHPolygon 3D — Beach',
            width: 0,
            height: 0,
            targetTickRate: 60.0,
            assetsPath: __DIR__ . '/../assets',
            is3D: true,
            renderBackend3D: 'opengl',
        ));

        $engine->onInit(function (Engine $engine) {
            $commandList = $engine->commandList3D;
            $config = $engine->getConfig();

            // Window is already maximized by Engine — get actual dimensions
            $w = $engine->window->getWidth();
            $h = $engine->window->getHeight();
            fprintf(STDERR, "[Startup] Window: %dx%d  Framebuffer: %dx%d  Scale: %.1f\n",
                $w, $h,
                $engine->window->getFramebufferWidth(), $engine->window->getFramebufferHeight(),
                $engine->window->getContentScaleX());

            // --- Loading screen ---
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
            $engine->world->addSystem(new CloudSystem());
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

            // Pre-build MeshCollider3D BVH trees now (instead of lazy on first tick)
            // This is the expensive step that would otherwise freeze the first frame.
            foreach ($engine->world->query(
                \PHPolygon\Component\MeshCollider3D::class,
                \PHPolygon\Component\Transform3D::class,
            ) as $entity) {
                $mc = $entity->get(\PHPolygon\Component\MeshCollider3D::class);
                $transform = $entity->get(\PHPolygon\Component\Transform3D::class);
                $meshData = \PHPolygon\Geometry\MeshRegistry::get($mc->meshId);
                if ($meshData !== null && $mc->bvh === null) {
                    $worldMatrix = $transform->getWorldMatrix();
                    $triangles = [];
                    $verts = $meshData->vertices;
                    $idxs = $meshData->indices;
                    for ($i = 0, $count = count($idxs); $i < $count; $i += 3) {
                        $triangles[] = new \PHPolygon\Physics\Triangle(
                            $worldMatrix->transformPoint(new \PHPolygon\Math\Vec3($verts[$idxs[$i] * 3], $verts[$idxs[$i] * 3 + 1], $verts[$idxs[$i] * 3 + 2])),
                            $worldMatrix->transformPoint(new \PHPolygon\Math\Vec3($verts[$idxs[$i+1] * 3], $verts[$idxs[$i+1] * 3 + 1], $verts[$idxs[$i+1] * 3 + 2])),
                            $worldMatrix->transformPoint(new \PHPolygon\Math\Vec3($verts[$idxs[$i+2] * 3], $verts[$idxs[$i+2] * 3 + 1], $verts[$idxs[$i+2] * 3 + 2])),
                        );
                    }
                    $mc->bvh = \PHPolygon\Physics\BVH::build($triangles);
                    $mc->lastWorldMatrixArr = $worldMatrix->toArray();
                }
            }

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

        $r->endFrame();
        $engine->window->swapBuffers();
        $engine->window->pollEvents();
    }
}
