<?php

declare(strict_types=1);

namespace App\Scene;

use App\Component\CloudDrift;
use App\Component\FirstPersonCamera;
use App\Component\PlayerBody;
use App\Geometry\PlankWallMesh;
use App\Prefab\SandGrain;
use App\Prefab\WaterPixel;
use PHPolygon\Component\InstancedTerrain;
use PHPolygon\Math\Mat4;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\DayNightCycle;
use PHPolygon\Prefab\Door\DoorBuilder;
use PHPolygon\Prefab\Door\DoorMaterials;
use PHPolygon\Prefab\Furniture\CrateBuilder;
use PHPolygon\Prefab\Furniture\FurnitureMaterials;
use PHPolygon\Prefab\Furniture\HammockBuilder;
use PHPolygon\Prefab\Furniture\LanternBuilder;
use PHPolygon\Prefab\Furniture\ShelfBuilder;
use PHPolygon\Prefab\Furniture\TableBuilder;
use PHPolygon\Prefab\Furniture\WindowBuilder;
use App\Component\Wind;
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
use PHPolygon\Rendering\Materials\FabricMaterials;
use PHPolygon\Rendering\Materials\MetalMaterials;
use PHPolygon\Rendering\Materials\ThatchMaterials;
use PHPolygon\Rendering\Materials\WoodMaterials;
use PHPolygon\Prefab\Roof\RoofBuilder;
use PHPolygon\Prefab\Roof\RoofMaterials;
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
        // Fallback behind sky dome — deep blue (only visible through gaps)
        $config->clearColor = Color::hex('#1E5FAA');
        return $config;
    }

    public function build(SceneBuilder $builder): void
    {
        $this->registerMeshes();
        $this->registerMaterials();

        $this->buildSkyDome($builder);
        $this->buildPlayer($builder);
        $this->buildLighting($builder);
        $this->buildWind($builder);

        // Day/night cycle — 10 minute full day, start at morning
        $builder->entity('DayNight')
            ->with(new Transform3D())
            ->with(new DayNightCycle(
                timeOfDay: 0.35,
                dayDuration: 60.0,
            ));
        $this->buildTerrain($builder);
        $this->buildOceanAndWaves($builder);
        $this->buildPalmTrees($builder);
        $this->buildRocks($builder);
        $this->buildBeachDetails($builder);
        $this->buildBeachHut($builder);
        $this->buildClouds($builder);
    }

    private function buildPlayer(SceneBuilder $builder): void
    {
        $builder->entity('Player')
            ->with(new Transform3D(
                position: new Vec3(0.0, 1.5, 12.0),
            ))
            ->with(new Camera3DComponent(fov: 70.0, near: 0.3, far: 500.0))
            ->with(new CharacterController3D(height: 1.8, radius: 0.3))
            ->with(new FirstPersonCamera());

        // Visible player body — two legs visible when looking down
        $builder->entity('PlayerBody')
            ->with(new Transform3D(
                position: new Vec3(0.0, 0.3, 12.0),
                scale: new Vec3(0.3, 0.6, 0.3),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: 'player_body'))
            ->with(new PlayerBody());

        // Left foot
        $builder->entity('PlayerFootL')
            ->with(new Transform3D(
                position: new Vec3(-0.15, 0.05, 11.7),
                scale: new Vec3(0.12, 0.08, 0.25),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: 'player_shoe'))
            ->with(new PlayerBody());

        // Right foot
        $builder->entity('PlayerFootR')
            ->with(new Transform3D(
                position: new Vec3(0.15, 0.05, 11.7),
                scale: new Vec3(0.12, 0.08, 0.25),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: 'player_shoe'))
            ->with(new PlayerBody());
    }

    // =========================================================================
    //  SKY DOME — giant sphere seen from inside (no backface culling in OpenGL)
    //  Layered shells create a gradient: horizon warm haze → mid azure → zenith deep blue
    //  All emission-lit so they glow regardless of sun direction
    // =========================================================================

    private function buildSkyDome(SceneBuilder $builder): void
    {
        $center = new Vec3(0.0, -20.0, 0.0);
        $radius = 350.0;

        // Layer 1: Outer space / upper stratosphere — deepest blue/black at zenith
        $builder->entity('SkyZenith')
            ->with(new Transform3D(
                position: new Vec3($center->x, $center->y + 40.0, $center->z),
                scale: new Vec3($radius * 0.85, $radius * 0.7, $radius * 0.85),
            ))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'sky_zenith'));

        // Layer 2: Mid atmosphere / troposphere — main sky color
        $builder->entity('SkyDome')
            ->with(new Transform3D(
                position: $center,
                scale: new Vec3($radius, $radius, $radius),
            ))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'sky_mid'));

        // Layer 3: Stratosphere — transition layer between zenith and horizon
        // Simulates Rayleigh scattering: light passes through more atmosphere at low angles
        // making the sky whiter/paler near the horizon and deeper blue higher up
        MaterialRegistry::register('sky_strato', new Material(
            albedo: Color::hex('#000000'),
            emission: Color::hex('#6A9AC0'),
            alpha: 0.5,
        ));
        $builder->entity('SkyStrato')
            ->with(new Transform3D(
                position: new Vec3($center->x, $center->y + 10.0, $center->z),
                scale: new Vec3($radius * 0.95, $radius * 0.55, $radius * 0.95),
            ))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'sky_strato'));

        // Layer 4: Horizon haze — dense atmosphere at ground level
        // White/pale because all wavelengths scatter equally through thick air (Mie scattering)
        $builder->entity('SkyHorizon')
            ->with(new Transform3D(
                position: new Vec3($center->x, $center->y - 10.0, $center->z),
                scale: new Vec3($radius * 1.05, $radius * 0.35, $radius * 1.05),
            ))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'sky_horizon'));

        // Layer 5: Sun-side warmth — Mie forward scattering near sun position
        $builder->entity('SkySunSide')
            ->with(new Transform3D(
                position: new Vec3(20.0, 10.0, -120.0),
                scale: new Vec3(180.0, 120.0, 80.0),
            ))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'sky_sun_warm'));
    }

    private function buildLighting(SceneBuilder $builder): void
    {
        // === PRIMARY SUN ===
        // DirectionalLight direction = the direction light TRAVELS (from sky to ground).
        // Sun position: high up (Y=70), slightly right (X=15), over ocean (Z=-60).
        // Light must hit BOTH strand (Z=0..35) and water (Z=-10..-100).
        // Direction: nearly straight down with slight angle — illuminates everything.
        $builder->entity('Sun')
            ->with(new Transform3D())
            ->with(new DirectionalLight(
                direction: new Vec3(-0.2, -0.9, -0.1),
                color: Color::hex('#FFFAF0'),
                intensity: 1.5,
            ));

        // === SUN (3-layer: bright core, warm corona, soft outer glow) ===
        $sunStart = new Vec3(20.0, 65.0, -50.0);
        $builder->entity('SunDisc')
            ->with(new Transform3D(position: $sunStart, scale: new Vec3(3.0, 3.0, 3.0)))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'sun_disc'));

        $builder->entity('SunGlow')
            ->with(new Transform3D(position: $sunStart, scale: new Vec3(14.0, 14.0, 14.0)))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'sun_glow'));

        // === MOON (3-layer: surface, shadow crescent, soft glow) ===
        $moonHidden = new Vec3(0.0, -100.0, 0.0);
        MaterialRegistry::register('moon_disc', new Material(
            albedo: Color::hex('#000000'), emission: Color::hex('#D0D8E8'),
        ));
        $builder->entity('MoonDisc')
            ->with(new Transform3D(position: $moonHidden, scale: new Vec3(2.5, 2.5, 2.5)))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'moon_disc'));

        // Shadow sphere — slightly offset to create crescent
        MaterialRegistry::register('moon_shadow', new Material(
            albedo: Color::hex('#000000'), emission: Color::hex('#080A14'),
        ));
        $builder->entity('MoonShadow')
            ->with(new Transform3D(position: $moonHidden, scale: new Vec3(2.3, 2.3, 2.3)))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'moon_shadow'));

        MaterialRegistry::register('moon_glow', new Material(
            albedo: Color::hex('#000000'), emission: Color::hex('#3A4060'), alpha: 0.3,
        ));
        $builder->entity('MoonGlow')
            ->with(new Transform3D(position: $moonHidden, scale: new Vec3(6.0, 6.0, 6.0)))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'moon_glow'));

        // === FILL LIGHT from sky ===
        // Simulates scattered blue skylight hitting surfaces facing away from sun
        $builder->entity('FillLight')
            ->with(new Transform3D())
            ->with(new DirectionalLight(
                direction: new Vec3(0.3, -0.5, 0.3),
                color: Color::hex('#B8D8F0'),
                intensity: 0.3,
            ));

        // === POINT LIGHTS for local warmth ===
        $builder->entity('SunsetGlow')
            ->with(new Transform3D(position: new Vec3(15.0, 15.0, -25.0)))
            ->with(new PointLight(
                color: Color::hex('#FFD494'),
                intensity: 2.0,
                radius: 60.0,
            ));

        // Beach area warm fill
        $builder->entity('BeachLight')
            ->with(new Transform3D(position: new Vec3(0.0, 8.0, 10.0)))
            ->with(new PointLight(
                color: Color::hex('#FFF0D0'),
                intensity: 1.0,
                radius: 40.0,
            ));

        // Cool water reflection
        $builder->entity('ShoreGlow')
            ->with(new Transform3D(position: new Vec3(0.0, 2.0, -8.0)))
            ->with(new PointLight(
                color: Color::hex('#88CCEE'),
                intensity: 0.5,
                radius: 25.0,
            ));
    }

    private function buildWind(SceneBuilder $builder): void
    {
        $wind = new Wind();
        $wind->maxIntensity = 1.0;
        $wind->minIntensity = 0.15;
        $wind->gustFrequency = 0.2;
        $builder->entity('WindController')
            ->with(new Transform3D())
            ->with($wind);
    }

    // =========================================================================
    //  TERRAIN — sand grain based beach
    //  Thousands of individual grain instances with per-grain rotation.
    //  Height function creates: slope to water, dune bumps, sand ripples.
    //  Rendered via DrawMeshInstanced for GPU efficiency.
    // =========================================================================

    private function buildTerrain(SceneBuilder $builder): void
    {
        // Single terrain mesh with height baked into vertices
        // Zone info encoded in UVs for the procedural sand shader
        if (!MeshRegistry::has('beach_terrain')) {
            MeshRegistry::register('beach_terrain', \App\Geometry\TerrainMesh::generate(
                xMin: -55.0,
                xMax: 55.0,
                zMin: -13.0,
                zMax: 40.0,
                step: 0.25,
                heightFn: fn(float $x, float $z) => $this->beachHeightFn($x, $z),
            ));
        }

        $builder->entity('SandTerrain')
            ->with(new Transform3D())
            ->with(new MeshRenderer(meshId: 'beach_terrain', materialId: 'sand_terrain'));

        $this->buildTerrainColliders($builder);
    }

    /**
     * Build a curved palm trunk from multiple short cylinder segments.
     * Returns the crown position at the top.
     */
    private function buildCurvedTrunk(SceneBuilder $builder, int $index, Vec3 $base, float $height, float $lean): Vec3
    {
        $segments = 8;

        // Curve control
        $curveX = $lean * 0.6;
        $curveZ = sin($index * 2.3) * 0.08;
        $sBend = cos($index * 1.7) * 0.12;

        // Pre-compute all joint positions along the curve
        $joints = [];
        for ($s = 0; $s <= $segments; $s++) {
            $t = (float) $s / $segments;
            $ox = $curveX * $t * $t + $sBend * sin($t * M_PI) * $t;
            $oz = $curveZ * $t * $t;
            $joints[] = new Vec3(
                $base->x + $ox * $height,
                $base->y + $t * $height,
                $base->z + $oz * $height,
            );
        }

        // Build segments between consecutive joints
        for ($s = 0; $s < $segments; $s++) {
            $bot = $joints[$s];
            $top = $joints[$s + 1];
            $tMid = ((float) $s + 0.5) / $segments;

            // Direction vector
            $dx = $top->x - $bot->x;
            $dy = $top->y - $bot->y;
            $dz = $top->z - $bot->z;
            $segLen = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
            if ($segLen < 0.001) continue;

            // Align cylinder Y-axis to segment direction using axis-angle
            $dirX = $dx / $segLen;
            $dirY = $dy / $segLen;
            $dirZ = $dz / $segLen;

            // Rotation from Y-up (0,1,0) to direction via cross product
            // cross(Y, dir) = (dirZ, 0, -dirX), angle = acos(dirY)
            $dot = $dirY; // dot(Y, dir)
            $dot = max(-1.0, min(1.0, $dot));
            $angle = acos($dot);
            $axisX = $dirZ;
            $axisZ = -$dirX;
            $axisLen = sqrt($axisX * $axisX + $axisZ * $axisZ);

            if ($axisLen > 0.0001) {
                $rotation = Quaternion::fromAxisAngle(
                    new Vec3($axisX / $axisLen, 0.0, $axisZ / $axisLen),
                    $angle,
                );
            } else {
                $rotation = Quaternion::identity();
            }

            // Center = midpoint between joints
            $center = new Vec3(
                ($bot->x + $top->x) * 0.5,
                ($bot->y + $top->y) * 0.5,
                ($bot->z + $top->z) * 0.5,
            );

            // Radius tapers
            $radius = 0.19 - $tMid * 0.08;

            $matId = ($s < 4) ? 'palm_trunk' : (($index % 2 === 0) ? 'palm_trunk' : 'palm_trunk_dark');

            // Scale Y to exactly match joint-to-joint distance (cylinder height = 2*scaleY)
            $builder->entity("Palm_{$index}_Trunk_{$s}")
                ->with(new Transform3D(
                    position: $center,
                    rotation: $rotation,
                    scale: new Vec3($radius, $segLen * 0.52, $radius),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: $matId));

            // Rings at joints
            if ($s === 2 || $s === 4 || $s === 6) {
                $builder->entity("Palm_{$index}_Ring_{$s}")
                    ->with(new Transform3D(
                        position: $bot,
                        rotation: $rotation,
                        scale: new Vec3($radius + 0.04, 0.035, $radius + 0.04),
                    ))
                    ->with(new MeshRenderer(meshId: 'cylinder', materialId: 'palm_trunk_ring'));
            }
        }

        // Trunk collider
        $midJoint = $joints[(int) ($segments / 2)];
        $builder->entity("Palm_{$index}_Collider")
            ->with(new Transform3D(
                position: new Vec3($midJoint->x, $base->y + $height * 0.5, $midJoint->z),
            ))
            ->with(new BoxCollider3D(
                size: new Vec3(0.9, $height, 0.9),
                isStatic: true,
            ));

        return $joints[$segments];
    }

    private function buildTerrainColliders(SceneBuilder $builder): void
    {
        // Main beach ground — flat plane from shore to dunes
        $builder->entity('GroundMain')
            ->with(new Transform3D(
                position: new Vec3(0.0, -0.2, 13.0),
            ))
            ->with(new BoxCollider3D(
                size: new Vec3(120.0, 0.4, 56.0),
                isStatic: true,
            ));

        // Dune collision — raised area behind the beach
        $builder->entity('GroundDuneLeft')
            ->with(new Transform3D(
                position: new Vec3(-18.0, 1.5, 30.0),
            ))
            ->with(new BoxCollider3D(
                size: new Vec3(30.0, 3.0, 20.0),
                isStatic: true,
            ));

        $builder->entity('GroundDuneCenter')
            ->with(new Transform3D(
                position: new Vec3(8.0, 2.0, 35.0),
            ))
            ->with(new BoxCollider3D(
                size: new Vec3(25.0, 4.0, 20.0),
                isStatic: true,
            ));

        $builder->entity('GroundDuneRight')
            ->with(new Transform3D(
                position: new Vec3(30.0, 1.2, 28.0),
            ))
            ->with(new BoxCollider3D(
                size: new Vec3(25.0, 2.5, 18.0),
                isStatic: true,
            ));

        // Ocean floor — player can swim above this
        $builder->entity('OceanFloor')
            ->with(new Transform3D(
                position: new Vec3(0.0, -4.0, -40.0),
            ))
            ->with(new BoxCollider3D(
                size: new Vec3(120.0, 0.5, 80.0),
                isStatic: true,
            ));
    }

    /**
     * Beach height function — returns Y height and material for any (x, z).
     *
     * Zones (by Z):
     *   Z > 10:   Dry back-beach, with dune bumps
     *   Z 0..10:  Mid beach, gentle slope
     *   Z -5..0:  Damp sand, steeper slope
     *   Z < -5:   Wet sand at waterline
     *
     * @return array{y: float, material: string}
     */
    private function beachHeightFn(float $x, float $z): array
    {
        // Base slope: terrain rises in +Z direction (toward back-beach/dunes)
        // Player starts at Z=12, sand slopes upward from there
        // At Z=-10 (3m past the low point) → cliff drops off
        $y = 0.0;
        if ($z > 0.0) {
            $y = $z * 0.02; // gentle uphill slope toward dunes
        }
        // Cliff: steep drop after Z=-10
        if ($z < -10.0) {
            $y -= (-10.0 - $z) * 2.0; // sharp drop-off
        }

        // === DUNES — distinct hills along the back beach ===
        // Each dune is a Gaussian bump: height * exp(-((x-cx)^2/wx + (z-cz)^2/wz))
        $dunes = [
            // [centerX, centerZ, height, widthX, widthZ]
            [-18.0, 30.0, 2.5, 80.0, 40.0],   // large left dune
            [  8.0, 35.0, 3.0, 60.0, 50.0],    // tall center-right dune
            [ 30.0, 28.0, 2.0, 70.0, 35.0],    // right dune
            [-35.0, 25.0, 1.8, 50.0, 30.0],    // far left smaller dune
            [  0.0, 22.0, 1.2, 90.0, 25.0],    // low wide ridge mid-beach
            [-10.0, 38.0, 2.2, 55.0, 45.0],    // back-left peak
            [ 22.0, 36.0, 2.8, 65.0, 55.0],    // back-right peak
            [ 45.0, 32.0, 1.5, 45.0, 30.0],    // far right bump
            [-45.0, 34.0, 1.6, 40.0, 35.0],    // far left bump
        ];

        foreach ($dunes as [$cx, $cz, $h, $wx, $wz]) {
            $dx = $x - $cx;
            $dz = $z - $cz;
            $y += $h * exp(-($dx * $dx / $wx + $dz * $dz / $wz));
        }

        // Dune ridge lines — elongated ridges running roughly parallel to shore
        if ($z > 15.0) {
            $ridgeFactor = min(1.0, ($z - 15.0) / 10.0);
            $ridge1 = 0.4 * $ridgeFactor * max(0.0, sin($x * 0.12 + 0.5)) * sin($z * 0.08 + 2.1);
            $ridge2 = 0.25 * $ridgeFactor * max(0.0, sin($x * 0.09 + 3.7)) * sin($z * 0.06 + 0.8);
            $y += $ridge1 + $ridge2;
        }

        // Sand ripples — small wave patterns across the whole beach
        $ripple = sin($x * 2.5 + $z * 0.3) * 0.015
                + sin($x * 0.7 + $z * 1.8) * 0.01;
        $y += $ripple;

        // Wind ripples — diagonal patterns, stronger on dune slopes
        if ($z > 5.0) {
            $windStrength = min(1.0, ($z - 5.0) / 20.0);
            $windRipple = sin(($x + $z) * 3.0) * 0.008 * $windStrength
                        + sin(($x * 0.8 - $z * 1.2) * 2.0) * 0.005 * $windStrength;
            $y += $windRipple;
        }

        // Material based on zone + height
        // Variant index from position → adjacent grains get different shades
        $variant = abs((int) (floor($x * 7.3 + $z * 11.1) + floor($x * 3.7 - $z * 5.9))) % 4;

        if ($z < -1.0) {
            $material = "sand_damp_{$variant}";
        } elseif ($z < 10.0) {
            $material = "sand_mid_{$variant}";
        } elseif ($y > 1.5) {
            $material = "sand_dune_{$variant}";
        } else {
            $material = "sand_dry_{$variant}";
        }

        return ['y' => $y, 'material' => $material];
    }

    // =========================================================================
    //  OCEAN — pixel-based water surface
    //  Each water element is an individual pixel with its own tilt.
    //  Material determined by depth (distance from shore).
    //  Color comes from light absorption: shallow = sand visible, deep = dark.
    // =========================================================================

    private function buildOceanAndWaves(SceneBuilder $builder): void
    {
        // Single subdivided plane with GPU wave animation
        // 64x64 grid = 4096 vertices — enough for smooth waves
        if (!MeshRegistry::has('water_plane')) {
            MeshRegistry::register('water_plane', PlaneMesh::generate(140.0, 90.0, 64));
        }

        // Main water surface — semitransparent, positioned at shore level
        $builder->entity('WaterSurface')
            ->with(new Transform3D(
                position: new Vec3(0.0, -0.3, -44.0),
            ))
            ->with(new MeshRenderer(meshId: 'water_plane', materialId: 'water_surface'));

        // Deeper water layer — slightly lower, darker, more opaque
        $builder->entity('WaterDeep')
            ->with(new Transform3D(
                position: new Vec3(0.0, -0.6, -44.0),
            ))
            ->with(new MeshRenderer(meshId: 'water_plane', materialId: 'water_deep_plane'));
    }

    /**
     * Water height and material function.
     * Height: gentle swell patterns.
     * Material: based on depth (distance from shore Z=-8).
     *
     * @return array{y: float, material: string}
     */
    private function waterHeightFn(float $x, float $z): array
    {
        // Base height — slight descent with depth
        $depth = abs($z + 8.0); // distance from shore
        $y = -0.25 - $depth * 0.004;

        // Ocean swell — large slow waves
        $swell = sin($x * 0.08 + $z * 0.12) * 0.15
               + sin($x * 0.05 - $z * 0.08 + 1.3) * 0.1;
        $y += $swell * min(1.0, $depth / 15.0); // swells grow with depth

        // Smaller ripples
        $ripple = sin($x * 0.5 + $z * 0.3) * 0.03
                + sin($x * 0.3 - $z * 0.7 + 2.1) * 0.02;
        $y += $ripple;

        // Material by depth — smooth gradient through absorption colors
        $t = min(1.0, $depth / 70.0); // 0 at shore, 1 at Z=-78

        $materials = [
            'water_crystal', 'water_turquoise', 'water_aqua', 'water_teal',
            'water_blue', 'water_ocean', 'water_deep', 'water_navy',
        ];

        $matIndex = $t * (count($materials) - 1);
        $material = $materials[(int) round($matIndex)];

        return ['y' => $y, 'material' => $material];
    }

    private function buildPalmTrees(SceneBuilder $builder): void
    {
        // Register frond meshes — 4 variations
        for ($v = 0; $v < 4; $v++) {
            $meshId = "palm_frond_{$v}";
            if (!MeshRegistry::has($meshId)) {
                MeshRegistry::register($meshId, \App\Geometry\PalmFrondMesh::generate(2.5, 12, $v));
            }
        }

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
            $crownPos = $this->buildCurvedTrunk($builder, $i, $palm['pos'], $palm['height'], $palm['lean']);

            // Crown base sphere
            $builder->entity("Palm_{$i}_CrownBase")
                ->with(new Transform3D(
                    position: $crownPos,
                    scale: new Vec3(0.28, 0.22, 0.28),
                ))
                ->with(new MeshRenderer(meshId: 'sphere', materialId: 'palm_trunk'));

            // Custom feathered fronds — 14 per tree for a full crown
            $frondCount = 14;
            $frondLength = 2.2 + ($palm['height'] - 4.5) * 0.25;
            $yawOffset = $i * 0.55;

            for ($f = 0; $f < $frondCount; $f++) {
                $yaw = ($f / $frondCount) * M_PI * 2.0 + $yawOffset;
                $yaw += sin($f * 2.7 + $i * 1.3) * 0.18; // jitter

                // Elevation — fronds droop outward, mostly horizontal to slightly down
                $elevation = -0.15 - sin($f * 1.9 + $i * 0.7) * 0.15;

                // Length variation
                $len = $frondLength * (0.85 + sin($f * 3.1 + $i) * 0.15);

                $rotation = Quaternion::fromEuler($elevation, $yaw, 0.0);

                $matId = ($i % 2 === 0) ? 'palm_leaves' : 'palm_leaves_light';
                // Mix in some dead/brown fronds
                if (($f + $i) % 7 === 0) {
                    $matId = 'palm_branch'; // olive/brown
                }

                $meshVariant = ($i + $f) % 4;

                $builder->entity("Palm_{$i}_Frond_{$f}")
                    ->with(new Transform3D(
                        position: $crownPos,
                        rotation: $rotation,
                        scale: new Vec3($len * 0.5, $len * 0.5, $len * 0.5),
                    ))
                    ->with(new MeshRenderer(meshId: "palm_frond_{$meshVariant}", materialId: $matId));
            }

            // Coconuts on some trees
            if ($i % 3 === 0) {
                $coconutCount = 2 + ($i % 2);
                for ($c = 0; $c < $coconutCount; $c++) {
                    $cAngle = ($c / $coconutCount) * M_PI * 2.0 + $i * 1.5;
                    $builder->entity("Palm_{$i}_Coconut_{$c}")
                        ->with(new Transform3D(
                            position: new Vec3(
                                $crownPos->x + cos($cAngle) * 0.3,
                                $crownPos->y - 0.35,
                                $crownPos->z + sin($cAngle) * 0.3,
                            ),
                            scale: new Vec3(0.11, 0.13, 0.11),
                        ))
                        ->with(new MeshRenderer(meshId: 'sphere', materialId: 'coconut'));
                }
            }
        }
    }

    private function buildRocks(SceneBuilder $builder): void
    {
        // Register unique deformed rock meshes — each seed creates a different shape
        for ($i = 0; $i < 10; $i++) {
            $meshId = "rock_mesh_{$i}";
            if (!MeshRegistry::has($meshId)) {
                MeshRegistry::register($meshId, \App\Geometry\RockMesh::generate(1.0, $i, 12, 16));
            }
        }

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
                ->with(new MeshRenderer(meshId: "rock_mesh_{$i}", materialId: $rock['mat']))
                ->with(new \PHPolygon\Component\MeshCollider3D(meshId: "rock_mesh_{$i}", isStatic: true));
        }
    }

    private function buildBeachDetails(SceneBuilder $builder): void
    {
        // Shells, driftwood and seaweed removed — need proper tiny-scale meshes first
    }

    // =========================================================================
    //  CLOUDS — reference: real cumulus clouds
    //  Very flat (width:height = 8:1), large (30-60m span), soft overlapping
    //  puffs (15-25 spheres each), bright white tops, slight gray undersides
    // =========================================================================

    private function buildClouds(SceneBuilder $builder): void
    {
        // Register unique cloud puff meshes
        for ($i = 0; $i < 6; $i++) {
            $meshId = "cloud_puff_{$i}";
            if (!MeshRegistry::has($meshId)) {
                MeshRegistry::register($meshId, \App\Geometry\CloudPuffMesh::generate($i));
            }
        }

        // Each cloud: center position + spread parameters
        $clouds = [
            ['x' => -25.0, 'y' => 45.0, 'z' => -50.0, 'speed' => 0.8, 'size' => 1.0],
            ['x' => 30.0,  'y' => 50.0, 'z' => -65.0, 'speed' => 0.5, 'size' => 1.3],
            ['x' => -55.0, 'y' => 48.0, 'z' => -55.0, 'speed' => 0.7, 'size' => 0.9],
            ['x' => 65.0,  'y' => 52.0, 'z' => -70.0, 'speed' => 0.4, 'size' => 1.1],
            ['x' => 5.0,   'y' => 46.0, 'z' => -80.0, 'speed' => 0.6, 'size' => 1.4],
            ['x' => -40.0, 'y' => 55.0, 'z' => -90.0, 'speed' => 0.3, 'size' => 1.2],
            ['x' => 50.0,  'y' => 44.0, 'z' => -45.0, 'speed' => 0.9, 'size' => 0.8],
            ['x' => -70.0, 'y' => 47.0, 'z' => -60.0, 'speed' => 0.6, 'size' => 1.0],
            ['x' => 15.0,  'y' => 53.0, 'z' => -75.0, 'speed' => 0.35, 'size' => 1.5],
            ['x' => -10.0, 'y' => 42.0, 'z' => -40.0, 'speed' => 1.0, 'size' => 0.7],
        ];

        foreach ($clouds as $ci => $cloud) {
            $sz = $cloud['size'];
            // Build each cloud from many overlapping, very flat spheres
            $puffs = $this->generateCloudPuffs($ci, $sz);

            foreach ($puffs as $pi => $puff) {
                $drift = new CloudDrift();
                $drift->speed = $cloud['speed'];
                $drift->resetMinX = -120.0;
                $drift->resetMaxX = 120.0;
                $drift->bobAmplitude = 0.1 + $pi * 0.01;
                $drift->bobFrequency = 0.08 + $ci * 0.01;
                $drift->phaseOffset = $ci * 1.5 + $pi * 0.3;

                // Top puffs bright, bottom ones shadowed
                $matId = $puff['y'] > 0.0 ? 'cloud_top' : 'cloud_base';
                if ($pi < 3) {
                    $matId = 'cloud_bright';
                }

                $puffMeshId = 'cloud_puff_' . (($ci + $pi) % 6);

                $builder->entity("Cloud_{$ci}_{$pi}")
                    ->with(new Transform3D(
                        position: new Vec3(
                            $cloud['x'] + $puff['x'],
                            $cloud['y'] + $puff['y'],
                            $cloud['z'] + $puff['z'],
                        ),
                        scale: new Vec3($puff['sx'], $puff['sy'], $puff['sz']),
                    ))
                    ->with(new MeshRenderer(meshId: $puffMeshId, materialId: $matId))
                    ->with($drift);
            }
        }
    }

    /**
     * Generate puff positions/scales for one cloud.
     * Real cumulus: wide flat base, rounded bumpy top.
     */
    private function generateCloudPuffs(int $seed, float $size): array
    {
        $puffs = [];

        // Core mass — large flat ellipsoids forming the base
        $coreCount = 6;
        for ($i = 0; $i < $coreCount; $i++) {
            $angle = ($i / $coreCount) * 2.0 * M_PI + $seed * 0.5;
            $r = 3.0 + sin($seed + $i * 1.7) * 1.5;
            $puffs[] = [
                'x' => cos($angle) * $r * $size,
                'y' => -0.3 + sin($i * 0.8) * 0.3,
                'z' => sin($angle) * $r * 0.7 * $size,
                'sx' => (5.0 + sin($seed + $i) * 2.0) * $size,
                'sy' => (1.0 + cos($i * 1.3) * 0.3) * $size,
                'sz' => (4.0 + cos($seed + $i) * 1.5) * $size,
            ];
        }

        // Top bumps — smaller puffs on top creating cauliflower shape
        $bumpCount = 8;
        for ($i = 0; $i < $bumpCount; $i++) {
            $angle = ($i / $bumpCount) * 2.0 * M_PI + $seed * 1.1;
            $r = 2.0 + sin($seed * 2 + $i * 2.3) * 1.5;
            $puffs[] = [
                'x' => cos($angle) * $r * $size,
                'y' => 0.8 + sin($i * 1.1 + $seed) * 0.5,
                'z' => sin($angle) * $r * 0.6 * $size,
                'sx' => (3.0 + sin($seed + $i * 0.9) * 1.0) * $size,
                'sy' => (1.2 + cos($i * 1.5) * 0.4) * $size,
                'sz' => (2.5 + cos($seed + $i * 0.7) * 0.8) * $size,
            ];
        }

        // Wispy edges — small flat spheres around the perimeter
        $edgeCount = 5;
        for ($i = 0; $i < $edgeCount; $i++) {
            $angle = ($i / $edgeCount) * 2.0 * M_PI + $seed * 0.3;
            $r = 5.0 + sin($seed + $i * 3.1) * 1.0;
            $puffs[] = [
                'x' => cos($angle) * $r * $size,
                'y' => -0.2 + sin($i * 2.1) * 0.2,
                'z' => sin($angle) * $r * 0.5 * $size,
                'sx' => (2.5 + sin($i * 1.4) * 0.5) * $size,
                'sy' => (0.5 + cos($i * 0.9) * 0.15) * $size,
                'sz' => (2.0 + cos($i * 1.2) * 0.4) * $size,
            ];
        }

        return $puffs;
    }

    private function registerMeshes(): void
    {
        if (!MeshRegistry::has('box')) {
            MeshRegistry::register('box', BoxMesh::generate(2.0, 2.0, 2.0));
        }
        if (!MeshRegistry::has('sphere')) {
            // Higher tessellation for smoother clouds and water
            MeshRegistry::register('sphere', SphereMesh::generate(1.0, 24, 36));
        }
        if (!MeshRegistry::has('plane')) {
            MeshRegistry::register('plane', PlaneMesh::generate(1.0, 1.0));
        }
        if (!MeshRegistry::has('cylinder')) {
            MeshRegistry::register('cylinder', CylinderMesh::generate(1.0, 2.0, 16));
        }
        if (!MeshRegistry::has('wedge')) {
            MeshRegistry::register('wedge', \PHPolygon\Geometry\WedgeMesh::generate(0.0));
            // Right-triangle wedges: peak at Z=-1 (for front half) and Z=+1 (for back half)
            MeshRegistry::register('wedge_right_neg', \PHPolygon\Geometry\WedgeMesh::generate(-1.0));
            MeshRegistry::register('wedge_right_pos', \PHPolygon\Geometry\WedgeMesh::generate(1.0));
        }
        if (!MeshRegistry::has('sand_grain')) {
            // Flat box — individual grain, rendered thousands of times via instancing
            MeshRegistry::register('sand_grain', BoxMesh::generate(1.0, 1.0, 1.0));
        }

        // Plank wall meshes — 3 seeded variants for visual variety
        // Same coordinate convention as BoxMesh: centered at origin, base 2×2
        if (!MeshRegistry::has('plank_wall_0')) {
            MeshRegistry::register('plank_wall_0', PlankWallMesh::generate(seed: 0));
            MeshRegistry::register('plank_wall_1', PlankWallMesh::generate(seed: 17));
            MeshRegistry::register('plank_wall_2', PlankWallMesh::generate(seed: 42));
        }
    }

    private function registerMaterials(): void
    {
        // ======================
        //  SKY DOME — emission-only, self-lit
        //  Reference: real tropical sky gradient
        //  Zenith: deep blue #1E5FAA
        //  Mid: azure #4A90D9
        //  Horizon: warm haze #C8DCF0
        // ======================

        MaterialRegistry::register('sky_zenith', new Material(
            albedo: Color::hex('#000000'),
            roughness: 1.0,
            emission: Color::hex('#1E5FAA'),
        ));
        MaterialRegistry::register('sky_mid', new Material(
            albedo: Color::hex('#000000'),
            roughness: 1.0,
            emission: Color::hex('#4A90D9'),
        ));
        MaterialRegistry::register('sky_horizon', new Material(
            albedo: Color::hex('#000000'),
            roughness: 1.0,
            emission: Color::hex('#87AECC'),
        ));
        MaterialRegistry::register('sky_sun_warm', new Material(
            albedo: Color::hex('#000000'),
            roughness: 1.0,
            emission: Color::hex('#E8D8C0'),
        ));

        // ======================
        //  SAND — multiple shades per zone for grain texture
        //  Each zone has 4 variants: base, lighter, darker, warm/cool shift
        //  Adjacent grains get different variants → visible texture
        // ======================

        // ======================
        //  SAND — high contrast grain texture
        //  Each zone has 4 variants with STRONG color differences.
        //  Variant 3 = dark mineral grain (quartz, feldspar, mica)
        //  to create visible speckle pattern even from distance.
        // ======================

        // Dry sand — warm beige base, one dark speckle variant
        MaterialRegistry::register('sand_dry_0', new Material(albedo: Color::hex('#D4B87A'), roughness: 0.95));
        MaterialRegistry::register('sand_dry_1', new Material(albedo: Color::hex('#C4A462'), roughness: 0.93));
        MaterialRegistry::register('sand_dry_2', new Material(albedo: Color::hex('#E0C48C'), roughness: 0.96));
        MaterialRegistry::register('sand_dry_3', new Material(albedo: Color::hex('#8B7340'), roughness: 0.90));

        // Mid sand — golden, more contrast
        MaterialRegistry::register('sand_mid_0', new Material(albedo: Color::hex('#B89050'), roughness: 0.90));
        MaterialRegistry::register('sand_mid_1', new Material(albedo: Color::hex('#A07838'), roughness: 0.88));
        MaterialRegistry::register('sand_mid_2', new Material(albedo: Color::hex('#C89858'), roughness: 0.91));
        MaterialRegistry::register('sand_mid_3', new Material(albedo: Color::hex('#6B5528'), roughness: 0.85));

        // Damp sand — dark ochre/brown
        MaterialRegistry::register('sand_damp_0', new Material(albedo: Color::hex('#7A5E2A'), roughness: 0.65));
        MaterialRegistry::register('sand_damp_1', new Material(albedo: Color::hex('#684E20'), roughness: 0.63));
        MaterialRegistry::register('sand_damp_2', new Material(albedo: Color::hex('#8A6830'), roughness: 0.67));
        MaterialRegistry::register('sand_damp_3', new Material(albedo: Color::hex('#4A3818'), roughness: 0.60));

        // Dune tops — lighter but still with contrast
        MaterialRegistry::register('sand_dune_0', new Material(albedo: Color::hex('#DCC080'), roughness: 0.95));
        MaterialRegistry::register('sand_dune_1', new Material(albedo: Color::hex('#E8CC90'), roughness: 0.96));
        MaterialRegistry::register('sand_dune_2', new Material(albedo: Color::hex('#D0B470'), roughness: 0.94));
        MaterialRegistry::register('sand_dune_3', new Material(albedo: Color::hex('#9A8048'), roughness: 0.92));
        MaterialRegistry::register('tide_wrack', new Material(
            albedo: Color::hex('#7A6B50'),
            roughness: 0.8,
        ));

        // Procedural terrain — base color, actual coloring done by sand shader
        MaterialRegistry::register('sand_terrain', new Material(
            albedo: Color::hex('#C4A868'),
            roughness: 0.92,
        ));

        // Shore blend — wet sand visible through thin water layer
        // Progressively darker as water depth increases, gaining reflectivity
        MaterialRegistry::register('shore_blend_1', new Material(
            albedo: Color::hex('#8A7D6B'),
            roughness: 0.15,
            metallic: 0.35,
        ));
        MaterialRegistry::register('shore_blend_2', new Material(
            albedo: Color::hex('#7E7568'),
            roughness: 0.1,
            metallic: 0.45,
        ));
        MaterialRegistry::register('shore_blend_3', new Material(
            albedo: Color::hex('#908578'),
            roughness: 0.06,
            metallic: 0.55,
        ));

        // Player body
        MaterialRegistry::register('player_body', new Material(
            albedo: Color::hex('#4A7A8C'),
            roughness: 0.8,
        ));
        MaterialRegistry::register('player_shoe', new Material(
            albedo: Color::hex('#3B2F2F'),
            roughness: 0.9,
        ));

        // Footprints
        MaterialRegistry::register('footprint', new Material(
            albedo: Color::hex('#B8975A'),
            roughness: 0.85,
        ));

        // Sun — very bright emissive
        MaterialRegistry::register('sun_disc', new Material(
            albedo: Color::hex('#FFFFFF'),
            roughness: 1.0,
            emission: Color::hex('#FFFF99'),
        ));
        MaterialRegistry::register('sun_glow', new Material(
            albedo: Color::hex('#FFFDE0'),
            roughness: 1.0,
            emission: Color::hex('#DDBB44'),
        ));

        // ==============================
        //  WATER — real tropical gradient
        //  Crystal shore: #7FDBDA
        //  Turquoise: #40E0D0
        //  Aqua: #20B2AA
        //  Teal: #1A8A8A
        //  Blue: #0077BE
        //  Ocean: #005A8E
        //  Deep: #003F6B
        //  Navy: #002244
        //  Abyss: #001122
        // ==============================

        // ==============================
        //  WATER — physically-based approach
        //  Water is nearly colorless. What we see:
        //  - Shallow: sand underneath shows through (warm grey-tan)
        //  - Medium: red light absorbed → remaining green-blue tint
        //  - Deep: most light absorbed → very dark blue-grey
        //  High metallic + low roughness = specular highlights from sun/sky
        // ==============================

        // Shore — sand visible through thin water film
        MaterialRegistry::register('water_crystal', new Material(
            albedo: Color::hex('#9B9080'),
            roughness: 0.05,
            metallic: 0.6,
        ));
        // Shallow — slight green-blue, sand still faintly visible
        MaterialRegistry::register('water_turquoise', new Material(
            albedo: Color::hex('#6B7B72'),
            roughness: 0.04,
            metallic: 0.65,
        ));
        // Moderate — red fully absorbed, green-blue dominant
        MaterialRegistry::register('water_aqua', new Material(
            albedo: Color::hex('#4A6B65'),
            roughness: 0.04,
            metallic: 0.7,
        ));
        // Deeper — less light returns, darker grey-teal
        MaterialRegistry::register('water_teal', new Material(
            albedo: Color::hex('#3A5550'),
            roughness: 0.05,
            metallic: 0.7,
        ));
        // Mid-ocean — blue-grey, losing brightness
        MaterialRegistry::register('water_blue', new Material(
            albedo: Color::hex('#2A4048'),
            roughness: 0.06,
            metallic: 0.75,
        ));
        // Open ocean — dark grey with blue shift
        MaterialRegistry::register('water_ocean', new Material(
            albedo: Color::hex('#1E3038'),
            roughness: 0.07,
            metallic: 0.75,
        ));
        // Deep — very little light returns
        MaterialRegistry::register('water_deep', new Material(
            albedo: Color::hex('#142428'),
            roughness: 0.08,
            metallic: 0.8,
        ));
        // Navy — almost all light absorbed
        MaterialRegistry::register('water_navy', new Material(
            albedo: Color::hex('#0C1820'),
            roughness: 0.1,
            metallic: 0.8,
        ));
        // Abyss — near black
        MaterialRegistry::register('ocean_abyss', new Material(
            albedo: Color::hex('#060E14'),
            roughness: 0.12,
            metallic: 0.8,
        ));

        // Animated water plane materials — semitransparent
        MaterialRegistry::register('water_surface', new Material(
            albedo: Color::hex('#3A8B9B'),
            roughness: 0.02,
            metallic: 0.8,
            alpha: 0.55,
        ));
        MaterialRegistry::register('water_deep_plane', new Material(
            albedo: Color::hex('#1A3848'),
            roughness: 0.05,
            metallic: 0.7,
            alpha: 0.7,
        ));

        // Underwater volume — dark sandy/silty layers beneath surface
        MaterialRegistry::register('seafloor', new Material(
            albedo: Color::hex('#2A2A20'),
            roughness: 0.95,
        ));
        MaterialRegistry::register('underwater_shallow', new Material(
            albedo: Color::hex('#1A2820'),
            roughness: 0.9,
        ));
        MaterialRegistry::register('underwater_mid', new Material(
            albedo: Color::hex('#101C18'),
            roughness: 0.9,
        ));
        MaterialRegistry::register('underwater_deep', new Material(
            albedo: Color::hex('#080E0C'),
            roughness: 0.9,
        ));

        // Foam — real ocean foam is bright white
        MaterialRegistry::register('foam', new Material(
            albedo: Color::hex('#FFFFFF'),
            roughness: 0.95,
            emission: Color::hex('#444444'),
        ));
        MaterialRegistry::register('foam_thin', new Material(
            albedo: Color::hex('#E0F0F8'),
            roughness: 0.85,
            emission: Color::hex('#222222'),
        ));

        // Palm trunks
        MaterialRegistry::register('palm_trunk', new Material(
            albedo: Color::hex('#5A3A1A'),
            roughness: 0.92,
        ));
        MaterialRegistry::register('palm_trunk_dark', new Material(
            albedo: Color::hex('#3D2510'),
            roughness: 0.95,
        ));
        MaterialRegistry::register('palm_trunk_ring', new Material(
            albedo: Color::hex('#4A2E14'),
            roughness: 0.88,
        ));

        // Palm branch stems
        MaterialRegistry::register('palm_branch', new Material(
            albedo: Color::hex('#4A6B2A'),
            roughness: 0.85,
        ));

        // Palm leaves
        MaterialRegistry::register('palm_leaves', new Material(
            albedo: Color::hex('#2D6B2D'),
            roughness: 0.8,
        ));
        MaterialRegistry::register('palm_leaves_light', new Material(
            albedo: Color::hex('#3D8B3D'),
            roughness: 0.75,
        ));

        // Coconuts
        MaterialRegistry::register('coconut', new Material(
            albedo: Color::hex('#5C3B10'),
            roughness: 0.7,
        ));

        // Rocks
        MaterialRegistry::register('rock', new Material(
            albedo: Color::hex('#4A4A4A'),
            roughness: 0.85,
        ));
        MaterialRegistry::register('rock_dark', new Material(
            albedo: Color::hex('#2E2E2E'),
            roughness: 0.9,
        ));
        MaterialRegistry::register('rock_mossy', new Material(
            albedo: Color::hex('#3A4A35'),
            roughness: 0.88,
        ));

        // Shells
        MaterialRegistry::register('shell', new Material(
            albedo: Color::hex('#E8DCC8'),
            roughness: 0.6,
        ));
        MaterialRegistry::register('shell_pink', new Material(
            albedo: Color::hex('#E8C8C0'),
            roughness: 0.55,
        ));
        MaterialRegistry::register('shell_cream', new Material(
            albedo: Color::hex('#F0E8D0'),
            roughness: 0.65,
        ));

        // Driftwood
        MaterialRegistry::register('driftwood', new Material(
            albedo: Color::hex('#8B7355'),
            roughness: 0.9,
        ));

        // Seaweed
        MaterialRegistry::register('seaweed', new Material(
            albedo: Color::hex('#1A4A2A'),
            roughness: 0.75,
        ));

        // ======================
        //  CLOUDS — real cumulus
        //  Bright top: pure white with strong emission
        //  Mid: off-white
        //  Base/shadow: light gray
        // ======================

        MaterialRegistry::register('cloud_bright', new Material(
            albedo: Color::hex('#FFFFFF'),
            roughness: 1.0,
            emission: Color::hex('#AAAAAA'),
            alpha: 0.9,
        ));
        MaterialRegistry::register('cloud_top', new Material(
            albedo: Color::hex('#F8F8FF'),
            roughness: 1.0,
            emission: Color::hex('#888888'),
            alpha: 0.85,
        ));
        MaterialRegistry::register('cloud_base', new Material(
            albedo: Color::hex('#D0D5DD'),
            roughness: 1.0,
            emission: Color::hex('#555566'),
            alpha: 0.8,
        ));

        // Foam already registered above — override with alpha versions
        MaterialRegistry::register('foam', new Material(
            albedo: Color::hex('#FFFFFF'),
            roughness: 0.95,
            emission: Color::hex('#333344'),

        ));
        MaterialRegistry::register('foam_thin', new Material(
            albedo: Color::hex('#E8F4FF'),
            roughness: 0.9,
            emission: Color::hex('#222233'),

        ));

        // Beach hut materials — loaded from engine presets
        WoodMaterials::registerAll();
        ThatchMaterials::registerAll();
        FabricMaterials::registerAll();
        MetalMaterials::registerAll();
    }

    // =========================================================================
    //  BEACH HUT — wooden hut with thatched roof, table, chair, door, window
    // =========================================================================

    private function buildBeachHut(SceneBuilder $builder): void
    {
        $hx = 10.0;
        $hz = 8.0;
        $hy = 0.85; // raised on stilts
        $hutYaw = 0.4;
        $hutRot = Quaternion::fromEuler(0.0, $hutYaw, 0.0);
        $yawQ = Quaternion::fromAxisAngle(new Vec3(0.0, 1.0, 0.0), $hutYaw);

        $w = 3.5;
        $d = 3.0;
        $wallH = 2.2;
        $roofH = 1.2;
        $wallT = 0.1;
        $porchD = 1.5;

        // === STILTS (6 posts from ground to floor) ===
        $stiltH = $hy * 0.5;
        $stiltPositions = [
            [-$w * 0.45, $d * 0.45],
            [$w * 0.45, $d * 0.45],
            [-$w * 0.45, -$d * 0.45],
            [$w * 0.45, -$d * 0.45],
            [0.0, $d * 0.45],   // mid-span front
            [0.0, -$d * 0.45],  // mid-span back
        ];
        foreach ($stiltPositions as $si => [$sx, $sz]) {
            $builder->entity("Hut_Stilt_{$si}")
                ->with(new Transform3D(
                    position: $this->rotateAroundHut($hx + $sx, $stiltH, $hz + $sz, $hx, $hz, $hutYaw),
                    rotation: $hutRot,
                    scale: new Vec3(0.07, $stiltH, 0.07),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: WoodMaterials::id('beam')));
        }

        // Cross-braces under floor (X-pattern on left side)
        $braceLen = sqrt(($w * 0.9) ** 2 + $hy ** 2);
        $braceAngle = atan2($hy, $w * 0.9);
        foreach ([1.0, -1.0] as $bi => $dir) {
            $bRot = $yawQ->multiply(Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), $dir * $braceAngle));
            $builder->entity("Hut_Brace_{$bi}")
                ->with(new Transform3D(
                    position: $this->rotateAroundHut($hx, $stiltH, $hz - $d * 0.45, $hx, $hz, $hutYaw),
                    rotation: $bRot,
                    scale: new Vec3(0.03, $braceLen * 0.48, 0.03),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: WoodMaterials::id('beam')));
        }

        // === FLOOR ===
        $builder->entity('Hut_Floor')
            ->with(new Transform3D(
                position: new Vec3($hx, $hy, $hz),
                rotation: $hutRot,
                scale: new Vec3($w * 0.5, 0.05, $d * 0.5),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: WoodMaterials::id('floor')))
            ->with(new BoxCollider3D(size: new Vec3(2.0, 2.0, 2.0), isStatic: true));

        // === WALLS (all BoxMesh — proc_mode 7 shader adds plank grain) ===
        // All walls overlap at corners by ~10cm to guarantee no visible gaps.
        $overlap = $wallT;

        // All walls stop at wallH. The gable wedge (in RoofBuilder) covers the
        // full triangular area above wallH up to the ridge. This avoids rectangular
        // walls sticking above the sloped roof at the corners.
        $frontWallExtraH = $roofH * (1.0 - ($d * 0.5) / ($d * 0.5 + $porchD + 0.7));
        $backWallExtraH = $roofH * (1.0 - ($d * 0.5) / ($d * 0.5 + 0.7));

        // Back wall
        $this->buildHutWall($builder, 'Hut_BackWall', $hx, $hy, $hz,
            0.0, 0.0, -$d * 0.5, $w + $overlap * 2, $wallH, $wallT, $hutRot, WoodMaterials::id('plank'));

        // Left wall
        $this->buildHutWall($builder, 'Hut_LeftWall', $hx, $hy, $hz,
            -$w * 0.5, 0.0, 0.0, $wallT, $wallH, $d + $overlap * 2, $hutRot, WoodMaterials::id('plank_dark'));

        // Right wall (with window)
        $this->buildHutWallWithWindow($builder, $hx, $hy, $hz, $w, $d, $wallH, $wallT, $hutRot, 0.0);

        // Front wall (with door opening — panels + top beam)
        $this->buildHutWallWithDoor($builder, $hx, $hy, $hz, $w, $d, $wallH, $wallT, $hutRot);

        // === CORNER POSTS (structural timber, ground to roof) ===
        $postH = $wallH * 0.5;
        $cornerPositions = [
            [-$w * 0.45, $d * 0.45],
            [$w * 0.45, $d * 0.45],
            [-$w * 0.45, -$d * 0.45],
            [$w * 0.45, -$d * 0.45],
        ];
        foreach ($cornerPositions as $pi => [$px, $pz]) {
            $builder->entity("Hut_Post_{$pi}")
                ->with(new Transform3D(
                    position: $this->rotateAroundHut($hx + $px, $hy + $postH, $hz + $pz, $hx, $hz, $hutYaw),
                    rotation: $hutRot,
                    scale: new Vec3(0.06, $postH, 0.06),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: WoodMaterials::id('beam')));
        }

        // === PORCH / VERANDA ===
        // Porch floor
        $porchCenterZ = $d * 0.5 + $porchD * 0.5;
        $builder->entity('Hut_PorchFloor')
            ->with(new Transform3D(
                position: $this->rotateAroundHut($hx, $hy, $hz + $porchCenterZ, $hx, $hz, $hutYaw),
                rotation: $hutRot,
                scale: new Vec3($w * 0.5, 0.04, $porchD * 0.5),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: WoodMaterials::id('floor')))
            ->with(new BoxCollider3D(size: new Vec3(2.0, 2.0, 2.0), isStatic: true));

        // Porch front posts (2)
        foreach ([-1, 1] as $side) {
            $builder->entity('Hut_PorchPost_' . ($side > 0 ? 'R' : 'L'))
                ->with(new Transform3D(
                    position: $this->rotateAroundHut(
                        $hx + $side * $w * 0.45, $hy + $wallH * 0.5, $hz + $d * 0.5 + $porchD, $hx, $hz, $hutYaw),
                    rotation: $hutRot,
                    scale: new Vec3(0.05, $wallH * 0.5, 0.05),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: WoodMaterials::id('beam')));
        }

        // Porch front stilts (2)
        foreach ([-1, 1] as $side) {
            $builder->entity('Hut_PorchStilt_' . ($side > 0 ? 'R' : 'L'))
                ->with(new Transform3D(
                    position: $this->rotateAroundHut(
                        $hx + $side * $w * 0.45, $stiltH, $hz + $d * 0.5 + $porchD, $hx, $hz, $hutYaw),
                    rotation: $hutRot,
                    scale: new Vec3(0.06, $stiltH, 0.06),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: WoodMaterials::id('beam')));
        }

        // Railing — bamboo bars (top rail on left, right, front; gap at steps)
        $railH = 0.9;
        $railY = $hy + $railH * 0.5;
        $railThick = 0.025;

        // Left railing (with collision wall)
        $builder->entity('Hut_RailLeft')
            ->with(new Transform3D(
                position: $this->rotateAroundHut($hx - $w * 0.45, $railY, $hz + $d * 0.5 + $porchD * 0.5, $hx, $hz, $hutYaw),
                rotation: $hutRot,
                scale: new Vec3($railThick, $railH * 0.5, $porchD * 0.5),
            ))
            ->with(new MeshRenderer(meshId: 'cylinder', materialId: WoodMaterials::id('bamboo')))
            ->with(new BoxCollider3D(size: new Vec3(2.0, 2.0, 2.0), isStatic: true));

        // Right railing (with collision wall)
        $builder->entity('Hut_RailRight')
            ->with(new Transform3D(
                position: $this->rotateAroundHut($hx + $w * 0.45, $railY, $hz + $d * 0.5 + $porchD * 0.5, $hx, $hz, $hutYaw),
                rotation: $hutRot,
                scale: new Vec3($railThick, $railH * 0.5, $porchD * 0.5),
            ))
            ->with(new MeshRenderer(meshId: 'cylinder', materialId: WoodMaterials::id('bamboo')))
            ->with(new BoxCollider3D(size: new Vec3(2.0, 2.0, 2.0), isStatic: true));

        // Front railing (two sections with gap for steps in the middle, with collision)
        $frontRailW = ($w - 1.0) * 0.25;
        foreach ([-1, 1] as $side) {
            $rx = $side * ($w * 0.25 + 0.25);
            $builder->entity('Hut_RailFront_' . ($side > 0 ? 'R' : 'L'))
                ->with(new Transform3D(
                    position: $this->rotateAroundHut($hx + $rx, $railY, $hz + $d * 0.5 + $porchD, $hx, $hz, $hutYaw),
                    rotation: $hutRot,
                    scale: new Vec3($frontRailW, $railH * 0.5, $railThick),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: WoodMaterials::id('bamboo')))
                ->with(new BoxCollider3D(size: new Vec3(2.0, 2.0, 2.0), isStatic: true));
        }

        // Railing vertical supports (6 bamboo spindles)
        $spindles = [
            [-$w * 0.45, $d * 0.5 + 0.3],
            [-$w * 0.45, $d * 0.5 + $porchD],
            [$w * 0.45, $d * 0.5 + 0.3],
            [$w * 0.45, $d * 0.5 + $porchD],
            [-$w * 0.25, $d * 0.5 + $porchD],
            [$w * 0.25, $d * 0.5 + $porchD],
        ];
        foreach ($spindles as $spi => [$spx, $spz]) {
            $builder->entity("Hut_Spindle_{$spi}")
                ->with(new Transform3D(
                    position: $this->rotateAroundHut($hx + $spx, $hy + $railH * 0.5, $hz + $spz, $hx, $hz, $hutYaw),
                    rotation: $hutRot,
                    scale: new Vec3(0.02, $railH * 0.5, 0.02),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: WoodMaterials::id('bamboo_dark')));
        }

        // === STEPS (3 steps, each ≤0.28m high so CharacterController can walk up) ===
        $stepW = 0.9;
        $stepD = 0.35;
        $stepCount = 3;
        $stepH = $hy / $stepCount; // ~0.283m (under 0.3m stepHeight limit)
        $stepsZ = $d * 0.5 + $porchD; // starts at porch front edge, no gap
        for ($si = 0; $si < $stepCount; $si++) {
            // Each step is a full-height column from ground to step surface
            $columnH = $hy - $si * $stepH;
            $stepY = $columnH * 0.5;
            $stepZ = $stepsZ + ($si + 0.5) * $stepD;
            $builder->entity("Hut_Step_{$si}")
                ->with(new Transform3D(
                    position: $this->rotateAroundHut($hx, $stepY, $hz + $stepZ, $hx, $hz, $hutYaw),
                    rotation: $hutRot,
                    scale: new Vec3($stepW * 0.5, $columnH * 0.5, $stepD * 0.5),
                ))
                ->with(new MeshRenderer(meshId: 'box', materialId: WoodMaterials::id('plank_weathered')))
                ->with(new BoxCollider3D(size: new Vec3(2.0, 2.0, 2.0), isStatic: true));
        }

        // === ROOF (thatched, asymmetric — front extends over porch) ===
        $roof = RoofBuilder::thatched(
            width: $w, depth: $d,
            roofHeight: $roofH,
            overhang: 0.7,
            frontExtension: $porchD,
            rafterCount: 4,
        );
        $roofResult = $roof->withPrefix('Hut')->build(
            $builder,
            basePosition: new Vec3($hx, $hy + $wallH, $hz),
            baseRotation: $hutRot,
            materials: new RoofMaterials(
                panel: ThatchMaterials::id('roof'),
                panelBack: ThatchMaterials::id('roof_dark'),
                ridge: WoodMaterials::id('beam'),
                rafter: WoodMaterials::id('beam'),
                gable: WoodMaterials::id('plank_dark'),
            ),
        );

        // Front wall extension: fill the gap between wallH and the roof at the front wall.
        if ($frontWallExtraH > 0.05) {
            $this->buildHutWall($builder, 'Hut_FrontFill', $hx, $hy + $wallH, $hz,
                0.0, 0.0, $d * 0.5, $w + $overlap * 2, $frontWallExtraH, $wallT, $hutRot, WoodMaterials::id('plank'));
        }

        // Back wall extension: same treatment as front.
        if ($backWallExtraH > 0.05) {
            $this->buildHutWall($builder, 'Hut_BackFill', $hx, $hy + $wallH, $hz,
                0.0, 0.0, -$d * 0.5, $w + $overlap * 2, $backWallExtraH, $wallT, $hutRot, WoodMaterials::id('plank_dark'));
        }

        // === TABLE ===
        $tablePos = $this->rotateAroundHut($hx + 0.5, $hy, $hz - 0.3, $hx, $hz, $hutYaw);
        TableBuilder::rectangular(width: 0.9, depth: 0.6, height: 0.55)
            ->withPrefix('Hut')
            ->build($builder, $tablePos, $hutRot, new FurnitureMaterials(
                primary: WoodMaterials::id('table'),
                secondary: WoodMaterials::id('beam'),
            ));

        // === HAMMOCK ===
        $hamPos = $this->rotateAroundHut($hx - 0.6, $hy, $hz - 0.3, $hx, $hz, $hutYaw);
        HammockBuilder::standard(length: 1.6, postHeight: 1.2)
            ->withPrefix('Hut')
            ->build($builder, $hamPos, $hutRot, new FurnitureMaterials(
                primary: WoodMaterials::id('beam'),
                secondary: WoodMaterials::id('beam'),
                fabric: FabricMaterials::id('hammock'),
            ));

        // === DOOR (interactive, with frame, hinged on left edge) ===
        // Position flush with front wall (Z = d/2, centered in wall thickness)
        $doorPos = $this->rotateAroundHut($hx + 0.0, $hy + 0.8, $hz + $d * 0.5, $hx, $hz, $hutYaw);
        DoorBuilder::single(
            width: 1.0, height: 1.8, thickness: 0.04,
            hingeSide: 'left', maxAngle: 1.57,  // ~90° — stops flat against inner wall
            damping: 2.5, mass: 8.0, initialAngle: 0.1,
        )->withFrame(frameWidth: 0.06, frameDepth: $wallT)
         ->withPrefix('Hut')
         ->build(
            $builder,
            position: $doorPos,
            rotation: Quaternion::fromEuler(0.0, $hutYaw, 0.0),
            materials: new DoorMaterials(
                panel: WoodMaterials::id('door'),
                frame: WoodMaterials::id('beam'),
            ),
        );

        // === WINDOW ===
        $windowPos = $this->rotateAroundHut($hx + $w * 0.5 + 0.01, $hy + $wallH * 0.55, $hz, $hx, $hz, $hutYaw);
        WindowBuilder::cross(width: 0.7, height: 0.5)
            ->withPrefix('Hut')
            ->build($builder, $windowPos, Quaternion::fromEuler(0.0, $hutYaw, 0.0), new FurnitureMaterials(
                primary: WoodMaterials::id('window_frame'),
            ));

        // === HANGING LANTERN (on porch) ===
        $lanternPos = $this->rotateAroundHut($hx, $hy + $wallH - 0.1, $hz + $d * 0.5 + $porchD * 0.5, $hx, $hz, $hutYaw);
        LanternBuilder::hanging(ropeLength: 0.5)
            ->withLight(intensity: 1.5, radius: 6.0, color: '#FFCC66')
            ->withPrefix('Hut')
            ->build($builder, $lanternPos, $hutRot, new FurnitureMaterials(
                primary: FabricMaterials::id('rope_dark'),
                secondary: FabricMaterials::id('rope_dark'),
                metal: MetalMaterials::id('lantern_glass'),
            ));

        // === SHELF (against back wall) ===
        $shelfPos = $this->rotateAroundHut($hx - 0.2, $hy + 0.6, $hz - $d * 0.5 + 0.2, $hx, $hz, $hutYaw);
        ShelfBuilder::standard(width: 0.7, height: 0.8, depth: 0.25, shelves: 2)
            ->withPrefix('Hut')
            ->build($builder, $shelfPos, $hutRot, new FurnitureMaterials(
                primary: WoodMaterials::id('plank_weathered'),
            ));

        // === CRATE (on porch) ===
        $cratePos = $this->rotateAroundHut($hx + 1.2, $hy + 0.15, $hz + $d * 0.5 + 0.5, $hx, $hz, $hutYaw);
        CrateBuilder::wooden(width: 0.35, height: 0.3, depth: 0.35)
            ->dynamic(mass: 3.0)
            ->withPrefix('Hut')
            ->build($builder, $cratePos, $hutRot, new FurnitureMaterials(
                primary: WoodMaterials::id('plank_dark'),
            ));

    }

    /**
     * Build a plank wall using the procedural PlankWallMesh.
     */
    private function buildPlankWall(SceneBuilder $builder, string $name, float $hx, float $hy, float $hz,
        float $offX, float $offY, float $offZ, float $w, float $h, float $d,
        Quaternion $rot, string $matId, int $variant): void
    {
        $hutYaw = 0.4;
        $meshId = 'plank_wall_' . ($variant % 3);

        // PlankWallMesh is centered like BoxMesh (X/Y: -1 to +1), but has baked-in
        // depth (Z: ±0.08). Scale X/Y like BoxMesh, Z = 1.0 to preserve plank relief.
        $builder->entity($name)
            ->with(new Transform3D(
                position: $this->rotateAroundHut($hx + $offX, $hy + $offY, $hz + $offZ, $hx, $hz, $hutYaw),
                rotation: $rot,
                scale: new Vec3($w * 0.5, $h * 0.5, 1.0),
            ))
            ->with(new MeshRenderer(meshId: $meshId, materialId: $matId))
            ->with(new BoxCollider3D(size: new Vec3($w, $h, 0.16), isStatic: true));
    }

    /**
     * Build a simple box wall (for sections around door/window openings).
     */
    private function buildHutWall(SceneBuilder $builder, string $name, float $hx, float $hy, float $hz,
        float $offX, float $offY, float $offZ, float $w, float $h, float $d,
        Quaternion $rot, string $matId): void
    {
        $hutYaw = 0.4;
        // BoxMesh is 2×2×2. Collider size matches mesh; the engine's physics
        // now transforms corners through world matrix (handles rotation+scale).
        $builder->entity($name)
            ->with(new Transform3D(
                position: $this->rotateAroundHut($hx + $offX, $hy + $h * 0.5, $hz + $offZ, $hx, $hz, $hutYaw),
                rotation: $rot,
                scale: new Vec3($w * 0.5, $h * 0.5, $d * 0.5),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: $matId))
            ->with(new BoxCollider3D(size: new Vec3(2.0, 2.0, 2.0), isStatic: true));
    }

    private function buildHutWallWithDoor(SceneBuilder $builder, float $hx, float $hy, float $hz,
        float $w, float $d, float $wallH, float $wallT, Quaternion $hutRot): void
    {
        $doorW = 1.1; // wide enough for player capsule (0.6m diameter + margin)
        $doorH = 1.8;
        $overlap = $wallT;
        $wExt = $w + $overlap * 2; // wider to overlap side walls

        $panelW = ($wExt - $doorW) * 0.5;
        $this->buildHutWall($builder, 'Hut_FrontLeft', $hx, $hy, $hz,
            -($doorW * 0.5 + $panelW * 0.5), 0.0, $d * 0.5, $panelW, $wallH, $wallT, $hutRot, WoodMaterials::id('plank'));

        $this->buildHutWall($builder, 'Hut_FrontRight', $hx, $hy, $hz,
            $doorW * 0.5 + $panelW * 0.5, 0.0, $d * 0.5, $panelW, $wallH, $wallT, $hutRot, WoodMaterials::id('plank'));

        $topH = $wallH - $doorH;
        $this->buildHutWall($builder, 'Hut_FrontTop', $hx, $hy + $doorH, $hz,
            0.0, 0.0, $d * 0.5, $doorW + 0.1, $topH, $wallT, $hutRot, WoodMaterials::id('plank_dark'));
    }

    private function buildHutWallWithWindow(SceneBuilder $builder, float $hx, float $hy, float $hz,
        float $w, float $d, float $wallH, float $wallT, Quaternion $hutRot, float $roofH = 0.0): void
    {
        $winW = 0.7;
        $winH = 0.5;
        $winY = $wallH * 0.5;
        $overlap = $wallT;
        $dExt = $d + $overlap * 2;

        // Right wall extended to half-gable height (matching left wall)
        $totalSideWallH = $wallH + $roofH * 0.5;

        $this->buildHutWall($builder, 'Hut_RightLower', $hx, $hy, $hz,
            $w * 0.5, 0.0, 0.0, $wallT, $winY - $winH * 0.5, $dExt, $hutRot, WoodMaterials::id('plank'));

        // Above window: extends to half-gable height
        $aboveH = $totalSideWallH - ($winY + $winH * 0.5);
        $this->buildHutWall($builder, 'Hut_RightUpper', $hx, $hy + $winY + $winH * 0.5, $hz,
            $w * 0.5, 0.0, 0.0, $wallT, $aboveH, $dExt, $hutRot, WoodMaterials::id('plank'));

        $sideD = ($dExt - $winW) * 0.5;
        $this->buildHutWall($builder, 'Hut_RightWinLeft', $hx, $hy + $winY - $winH * 0.5, $hz,
            $w * 0.5, 0.0, -($winW * 0.5 + $sideD * 0.5), $wallT, $winH, $sideD, $hutRot, WoodMaterials::id('plank'));

        $this->buildHutWall($builder, 'Hut_RightWinRight', $hx, $hy + $winY - $winH * 0.5, $hz,
            $w * 0.5, 0.0, $winW * 0.5 + $sideD * 0.5, $wallT, $winH, $sideD, $hutRot, WoodMaterials::id('plank'));
    }

    /**
     * Rotate a point around the hut center (hx, hz) by yaw angle.
     * Uses negated sin to match Quaternion::fromEuler(0, yaw, 0) convention,
     * which rotates Z in the -sin direction (clockwise when viewed from +Y).
     */
    private function rotateAroundHut(float $x, float $y, float $z, float $cx, float $cz, float $yaw): Vec3
    {
        $dx = $x - $cx;
        $dz = $z - $cz;
        $cosY = cos($yaw);
        $sinY = sin($yaw);
        return new Vec3(
            $cx + $dx * $cosY + $dz * $sinY,
            $y,
            $cz - $dx * $sinY + $dz * $cosY,
        );
    }
}
