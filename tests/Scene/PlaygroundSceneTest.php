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
    public function testSceneMaterializesPlayer(): void
    {
        $world = new World();
        $scene = new PlaygroundScene();
        $builder = new SceneBuilder();
        $scene->build($builder);
        $entityMap = $builder->materialize($world);

        $this->assertArrayHasKey('Player', $entityMap);
        $playerId = $entityMap['Player'];
        $this->assertTrue($world->hasComponent($playerId, Transform3D::class));
        $this->assertTrue($world->hasComponent($playerId, Camera3DComponent::class));
        $this->assertTrue($world->hasComponent($playerId, CharacterController3D::class));
        $this->assertTrue($world->hasComponent($playerId, FirstPersonCamera::class));
    }

    public function testSceneHasGround(): void
    {
        $world = new World();
        $scene = new PlaygroundScene();
        $builder = new SceneBuilder();
        $scene->build($builder);
        $entityMap = $builder->materialize($world);

        $this->assertArrayHasKey('Ground', $entityMap);
        $groundId = $entityMap['Ground'];
        $this->assertTrue($world->hasComponent($groundId, MeshRenderer::class));

        $mesh = $world->getComponent($groundId, MeshRenderer::class);
        $this->assertSame('plane', $mesh->meshId);
        $this->assertSame('ground', $mesh->materialId);
    }

    public function testSceneHasDirectionalLight(): void
    {
        $world = new World();
        $scene = new PlaygroundScene();
        $builder = new SceneBuilder();
        $scene->build($builder);
        $entityMap = $builder->materialize($world);

        $this->assertArrayHasKey('Sun', $entityMap);
        $this->assertTrue($world->hasComponent($entityMap['Sun'], DirectionalLight::class));
    }

    public function testSceneHasBoxes(): void
    {
        $world = new World();
        $scene = new PlaygroundScene();
        $builder = new SceneBuilder();
        $scene->build($builder);
        $entityMap = $builder->materialize($world);

        for ($i = 0; $i < 5; $i++) {
            $this->assertArrayHasKey("Box_{$i}", $entityMap);
            $this->assertTrue($world->hasComponent($entityMap["Box_{$i}"], MeshRenderer::class));
        }
    }

    public function testSceneHasLightSpheres(): void
    {
        $world = new World();
        $scene = new PlaygroundScene();
        $builder = new SceneBuilder();
        $scene->build($builder);
        $entityMap = $builder->materialize($world);

        for ($i = 0; $i < 3; $i++) {
            $this->assertArrayHasKey("LightSphere_{$i}", $entityMap);
            $id = $entityMap["LightSphere_{$i}"];
            $this->assertTrue($world->hasComponent($id, MeshRenderer::class));
            $this->assertTrue($world->hasComponent($id, PointLight::class));
        }
    }
}
