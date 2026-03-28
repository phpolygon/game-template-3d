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
        return 'playground';
    }

    public function getConfig(): SceneConfig
    {
        $config = new SceneConfig();
        $config->clearColor = Color::hex('#1a1a2e');
        return $config;
    }

    public function build(SceneBuilder $builder): void
    {
        $this->registerMeshes();
        $this->registerMaterials();

        // Player with first-person camera
        $builder->entity('Player')
            ->with(new Transform3D(
                position: new Vec3(0.0, 2.0, 5.0),
            ))
            ->with(new Camera3DComponent(fov: 70.0, near: 0.1, far: 500.0))
            ->with(new CharacterController3D(height: 1.8, radius: 0.4))
            ->with(new FirstPersonCamera());

        // Ground plane
        $builder->entity('Ground')
            ->with(new Transform3D(
                position: Vec3::zero(),
                scale: new Vec3(50.0, 1.0, 50.0),
            ))
            ->with(new MeshRenderer(meshId: 'plane', materialId: 'ground'));

        // Directional light (sun)
        $builder->entity('Sun')
            ->with(new Transform3D())
            ->with(new DirectionalLight(
                direction: new Vec3(-0.5, -1.0, -0.3),
                color: Color::hex('#fff8e7'),
                intensity: 0.8,
            ));

        // Scattered boxes
        $boxPositions = [
            new Vec3(-6.0, 1.0, -4.0),
            new Vec3(4.0, 0.5, -8.0),
            new Vec3(-3.0, 1.5, -12.0),
            new Vec3(8.0, 1.0, -6.0),
            new Vec3(0.0, 2.0, -16.0),
        ];

        $materials = ['brick', 'metal', 'wood', 'brick', 'metal'];

        foreach ($boxPositions as $i => $pos) {
            $scale = 0.5 + ($i % 3) * 0.5;
            $builder->entity("Box_{$i}")
                ->with(new Transform3D(
                    position: $pos,
                    scale: new Vec3($scale, $scale, $scale),
                ))
                ->with(new MeshRenderer(meshId: 'box', materialId: $materials[$i]));
        }

        // Spheres with point lights
        $spheres = [
            ['pos' => new Vec3(-4.0, 2.0, -8.0), 'color' => '#ff4444', 'material' => 'emissive_red'],
            ['pos' => new Vec3(6.0, 2.0, -10.0), 'color' => '#44ff44', 'material' => 'emissive_green'],
            ['pos' => new Vec3(0.0, 3.0, -20.0), 'color' => '#4444ff', 'material' => 'emissive_blue'],
        ];

        foreach ($spheres as $i => $sphere) {
            $builder->entity("LightSphere_{$i}")
                ->with(new Transform3D(
                    position: $sphere['pos'],
                    scale: new Vec3(0.3, 0.3, 0.3),
                ))
                ->with(new MeshRenderer(meshId: 'sphere', materialId: $sphere['material']))
                ->with(new PointLight(
                    color: Color::hex($sphere['color']),
                    intensity: 2.0,
                    radius: 15.0,
                ));
        }
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
    }

    private function registerMaterials(): void
    {
        MaterialRegistry::register('ground', new Material(
            albedo: Color::hex('#445566'),
            roughness: 0.9,
        ));
        MaterialRegistry::register('brick', new Material(
            albedo: Color::hex('#8b4513'),
            roughness: 0.8,
        ));
        MaterialRegistry::register('metal', new Material(
            albedo: Color::hex('#aaaacc'),
            roughness: 0.2,
            metallic: 0.9,
        ));
        MaterialRegistry::register('wood', new Material(
            albedo: Color::hex('#a0724a'),
            roughness: 0.7,
        ));
        MaterialRegistry::register('emissive_red', new Material(
            albedo: Color::hex('#331111'),
            emission: Color::hex('#ff4444'),
        ));
        MaterialRegistry::register('emissive_green', new Material(
            albedo: Color::hex('#113311'),
            emission: Color::hex('#44ff44'),
        ));
        MaterialRegistry::register('emissive_blue', new Material(
            albedo: Color::hex('#111133'),
            emission: Color::hex('#4444ff'),
        ));
    }
}
