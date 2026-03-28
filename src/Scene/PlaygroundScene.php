<?php

declare(strict_types=1);

namespace App\Scene;

use App\Component\FirstPersonCamera;
use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\DirectionalLight;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PointLight;
use PHPolygon\Component\Transform3D;
use PHPolygon\Geometry\BoxMesh;
use PHPolygon\Geometry\CylinderMesh;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Geometry\PlaneMesh;
use PHPolygon\Geometry\SphereMesh;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Scene\Scene;
use PHPolygon\Scene\SceneBuilder;
use PHPolygon\Scene\SceneConfig;

class PlaygroundScene extends Scene
{
    public function getName(): string
    {
        return 'beach';
    }

    public function getConfig(): SceneConfig
    {
        $config = new SceneConfig();
        $config->clearColor = Color::hex('#87ceeb'); // sky blue
        return $config;
    }

    public function build(SceneBuilder $builder): void
    {
        $this->registerMeshes();
        $this->registerMaterials();

        // Player — starts on the beach looking toward the water
        $builder->entity('Player')
            ->with(new Transform3D(
                position: new Vec3(0.0, 1.5, 8.0),
            ))
            ->with(new Camera3DComponent(fov: 70.0, near: 0.1, far: 500.0))
            ->with(new CharacterController3D(height: 1.8, radius: 0.4))
            ->with(new FirstPersonCamera());

        // Sun — warm afternoon light
        $builder->entity('Sun')
            ->with(new Transform3D())
            ->with(new DirectionalLight(
                direction: new Vec3(-0.4, -0.8, -0.3),
                color: Color::hex('#fff4e0'),
                intensity: 0.9,
            ));

        // Sand ground — large warm-colored plane
        $builder->entity('Sand')
            ->with(new Transform3D(
                position: new Vec3(0.0, 0.0, 0.0),
                scale: new Vec3(80.0, 1.0, 80.0),
            ))
            ->with(new MeshRenderer(meshId: 'plane', materialId: 'sand'));

        // Ocean — slightly lower blue plane extending to the horizon
        $builder->entity('Ocean')
            ->with(new Transform3D(
                position: new Vec3(0.0, -0.3, -50.0),
                scale: new Vec3(200.0, 1.0, 120.0),
            ))
            ->with(new MeshRenderer(meshId: 'plane', materialId: 'water'));

        // Shoreline — thin strip where sand meets water
        $builder->entity('Shoreline')
            ->with(new Transform3D(
                position: new Vec3(0.0, -0.1, -8.0),
                scale: new Vec3(80.0, 1.0, 4.0),
            ))
            ->with(new MeshRenderer(meshId: 'plane', materialId: 'wet_sand'));

        // Palm trees — trunk (cylinder) + canopy (sphere)
        $palmPositions = [
            new Vec3(-6.0, 0.0, 4.0),
            new Vec3(8.0, 0.0, 6.0),
            new Vec3(-12.0, 0.0, 10.0),
            new Vec3(3.0, 0.0, 14.0),
            new Vec3(15.0, 0.0, 3.0),
            new Vec3(-18.0, 0.0, 8.0),
        ];

        foreach ($palmPositions as $i => $pos) {
            $trunkHeight = 4.0 + ($i % 3) * 0.5;
            $canopySize = 2.0 + ($i % 2) * 0.5;

            // Trunk
            $builder->entity("PalmTrunk_{$i}")
                ->with(new Transform3D(
                    position: new Vec3($pos->x, $trunkHeight * 0.5, $pos->z),
                    scale: new Vec3(0.25, $trunkHeight * 0.5, 0.25),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: 'palm_trunk'));

            // Canopy
            $builder->entity("PalmCanopy_{$i}")
                ->with(new Transform3D(
                    position: new Vec3($pos->x, $trunkHeight + $canopySize * 0.3, $pos->z),
                    scale: new Vec3($canopySize, $canopySize * 0.6, $canopySize),
                ))
                ->with(new MeshRenderer(meshId: 'sphere', materialId: 'palm_leaves'));
        }

        // Rocks — scattered dark boulders along the beach
        $rockPositions = [
            ['pos' => new Vec3(-4.0, 0.3, -3.0), 'scale' => new Vec3(1.2, 0.8, 1.0)],
            ['pos' => new Vec3(5.0, 0.2, -5.0), 'scale' => new Vec3(0.8, 0.5, 0.9)],
            ['pos' => new Vec3(-10.0, 0.4, 2.0), 'scale' => new Vec3(1.5, 1.0, 1.3)],
            ['pos' => new Vec3(12.0, 0.25, -2.0), 'scale' => new Vec3(0.7, 0.6, 0.8)],
            ['pos' => new Vec3(-2.0, 0.5, -6.0), 'scale' => new Vec3(2.0, 1.2, 1.8)],
            ['pos' => new Vec3(9.0, 0.15, 0.0), 'scale' => new Vec3(0.5, 0.4, 0.6)],
            ['pos' => new Vec3(-8.0, 0.35, -4.0), 'scale' => new Vec3(1.0, 0.7, 1.1)],
        ];

        foreach ($rockPositions as $i => $rock) {
            $builder->entity("Rock_{$i}")
                ->with(new Transform3D(
                    position: $rock['pos'],
                    scale: $rock['scale'],
                ))
                ->with(new MeshRenderer(meshId: 'box', materialId: 'rock'));
        }

        // Beach shells / pebbles — small spheres for detail
        $pebblePositions = [
            new Vec3(1.0, 0.05, 3.0),
            new Vec3(-3.0, 0.05, 7.0),
            new Vec3(6.0, 0.05, 5.0),
            new Vec3(-7.0, 0.05, 1.0),
            new Vec3(4.0, 0.05, 10.0),
        ];

        foreach ($pebblePositions as $i => $pos) {
            $builder->entity("Pebble_{$i}")
                ->with(new Transform3D(
                    position: $pos,
                    scale: new Vec3(0.08, 0.06, 0.08),
                ))
                ->with(new MeshRenderer(meshId: 'sphere', materialId: 'shell'));
        }

        // Warm point light near the shore — sunset glow
        $builder->entity('SunsetGlow')
            ->with(new Transform3D(position: new Vec3(0.0, 3.0, -6.0)))
            ->with(new PointLight(
                color: Color::hex('#ffaa55'),
                intensity: 1.5,
                radius: 20.0,
            ));
    }

    private function registerMeshes(): void
    {
        if (!MeshRegistry::has('box')) {
            MeshRegistry::register('box', BoxMesh::generate(2.0, 2.0, 2.0));
        }
        if (!MeshRegistry::has('sphere')) {
            MeshRegistry::register('sphere', SphereMesh::generate(1.0, 16, 24));
        }
        if (!MeshRegistry::has('plane')) {
            MeshRegistry::register('plane', PlaneMesh::generate(1.0, 1.0));
        }
        if (!MeshRegistry::has('cylinder')) {
            MeshRegistry::register('cylinder', CylinderMesh::generate(1.0, 2.0, 12));
        }
    }

    private function registerMaterials(): void
    {
        // Sand — warm beige
        MaterialRegistry::register('sand', new Material(
            albedo: Color::hex('#deb887'),
            roughness: 0.95,
        ));

        // Wet sand near water
        MaterialRegistry::register('wet_sand', new Material(
            albedo: Color::hex('#c4a265'),
            roughness: 0.7,
        ));

        // Ocean water — dark blue-green, slightly metallic for reflections
        MaterialRegistry::register('water', new Material(
            albedo: Color::hex('#1a6b8a'),
            roughness: 0.1,
            metallic: 0.3,
        ));

        // Palm trunk — dark brown
        MaterialRegistry::register('palm_trunk', new Material(
            albedo: Color::hex('#5a3a1a'),
            roughness: 0.9,
        ));

        // Palm leaves — rich green
        MaterialRegistry::register('palm_leaves', new Material(
            albedo: Color::hex('#2d6b2d'),
            roughness: 0.8,
        ));

        // Rocks — dark grey
        MaterialRegistry::register('rock', new Material(
            albedo: Color::hex('#4a4a4a'),
            roughness: 0.85,
        ));

        // Shells / pebbles — off-white
        MaterialRegistry::register('shell', new Material(
            albedo: Color::hex('#e8dcc8'),
            roughness: 0.6,
        ));
    }
}
