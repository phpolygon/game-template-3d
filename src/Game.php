<?php

declare(strict_types=1);

namespace App;

use App\System\AmbientAudioSystem;
use App\System\AmbientLightSystem;
use App\System\CloudSystem;
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
use PHPolygon\System\EnvironmentalSystem;
use PHPolygon\System\PrecipitationSystem;
use PHPolygon\System\Physics3DSystem;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\System\RigidBody3DSystem;
use PHPolygon\System\Transform3DSystem;

class Game
{
    public static function run(): void
    {
        $engine = new Engine(new EngineConfig(
            title: 'PHPolygon 3D — Beach',
            width: 1280,
            height: 720,
            targetTickRate: 60.0,
            assetsPath: __DIR__ . '/../assets',
            is3D: true,
            renderBackend3D: 'opengl',
        ));

        $engine->onInit(function (Engine $engine) {
            $commandList = $engine->commandList3D;
            $config = $engine->getConfig();

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
            $engine->world->addSystem(new WaveSystem());
            $engine->world->addSystem(new FootprintSystem());
            $engine->world->addSystem(new CloudSystem());
            $engine->world->addSystem(new AmbientAudioSystem($audioManager, $config->assetsPath));
            $engine->world->addSystem(new EnvironmentalSystem());
            $engine->world->addSystem(new PrecipitationSystem());
            $engine->world->addSystem(new DayNightSystem($commandList));
            $engine->world->addSystem(new DoorSystem());
            $engine->world->addSystem(new Transform3DSystem());
            $engine->world->addSystem(new Physics3DSystem(groundPlaneY: -5.0));
            $engine->world->addSystem(new RigidBody3DSystem());
            $engine->world->addSystem(new Camera3DSystem($commandList, $config->width, $config->height));
            $engine->world->addSystem(new InstancedTerrainSystem($commandList));
            $engine->world->addSystem(new Renderer3DSystem(
                $engine->renderer3D,
                $commandList,
            ));

            $engine->scenes->register('playground', Scene\PlaygroundScene::class);
            $engine->scenes->loadScene('playground');
        });

        $engine->onUpdate(function (Engine $engine, float $dt) {
            $fps = $engine->gameLoop->getAverageFps();
            $engine->window->setTitle(sprintf('PHPolygon 3D — Beach — %.0f FPS', $fps));
        });

        $engine->run();
    }
}
