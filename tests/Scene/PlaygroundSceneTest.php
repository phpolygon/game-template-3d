<?php

declare(strict_types=1);

namespace App\Tests\Scene;

use App\Component\CloudDrift;
use App\Component\FirstPersonCamera;
use PHPolygon\Component\PalmSway;
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

        $this->assertArrayHasKey('SandTerrain', $map);
        $sand = $world->getComponent($map['SandTerrain'], MeshRenderer::class);
        $this->assertSame('beach_terrain', $sand->meshId);
        $this->assertSame('sand_terrain', $sand->materialId);

        $this->assertArrayHasKey('WaterDeep', $map);
        $ocean = $world->getComponent($map['WaterDeep'], MeshRenderer::class);
        $this->assertSame('water_deep_plane', $ocean->materialId);
    }

    public function testSceneHasPalmTrees(): void
    {
        ['world' => $world, 'map' => $map] = $this->buildScene();

        $trunkCount = 0;
        $frondCount = 0;
        foreach ($map as $name => $id) {
            // PalmBuilder uses _T_0 through _T_11 for trunk segments
            if (preg_match('/Palm_\d+_T_\d+/', $name)) {
                $mesh = $world->getComponent($id, MeshRenderer::class);
                $this->assertSame('cylinder', $mesh->meshId);
                $trunkCount++;
            }
            // PalmBuilder uses _F_0 through _F_29 for fronds
            if (preg_match('/Palm_\d+_F_\d+/', $name)) {
                $this->assertTrue($world->hasComponent($id, PalmSway::class));
                $frondCount++;
            }
        }
        // 10 palms × 12 trunk segments = 120
        $this->assertSame(120, $trunkCount);
        // 10 palms × 30 fronds = 300
        $this->assertSame(300, $frondCount);
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

        // Water is now a single GPU-animated plane, not individual WaveStrip entities
        $this->assertArrayHasKey('WaterSurface', $map);
        $this->assertArrayHasKey('WaterDeep', $map);
        $waterMesh = $world->getComponent($map['WaterSurface'], MeshRenderer::class);
        $this->assertSame('water_plane', $waterMesh->meshId);
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
