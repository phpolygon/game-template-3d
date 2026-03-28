<?php

declare(strict_types=1);

namespace App;

use App\System\FirstPersonCameraSystem;
use PHPolygon\Engine;
use PHPolygon\EngineConfig;
use PHPolygon\System\Camera3DSystem;
use PHPolygon\System\Physics3DSystem;
use PHPolygon\System\Renderer3DSystem;
use PHPolygon\System\Transform3DSystem;

class Game
{
    public static function run(): void
    {
        $engine = new Engine(new EngineConfig(
            title: 'PHPolygon 3D — Procedural Playground',
            width: 1280,
            height: 720,
            targetTickRate: 60.0,
            assetsPath: __DIR__ . '/../assets',
            is3D: true,
            renderBackend3D: 'opengl',
        ));

        $engine->onInit(function (Engine $engine) {
            $commandList = $engine->commandList3D;

            $engine->world->addSystem(new FirstPersonCameraSystem($engine->input, $engine->window));
            $engine->world->addSystem(new Transform3DSystem());
            $engine->world->addSystem(new Physics3DSystem());
            $engine->world->addSystem(new Camera3DSystem($commandList));
            $engine->world->addSystem(new Renderer3DSystem(
                $engine->renderer3D,
                $commandList,
            ));

            $engine->scenes->register('playground', Scene\PlaygroundScene::class);
            $engine->scenes->loadScene('playground');
        });

        $engine->onUpdate(function (Engine $engine, float $dt) {
            $fps = $engine->gameLoop->getAverageFps();
            $engine->window->setTitle(sprintf('PHPolygon 3D — %.0f FPS', $fps));
        });

        $engine->run();
    }
}
