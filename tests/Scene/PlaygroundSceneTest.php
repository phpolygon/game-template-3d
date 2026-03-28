<?php

declare(strict_types=1);

namespace App\Tests\Scene;

use App\Component\FirstPersonCamera;
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

        $this->assertArrayHasKey('Ocean', $map);
        $ocean = $world->getComponent($map['Ocean'], MeshRenderer::class);
        $this->assertSame('water', $ocean->materialId);
    }

    public function testSceneHasPalmTrees(): void
    {
        ['world' => $world, 'map' => $map] = $this->buildScene();

        $trunkCount = 0;
        $canopyCount = 0;
        foreach ($map as $name => $id) {
            if (str_starts_with($name, 'PalmTrunk_')) {
                $mesh = $world->getComponent($id, MeshRenderer::class);
                $this->assertSame('cylinder', $mesh->meshId);
                $this->assertSame('palm_trunk', $mesh->materialId);
                $trunkCount++;
            }
            if (str_starts_with($name, 'PalmCanopy_')) {
                $mesh = $world->getComponent($id, MeshRenderer::class);
                $this->assertSame('sphere', $mesh->meshId);
                $this->assertSame('palm_leaves', $mesh->materialId);
                $canopyCount++;
            }
        }
        $this->assertSame(6, $trunkCount);
        $this->assertSame($trunkCount, $canopyCount);
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
        $this->assertGreaterThanOrEqual(7, $rockCount);
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
        // Sky blue: #87ceeb
        $this->assertGreaterThan(0.4, $config->clearColor->b);
    }
}
