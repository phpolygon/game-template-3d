<?php

declare(strict_types=1);

namespace App\Tests\Scene;

use App\Component\CloudDrift;
use App\Component\FirstPersonCamera;
use App\Component\PalmSway;
use App\Component\WaveStrip;
use App\Component\Wind;
use App\Scene\PlaygroundScene;
use PHPUnit\Framework\TestCase;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\World;
use PHPolygon\Scene\SceneBuilder;

class PlaygroundSceneTest extends TestCase
{
    private function buildScene(): array
    {
        $world = new World();
        $builder = new SceneBuilder();
        (new PlaygroundScene())->build($builder);
        return ['world' => $world, 'map' => $builder->materialize($world)];
    }

    public function testSceneHasPlayer(): void
    {
        ['world' => $world, 'map' => $map] = $this->buildScene();

        $this->assertArrayHasKey('Player', $map);
        $id = $map['Player'];
        $this->assertTrue($world->hasComponent($id, Transform3D::class));
        $this->assertTrue($world->hasComponent($id, Camera3DComponent::class));
        $this->assertTrue($world->hasComponent($id, CharacterController3D::class));
        $this->assertTrue($world->hasComponent($id, FirstPersonCamera::class));
    }

    public function testSceneHasSandAndOcean(): void
    {
        ['world' => $world, 'map' => $map] = $this->buildScene();

        $this->assertArrayHasKey('Sand', $map);
        $sand = $world->getComponent($map['Sand'], MeshRenderer::class);
        $this->assertSame('plane', $sand->meshId);
        $this->assertSame('sand', $sand->materialId);

        $this->assertArrayHasKey('DeepOcean', $map);
        $ocean = $world->getComponent($map['DeepOcean'], MeshRenderer::class);
        $this->assertSame('deep_water', $ocean->materialId);
    }

    public function testSceneHasPalmTrees(): void
    {
        ['world' => $world, 'map' => $map] = $this->buildScene();

        $trunkCount = 0;
        $leafCount = 0;
        foreach ($map as $name => $id) {
            if (str_starts_with($name, 'PalmTrunk_')) {
                $mesh = $world->getComponent($id, MeshRenderer::class);
                $this->assertSame('cylinder', $mesh->meshId);
                $this->assertTrue($world->hasComponent($id, PalmSway::class));
                $trunkCount++;
            }
            if (str_starts_with($name, 'PalmLeaf_')) {
                $mesh = $world->getComponent($id, MeshRenderer::class);
                $this->assertSame('sphere', $mesh->meshId);
                $this->assertTrue($world->hasComponent($id, PalmSway::class));
                $leafCount++;
            }
        }
        $this->assertSame(10, $trunkCount);
        $this->assertGreaterThan($trunkCount, $leafCount);
    }

    public function testSceneHasRocks(): void
    {
        ['world' => $world, 'map' => $map] = $this->buildScene();

        $rockCount = 0;
        foreach ($map as $name => $id) {
            if (str_starts_with($name, 'Rock_')) {
                $this->assertTrue($world->hasComponent($id, MeshRenderer::class));
                $rockCount++;
            }
        }
        $this->assertGreaterThanOrEqual(10, $rockCount);
    }

    public function testSceneHasSunlight(): void
    {
        ['world' => $world, 'map' => $map] = $this->buildScene();

        $this->assertArrayHasKey('Sun', $map);
        $this->assertTrue($world->hasComponent($map['Sun'], DirectionalLight::class));
    }

    public function testSceneHasSunsetGlow(): void
    {
        ['world' => $world, 'map' => $map] = $this->buildScene();

        $this->assertArrayHasKey('SunsetGlow', $map);
        $this->assertTrue($world->hasComponent($map['SunsetGlow'], PointLight::class));
    }

    public function testSceneClearColorIsSkyBlue(): void
    {
        $config = (new PlaygroundScene())->getConfig();
        $this->assertGreaterThan(0.4, $config->clearColor->b);
    }

    public function testSceneHasWindController(): void
    {
        ['world' => $world, 'map' => $map] = $this->buildScene();

        $this->assertArrayHasKey('WindController', $map);
        $this->assertTrue($world->hasComponent($map['WindController'], Wind::class));
    }

    public function testSceneHasWaves(): void
    {
        ['world' => $world, 'map' => $map] = $this->buildScene();

        $waveCount = 0;
        $foamCount = 0;
        foreach ($map as $name => $id) {
            if (str_starts_with($name, 'Wave_')) {
                $this->assertTrue($world->hasComponent($id, WaveStrip::class));
                $waveCount++;
            }
            if (str_starts_with($name, 'Foam_')) {
                $this->assertTrue($world->hasComponent($id, WaveStrip::class));
                $foamCount++;
            }
        }
        $this->assertGreaterThanOrEqual(15, $waveCount);
        $this->assertGreaterThanOrEqual(2, $foamCount);
    }

    public function testSceneHasClouds(): void
    {
        ['world' => $world, 'map' => $map] = $this->buildScene();

        $cloudCount = 0;
        foreach ($map as $name => $id) {
            if (str_starts_with($name, 'Cloud_')) {
                $this->assertTrue($world->hasComponent($id, CloudDrift::class));
                $cloudCount++;
            }
        }
        $this->assertGreaterThanOrEqual(30, $cloudCount);
    }
}
