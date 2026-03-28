<?php

declare(strict_types=1);

namespace App\Scene;

use App\Component\CloudDrift;
use App\Component\FirstPersonCamera;
use App\Component\PalmSway;
use App\Component\WaveStrip;
use App\Component\Wind;
use PHPolygon\Component\BoxCollider3D;
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
        $config->clearColor = Color::hex('#5b9bd5');
        return $config;
    }

    public function build(SceneBuilder $builder): void
    {
        $this->registerMeshes();
        $this->registerMaterials();

        $this->buildPlayer($builder);
        $this->buildLighting($builder);
        $this->buildWind($builder);
        $this->buildTerrain($builder);
        $this->buildOceanAndWaves($builder);
        $this->buildPalmTrees($builder);
        $this->buildRocks($builder);
        $this->buildBeachDetails($builder);
        $this->buildClouds($builder);
    }

    private function buildPlayer(SceneBuilder $builder): void
    {
        $builder->entity('Player')
            ->with(new Transform3D(
                position: new Vec3(0.0, 1.5, 12.0),
            ))
            ->with(new Camera3DComponent(fov: 70.0, near: 0.1, far: 500.0))
            ->with(new CharacterController3D(height: 1.8, radius: 0.4))
            ->with(new FirstPersonCamera());
    }

    private function buildLighting(SceneBuilder $builder): void
    {
        // Sun light — direction points FROM sun TO scene (high up, slightly behind player)
        $builder->entity('Sun')
            ->with(new Transform3D())
            ->with(new DirectionalLight(
                direction: new Vec3(-0.3, -0.8, 0.3),
                color: Color::hex('#fff8e8'),
                intensity: 1.1,
            ));

        // Visible sun sphere high in the sky
        $builder->entity('SunDisc')
            ->with(new Transform3D(
                position: new Vec3(40.0, 80.0, -40.0),
                scale: new Vec3(6.0, 6.0, 6.0),
            ))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'sun_disc'));

        // Sun glow halo (larger, dimmer sphere behind sun)
        $builder->entity('SunGlow')
            ->with(new Transform3D(
                position: new Vec3(40.0, 80.0, -41.0),
                scale: new Vec3(12.0, 12.0, 12.0),
            ))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'sun_glow'));

        $builder->entity('FillLight')
            ->with(new Transform3D())
            ->with(new DirectionalLight(
                direction: new Vec3(0.5, -0.3, 0.2),
                color: Color::hex('#b0d4f1'),
                intensity: 0.25,
            ));

        $builder->entity('SunsetGlow')
            ->with(new Transform3D(position: new Vec3(40.0, 30.0, -30.0)))
            ->with(new PointLight(
                color: Color::hex('#ffcc77'),
                intensity: 2.5,
                radius: 60.0,
            ));

        $builder->entity('ShoreGlow')
            ->with(new Transform3D(position: new Vec3(0.0, 2.0, -5.0)))
            ->with(new PointLight(
                color: Color::hex('#aaddff'),
                intensity: 0.6,
                radius: 25.0,
            ));
    }

    private function buildWind(SceneBuilder $builder): void
    {
        $wind = new Wind();
        $wind->maxIntensity = 1.2;
        $wind->minIntensity = 0.15;
        $wind->gustFrequency = 0.25;
        $builder->entity('WindController')
            ->with(new Transform3D())
            ->with($wind);
    }

    private function buildTerrain(SceneBuilder $builder): void
    {
        // Main sand beach
        $builder->entity('Sand')
            ->with(new Transform3D(
                position: new Vec3(0.0, 0.0, 10.0),
                scale: new Vec3(100.0, 1.0, 60.0),
            ))
            ->with(new MeshRenderer(meshId: 'plane', materialId: 'sand'));

        // Sand color variations for visual depth (raised enough to avoid z-fighting)
        $sandPatches = [
            ['pos' => new Vec3(-8.0, 0.05, 6.0), 'scale' => new Vec3(12.0, 1.0, 8.0), 'mat' => 'sand_light'],
            ['pos' => new Vec3(10.0, 0.06, 15.0), 'scale' => new Vec3(10.0, 1.0, 6.0), 'mat' => 'sand_warm'],
            ['pos' => new Vec3(-15.0, 0.07, 18.0), 'scale' => new Vec3(8.0, 1.0, 10.0), 'mat' => 'sand_light'],
            ['pos' => new Vec3(5.0, 0.08, 4.0), 'scale' => new Vec3(14.0, 1.0, 5.0), 'mat' => 'sand_dark'],
            ['pos' => new Vec3(20.0, 0.05, 10.0), 'scale' => new Vec3(6.0, 1.0, 12.0), 'mat' => 'sand_warm'],
        ];

        foreach ($sandPatches as $i => $p) {
            $builder->entity("SandPatch_{$i}")
                ->with(new Transform3D(position: $p['pos'], scale: $p['scale']))
                ->with(new MeshRenderer(meshId: 'plane', materialId: $p['mat']));
        }

        // Dunes — raised sand areas for 3D terrain feel
        $dunes = [
            ['pos' => new Vec3(-12.0, 0.6, 22.0), 'scale' => new Vec3(8.0, 1.2, 6.0)],
            ['pos' => new Vec3(14.0, 0.4, 25.0), 'scale' => new Vec3(6.0, 0.8, 5.0)],
            ['pos' => new Vec3(-22.0, 0.5, 18.0), 'scale' => new Vec3(5.0, 1.0, 4.0)],
            ['pos' => new Vec3(25.0, 0.35, 15.0), 'scale' => new Vec3(7.0, 0.7, 5.0)],
            ['pos' => new Vec3(0.0, 0.3, 30.0), 'scale' => new Vec3(10.0, 0.6, 8.0)],
            ['pos' => new Vec3(-30.0, 0.45, 25.0), 'scale' => new Vec3(6.0, 0.9, 5.0)],
        ];

        foreach ($dunes as $i => $d) {
            $builder->entity("Dune_{$i}")
                ->with(new Transform3D(position: $d['pos'], scale: $d['scale']))
                ->with(new MeshRenderer(meshId: 'sphere', materialId: ($i % 2 === 0) ? 'sand' : 'sand_warm'));
        }

        // Wet sand near shoreline (below main sand level to avoid z-fighting)
        $builder->entity('WetSand')
            ->with(new Transform3D(
                position: new Vec3(0.0, -0.15, -5.0),
                scale: new Vec3(100.0, 1.0, 8.0),
            ))
            ->with(new MeshRenderer(meshId: 'plane', materialId: 'wet_sand'));

        // Tide line — thin darker strip (between sand and wet sand)
        $builder->entity('TideLine')
            ->with(new Transform3D(
                position: new Vec3(0.0, -0.08, -1.5),
                scale: new Vec3(100.0, 1.0, 0.5),
            ))
            ->with(new MeshRenderer(meshId: 'plane', materialId: 'tide_line'));
    }

    private function buildOceanAndWaves(SceneBuilder $builder): void
    {
        // Deep ocean backdrop
        $builder->entity('DeepOcean')
            ->with(new Transform3D(
                position: new Vec3(0.0, -0.6, -80.0),
                scale: new Vec3(300.0, 1.0, 200.0),
            ))
            ->with(new MeshRenderer(meshId: 'plane', materialId: 'deep_water'));

        // Animated wave strips
        $waveCount = 20;
        for ($i = 0; $i < $waveCount; $i++) {
            $z = -8.0 - $i * 3.0;
            $depth = $i / (float) $waveCount;

            $wave = new WaveStrip();
            $wave->phaseOffset = $i * 0.8;
            $wave->amplitude = 0.2 + $depth * 0.4;
            $wave->frequency = 1.2 + $depth * 0.3;
            $wave->baseY = -0.25 - $depth * 0.3;

            $matId = $depth < 0.3 ? 'water_shallow' : ($depth < 0.6 ? 'water' : 'water_deep');

            $builder->entity("Wave_{$i}")
                ->with(new Transform3D(
                    position: new Vec3(0.0, $wave->baseY, $z),
                    scale: new Vec3(100.0, 1.0, 3.2),
                ))
                ->with(new MeshRenderer(meshId: 'plane', materialId: $matId))
                ->with($wave);

            // Foam on every 3rd wave near shore
            if ($i < 8 && $i % 2 === 0) {
                $foam = new WaveStrip();
                $foam->phaseOffset = $i * 0.8;
                $foam->amplitude = 0.2 + $depth * 0.3;
                $foam->frequency = 1.2 + $depth * 0.3;
                $foam->baseY = -0.2 - $depth * 0.3;
                $foam->isFoam = true;

                $builder->entity("Foam_{$i}")
                    ->with(new Transform3D(
                        position: new Vec3(0.0, -10.0, $z + 0.5),
                        scale: new Vec3(80.0, 1.0, 1.0),
                    ))
                    ->with(new MeshRenderer(meshId: 'plane', materialId: 'foam'))
                    ->with($foam);
            }
        }

        // Shore foam — persistent white line at water's edge (above wet sand)
        $builder->entity('ShoreFoam')
            ->with(new Transform3D(
                position: new Vec3(0.0, -0.1, -8.0),
                scale: new Vec3(100.0, 1.0, 1.2),
            ))
            ->with(new MeshRenderer(meshId: 'plane', materialId: 'shore_foam'));
    }

    private function buildPalmTrees(SceneBuilder $builder): void
    {
        $palms = [
            ['pos' => new Vec3(-6.0, 0.0, 5.0), 'height' => 5.5, 'lean' => 0.15],
            ['pos' => new Vec3(8.0, 0.0, 7.0), 'height' => 6.0, 'lean' => -0.1],
            ['pos' => new Vec3(-14.0, 0.0, 12.0), 'height' => 4.5, 'lean' => 0.2],
            ['pos' => new Vec3(3.0, 0.0, 16.0), 'height' => 5.0, 'lean' => -0.08],
            ['pos' => new Vec3(16.0, 0.0, 4.0), 'height' => 5.8, 'lean' => 0.12],
            ['pos' => new Vec3(-20.0, 0.0, 9.0), 'height' => 4.8, 'lean' => 0.18],
            ['pos' => new Vec3(-3.0, 0.0, 20.0), 'height' => 6.2, 'lean' => -0.05],
            ['pos' => new Vec3(22.0, 0.0, 10.0), 'height' => 5.2, 'lean' => 0.1],
            ['pos' => new Vec3(-25.0, 0.0, 16.0), 'height' => 4.6, 'lean' => 0.22],
            ['pos' => new Vec3(12.0, 0.0, 18.0), 'height' => 5.6, 'lean' => -0.13],
        ];

        foreach ($palms as $i => $palm) {
            $pos = $palm['pos'];
            $h = $palm['height'];
            $lean = $palm['lean'];

            // Trunk — lower section
            $trunkSway = new PalmSway();
            $trunkSway->swayStrength = 0.6 + fmod($i * 0.1, 0.4);
            $trunkSway->phaseOffset = $i * 1.3;
            $trunkSway->isTrunk = true;

            $builder->entity("PalmTrunk_{$i}")
                ->with(new Transform3D(
                    position: new Vec3($pos->x, $h * 0.5, $pos->z),
                    rotation: Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), $lean),
                    scale: new Vec3(0.2, $h * 0.5, 0.2),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: ($i % 3 === 0) ? 'palm_trunk_dark' : 'palm_trunk'))
                ->with($trunkSway)
                ->with(new BoxCollider3D(size: new Vec3(0.8, 2.0, 0.8), offset: new Vec3(0.0, 0.0, 0.0), isStatic: true));

            // Trunk rings for texture detail
            for ($r = 0; $r < 3; $r++) {
                $ringY = $h * 0.2 + $r * ($h * 0.25);
                $builder->entity("TrunkRing_{$i}_{$r}")
                    ->with(new Transform3D(
                        position: new Vec3($pos->x, $ringY, $pos->z),
                        scale: new Vec3(0.25, 0.06, 0.25),
                    ))
                    ->with(new MeshRenderer(meshId: 'cylinder', materialId: 'palm_trunk_ring'));
            }

            // Canopy — multiple leaf clusters
            $canopyBase = $h + 0.2;
            $leafOffsets = [
                new Vec3(0.0, 0.0, 0.0),
                new Vec3(0.8, -0.2, 0.4),
                new Vec3(-0.7, -0.3, -0.5),
                new Vec3(0.3, -0.1, -0.8),
                new Vec3(-0.5, -0.15, 0.7),
            ];

            foreach ($leafOffsets as $j => $off) {
                $leafSway = new PalmSway();
                $leafSway->swayStrength = 0.8 + ($j * 0.15);
                $leafSway->phaseOffset = $i * 1.3 + $j * 0.7;
                $leafSway->isTrunk = false;

                $leafScale = $j === 0
                    ? new Vec3(1.8, 0.4, 1.8)
                    : new Vec3(1.2 + $j * 0.1, 0.25, 1.0 + $j * 0.1);

                $matId = $j % 2 === 0 ? 'palm_leaves' : 'palm_leaves_light';

                $builder->entity("PalmLeaf_{$i}_{$j}")
                    ->with(new Transform3D(
                        position: new Vec3(
                            $pos->x + $off->x,
                            $canopyBase + $off->y,
                            $pos->z + $off->z,
                        ),
                        scale: $leafScale,
                    ))
                    ->with(new MeshRenderer(meshId: 'sphere', materialId: $matId))
                    ->with($leafSway);
            }

            // Coconuts
            if ($i % 3 === 0) {
                for ($c = 0; $c < 3; $c++) {
                    $angle = $c * 2.094;
                    $builder->entity("Coconut_{$i}_{$c}")
                        ->with(new Transform3D(
                            position: new Vec3(
                                $pos->x + cos($angle) * 0.5,
                                $canopyBase - 0.5,
                                $pos->z + sin($angle) * 0.5,
                            ),
                            scale: new Vec3(0.12, 0.12, 0.12),
                        ))
                        ->with(new MeshRenderer(meshId: 'sphere', materialId: 'coconut'));
                }
            }
        }
    }

    private function buildRocks(SceneBuilder $builder): void
    {
        $rocks = [
            ['pos' => new Vec3(-4.0, 0.4, -3.0), 'scale' => new Vec3(1.5, 1.0, 1.2), 'mat' => 'rock'],
            ['pos' => new Vec3(5.0, 0.25, -5.0), 'scale' => new Vec3(0.9, 0.6, 1.0), 'mat' => 'rock_dark'],
            ['pos' => new Vec3(-10.0, 0.5, 2.0), 'scale' => new Vec3(1.8, 1.2, 1.5), 'mat' => 'rock'],
            ['pos' => new Vec3(12.0, 0.3, -2.0), 'scale' => new Vec3(0.8, 0.7, 0.9), 'mat' => 'rock_mossy'],
            ['pos' => new Vec3(-2.0, 0.6, -6.0), 'scale' => new Vec3(2.2, 1.4, 2.0), 'mat' => 'rock_dark'],
            ['pos' => new Vec3(9.0, 0.2, 1.0), 'scale' => new Vec3(0.6, 0.5, 0.7), 'mat' => 'rock'],
            ['pos' => new Vec3(-8.0, 0.4, -4.0), 'scale' => new Vec3(1.2, 0.8, 1.3), 'mat' => 'rock_mossy'],
            ['pos' => new Vec3(18.0, 0.35, -1.0), 'scale' => new Vec3(1.0, 0.9, 1.1), 'mat' => 'rock_dark'],
            ['pos' => new Vec3(-16.0, 0.45, 0.0), 'scale' => new Vec3(1.6, 1.1, 1.4), 'mat' => 'rock'],
            ['pos' => new Vec3(7.0, 0.15, -7.0), 'scale' => new Vec3(0.5, 0.35, 0.6), 'mat' => 'rock_dark'],
        ];

        foreach ($rocks as $i => $rock) {
            $rotation = Quaternion::fromEuler(
                sin($i * 1.7) * 0.2,
                $i * 0.8,
                cos($i * 2.3) * 0.15,
            );

            $builder->entity("Rock_{$i}")
                ->with(new Transform3D(
                    position: $rock['pos'],
                    rotation: $rotation,
                    scale: $rock['scale'],
                ))
                ->with(new MeshRenderer(meshId: ($i % 3 === 0) ? 'sphere' : 'box', materialId: $rock['mat']))
                ->with(new BoxCollider3D(size: new Vec3(2.0, 2.0, 2.0), isStatic: true));
        }
    }

    private function buildBeachDetails(SceneBuilder $builder): void
    {
        // Shells scattered on the beach
        $shells = [
            new Vec3(1.0, 0.03, 3.0), new Vec3(-3.0, 0.03, 7.0),
            new Vec3(6.0, 0.03, 5.0), new Vec3(-7.0, 0.03, 1.0),
            new Vec3(4.0, 0.03, 10.0), new Vec3(-1.5, 0.03, 8.0),
            new Vec3(10.0, 0.03, 6.0), new Vec3(-5.0, 0.03, 11.0),
            new Vec3(2.5, 0.03, 14.0), new Vec3(-9.0, 0.03, 4.0),
            new Vec3(7.5, 0.03, 13.0), new Vec3(-11.0, 0.03, 6.0),
        ];

        foreach ($shells as $i => $pos) {
            $matId = $i % 3 === 0 ? 'shell_pink' : ($i % 3 === 1 ? 'shell' : 'shell_cream');
            $builder->entity("Shell_{$i}")
                ->with(new Transform3D(
                    position: $pos,
                    rotation: Quaternion::fromEuler(0.0, $i * 1.2, 0.0),
                    scale: new Vec3(0.06 + ($i % 3) * 0.02, 0.03, 0.08 + ($i % 2) * 0.02),
                ))
                ->with(new MeshRenderer(meshId: 'sphere', materialId: $matId));
        }

        // Driftwood pieces
        $driftwood = [
            ['pos' => new Vec3(-5.0, 0.08, -2.0), 'rot' => 0.7, 'len' => 2.0],
            ['pos' => new Vec3(8.0, 0.06, 3.0), 'rot' => -0.4, 'len' => 1.5],
            ['pos' => new Vec3(-2.0, 0.07, -4.0), 'rot' => 1.2, 'len' => 1.8],
            ['pos' => new Vec3(15.0, 0.05, 1.0), 'rot' => 0.3, 'len' => 1.2],
        ];

        foreach ($driftwood as $i => $dw) {
            $builder->entity("Driftwood_{$i}")
                ->with(new Transform3D(
                    position: $dw['pos'],
                    rotation: Quaternion::fromEuler(0.0, $dw['rot'], 0.1),
                    scale: new Vec3(0.08, 0.06, $dw['len'] * 0.5),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: 'driftwood'));
        }

        // Seaweed patches near waterline
        for ($i = 0; $i < 8; $i++) {
            $builder->entity("Seaweed_{$i}")
                ->with(new Transform3D(
                    position: new Vec3(-20.0 + $i * 6.0 + sin($i) * 2.0, -0.06, -6.5 + cos($i) * 1.0),
                    scale: new Vec3(0.8 + sin($i) * 0.3, 1.0, 0.4),
                ))
                ->with(new MeshRenderer(meshId: 'plane', materialId: 'seaweed'));
        }
    }

    private function buildClouds(SceneBuilder $builder): void
    {
        $clouds = [
            ['x' => -30.0, 'y' => 35.0, 'z' => -60.0, 'speed' => 1.5, 'parts' => 5],
            ['x' => 10.0, 'y' => 40.0, 'z' => -50.0, 'speed' => 1.0, 'parts' => 4],
            ['x' => 50.0, 'y' => 38.0, 'z' => -70.0, 'speed' => 1.8, 'parts' => 6],
            ['x' => -60.0, 'y' => 42.0, 'z' => -55.0, 'speed' => 0.8, 'parts' => 5],
            ['x' => 30.0, 'y' => 36.0, 'z' => -65.0, 'speed' => 1.3, 'parts' => 4],
            ['x' => -15.0, 'y' => 44.0, 'z' => -80.0, 'speed' => 0.6, 'parts' => 7],
            ['x' => 70.0, 'y' => 37.0, 'z' => -45.0, 'speed' => 1.6, 'parts' => 5],
            ['x' => -45.0, 'y' => 39.0, 'z' => -75.0, 'speed' => 0.9, 'parts' => 4],
            ['x' => 5.0, 'y' => 43.0, 'z' => -90.0, 'speed' => 0.7, 'parts' => 6],
            ['x' => -70.0, 'y' => 41.0, 'z' => -58.0, 'speed' => 1.1, 'parts' => 5],
            ['x' => 45.0, 'y' => 45.0, 'z' => -85.0, 'speed' => 0.5, 'parts' => 4],
            ['x' => -50.0, 'y' => 34.0, 'z' => -42.0, 'speed' => 2.0, 'parts' => 3],
        ];

        foreach ($clouds as $ci => $cloud) {
            for ($p = 0; $p < $cloud['parts']; $p++) {
                $offsetX = (sin($p * 2.1 + $ci) * 3.0);
                $offsetY = (cos($p * 1.7) * 1.0);
                $offsetZ = (sin($p * 0.9 + $ci * 0.5) * 2.0);

                $scaleX = 3.0 + sin($p * 1.3 + $ci) * 1.5;
                $scaleY = 1.0 + cos($p * 0.8) * 0.5;
                $scaleZ = 2.5 + cos($p * 1.7 + $ci) * 1.0;

                $drift = new CloudDrift();
                $drift->speed = $cloud['speed'];
                $drift->resetMinX = -100.0;
                $drift->resetMaxX = 100.0;
                $drift->bobAmplitude = 0.2 + $p * 0.05;
                $drift->bobFrequency = 0.15 + $ci * 0.02;
                $drift->phaseOffset = $ci * 2.0 + $p * 0.5;

                $matId = ($p + $ci) % 3 === 0 ? 'cloud_bright' : (($p + $ci) % 3 === 1 ? 'cloud' : 'cloud_shadow');

                $builder->entity("Cloud_{$ci}_{$p}")
                    ->with(new Transform3D(
                        position: new Vec3(
                            $cloud['x'] + $offsetX,
                            $cloud['y'] + $offsetY,
                            $cloud['z'] + $offsetZ,
                        ),
                        scale: new Vec3($scaleX, $scaleY, $scaleZ),
                    ))
                    ->with(new MeshRenderer(meshId: 'sphere', materialId: $matId))
                    ->with($drift);
            }
        }
    }

    private function registerMeshes(): void
    {
        if (!MeshRegistry::has('box')) {
            MeshRegistry::register('box', BoxMesh::generate(2.0, 2.0, 2.0));
        }
        if (!MeshRegistry::has('sphere')) {
            MeshRegistry::register('sphere', SphereMesh::generate(1.0, 20, 30));
        }
        if (!MeshRegistry::has('plane')) {
            MeshRegistry::register('plane', PlaneMesh::generate(1.0, 1.0));
        }
        if (!MeshRegistry::has('cylinder')) {
            MeshRegistry::register('cylinder', CylinderMesh::generate(1.0, 2.0, 16));
        }
    }

    private function registerMaterials(): void
    {
        // Sand variants
        MaterialRegistry::register('sand', new Material(
            albedo: Color::hex('#deb887'),
            roughness: 0.95,
        ));
        MaterialRegistry::register('sand_light', new Material(
            albedo: Color::hex('#f0dbb0'),
            roughness: 0.92,
        ));
        MaterialRegistry::register('sand_warm', new Material(
            albedo: Color::hex('#d4a862'),
            roughness: 0.93,
        ));
        MaterialRegistry::register('sand_dark', new Material(
            albedo: Color::hex('#c49a5c'),
            roughness: 0.96,
        ));

        // Wet sand / tide
        MaterialRegistry::register('wet_sand', new Material(
            albedo: Color::hex('#a08050'),
            roughness: 0.5,
            metallic: 0.1,
        ));
        MaterialRegistry::register('tide_line', new Material(
            albedo: Color::hex('#8b7040'),
            roughness: 0.4,
            metallic: 0.15,
        ));

        // Sun
        MaterialRegistry::register('sun_disc', new Material(
            albedo: Color::hex('#fffde0'),
            roughness: 1.0,
            emission: Color::hex('#ffee88'),
        ));
        MaterialRegistry::register('sun_glow', new Material(
            albedo: Color::hex('#fff8cc'),
            roughness: 1.0,
            emission: Color::hex('#aa8833'),
        ));

        // Footprints
        MaterialRegistry::register('footprint', new Material(
            albedo: Color::hex('#9a7a4a'),
            roughness: 0.85,
        ));

        // Water variants
        MaterialRegistry::register('water_shallow', new Material(
            albedo: Color::hex('#2a9baa'),
            roughness: 0.05,
            metallic: 0.4,
        ));
        MaterialRegistry::register('water', new Material(
            albedo: Color::hex('#1a7b9a'),
            roughness: 0.08,
            metallic: 0.35,
        ));
        MaterialRegistry::register('water_deep', new Material(
            albedo: Color::hex('#0a4b6a'),
            roughness: 0.1,
            metallic: 0.3,
        ));
        MaterialRegistry::register('deep_water', new Material(
            albedo: Color::hex('#052a4a'),
            roughness: 0.12,
            metallic: 0.25,
        ));

        // Foam
        MaterialRegistry::register('foam', new Material(
            albedo: Color::hex('#e8f0f8'),
            roughness: 0.9,
            emission: Color::hex('#222222'),
        ));
        MaterialRegistry::register('shore_foam', new Material(
            albedo: Color::hex('#d0e8f0'),
            roughness: 0.8,
            emission: Color::hex('#111111'),
        ));

        // Palm trunk variants
        MaterialRegistry::register('palm_trunk', new Material(
            albedo: Color::hex('#5a3a1a'),
            roughness: 0.92,
        ));
        MaterialRegistry::register('palm_trunk_dark', new Material(
            albedo: Color::hex('#3d2510'),
            roughness: 0.95,
        ));
        MaterialRegistry::register('palm_trunk_ring', new Material(
            albedo: Color::hex('#4a2e14'),
            roughness: 0.88,
        ));

        // Palm leaves variants
        MaterialRegistry::register('palm_leaves', new Material(
            albedo: Color::hex('#2d6b2d'),
            roughness: 0.8,
        ));
        MaterialRegistry::register('palm_leaves_light', new Material(
            albedo: Color::hex('#3d8b3d'),
            roughness: 0.75,
        ));

        // Coconuts
        MaterialRegistry::register('coconut', new Material(
            albedo: Color::hex('#5c3b10'),
            roughness: 0.7,
        ));

        // Rock variants
        MaterialRegistry::register('rock', new Material(
            albedo: Color::hex('#4a4a4a'),
            roughness: 0.85,
        ));
        MaterialRegistry::register('rock_dark', new Material(
            albedo: Color::hex('#2e2e2e'),
            roughness: 0.9,
        ));
        MaterialRegistry::register('rock_mossy', new Material(
            albedo: Color::hex('#3a4a35'),
            roughness: 0.88,
        ));

        // Shells
        MaterialRegistry::register('shell', new Material(
            albedo: Color::hex('#e8dcc8'),
            roughness: 0.6,
        ));
        MaterialRegistry::register('shell_pink', new Material(
            albedo: Color::hex('#e8c8c0'),
            roughness: 0.55,
        ));
        MaterialRegistry::register('shell_cream', new Material(
            albedo: Color::hex('#f0e8d0'),
            roughness: 0.65,
        ));

        // Driftwood
        MaterialRegistry::register('driftwood', new Material(
            albedo: Color::hex('#8b7355'),
            roughness: 0.9,
        ));

        // Seaweed
        MaterialRegistry::register('seaweed', new Material(
            albedo: Color::hex('#1a4a2a'),
            roughness: 0.75,
        ));

        // Clouds
        MaterialRegistry::register('cloud', new Material(
            albedo: Color::hex('#f0f0f5'),
            roughness: 1.0,
            emission: Color::hex('#333333'),
        ));
        MaterialRegistry::register('cloud_bright', new Material(
            albedo: Color::hex('#ffffff'),
            roughness: 1.0,
            emission: Color::hex('#444444'),
        ));
        MaterialRegistry::register('cloud_shadow', new Material(
            albedo: Color::hex('#c0c5d0'),
            roughness: 1.0,
            emission: Color::hex('#222222'),
        ));
    }
}
