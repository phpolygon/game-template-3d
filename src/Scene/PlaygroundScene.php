<?php

declare(strict_types=1);

namespace App\Scene;

use App\Component\FirstPersonCamera;
use App\Geometry\RainbowArcMesh;
use App\Prefab\PalmBuilder;
use App\Component\PlayerBody;
use App\Geometry\PlankWallMesh;
use App\Prefab\SandGrain;
use App\Prefab\WaterPixel;
use PHPolygon\Component\InstancedTerrain;
use PHPolygon\Math\Mat4;
use PHPolygon\Component\Atmosphere;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\Season;
use PHPolygon\Component\Weather;
use PHPolygon\Prefab\Door\DoorBuilder;
use PHPolygon\Prefab\Door\DoorMaterials;
use PHPolygon\Prefab\Furniture\CrateBuilder;
use PHPolygon\Prefab\Furniture\FurnitureMaterials;
use PHPolygon\Prefab\Furniture\HammockBuilder;
use PHPolygon\Prefab\Furniture\LanternBuilder;
use PHPolygon\Prefab\Furniture\ShelfBuilder;
use PHPolygon\Prefab\Furniture\TableBuilder;
use PHPolygon\Prefab\Furniture\WindowBuilder;
use PHPolygon\Component\Wind;
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

        $this->buildPlayer($builder);
        $this->buildLighting($builder);
        $this->buildWind($builder);

        // Day/night cycle — 60s full day, start at morning
        $builder->entity('DayNight')
            ->with(new Transform3D())
            ->with(new DayNightCycle(
                timeOfDay: 0.35,
                dayDuration: 60.0,
                dayCount: 4.0, // Start at full moon (half lunar cycle)
            ));

        // Seasons — 4 minutes per full year (1 min per season)
        $builder->entity('Seasons')
            ->with(new Transform3D())
            ->with(new Season(
                yearProgress: 0.1,  // start in early spring
                yearDuration: 240.0,
            ));

        // Weather + Atmosphere — physics-based weather driven by atmospheric pressure
        $builder->entity('Weather')
            ->with(new Transform3D())
            ->with(new Weather(
                cloudCoverage: 0.2,
                humidity: 0.5,
                temperature: 22.0,
            ))
            ->with(new Atmosphere());

        // Precipitation particle pool (150 particles, reused by PrecipitationSystem)
        MaterialRegistry::register('precipitation', new Material(
            albedo: Color::hex('#8090B0'),
            roughness: 0.1,
            alpha: 0.0, // invisible initially
        ));
        if (!MeshRegistry::has('precip_quad')) {
            MeshRegistry::register('precip_quad', PlaneMesh::generate(1.0, 1.0, 1));
        }
        for ($i = 0; $i < 150; $i++) {
            $builder->entity("Precip_{$i}")
                ->with(new Transform3D(
                    position: new Vec3(0, -100, 0), // hidden below world
                    scale: new Vec3(0.01, 0.15, 0.01),
                ))
                ->with(new MeshRenderer(meshId: 'precip_quad', materialId: 'precipitation'));
        }
        $this->buildTerrain($builder);
        $this->buildOceanAndWaves($builder);
        $this->buildPalmTrees($builder);
        $this->buildRocks($builder);
        $this->buildBeachDetails($builder);
        $this->buildBeachHut($builder);
        // Clouds are drawn by the atmospheric sky shader — no mesh entities.
        $this->buildRainbow($builder);
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

        // Sun and moon are drawn analytically by the atmospheric sky shader;
        // the Sun directional light above is what actually illuminates the
        // scene. No sun_disc / moon_disc / moon_glow sphere entities.

        // Sky fill + static point-light accents removed — DayNightSystem's
        // directional sun + time-driven ambient drive the scene entirely so
        // lighting follows the real time of day instead of being baked into
        // fixed warm/cool spots.
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
                zMin: -50.0,
                zMax: 40.0,
                step: 0.5,
                heightFn: fn(float $x, float $z) => $this->beachHeightFn($x, $z),
            ));
        }

        $builder->entity('SandTerrain')
            ->with(new Transform3D())
            ->with(new MeshRenderer(meshId: 'beach_terrain', materialId: 'sand_terrain'));

        $this->buildTerrainColliders($builder);
    }

    private function buildTerrainColliders(SceneBuilder $builder): void
    {
        // HeightmapCollider3D: O(1) terrain height query (AAA standard).
        // Much faster than MeshCollider3D (no BVH triangle test needed).
        $hm = new \PHPolygon\Component\HeightmapCollider3D(
            gridWidth: 128,
            gridDepth: 128,
            worldMinX: -55.0,
            worldMaxX: 55.0,
            worldMinZ: -50.0,
            worldMaxZ: 45.0,
        );
        $hm->populateFromFunction(fn(float $x, float $z) => $this->beachHeightFn($x, $z)['y']);

        $builder->entity('TerrainCollider')
            ->with(new Transform3D())
            ->with($hm);

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
        // Shoreline at Z≈-5, gentle slope into water, gradual seabed descent
        $y = 0.0;
        if ($z > 0.0) {
            $y = $z * 0.02; // gentle uphill slope toward dunes
        } elseif ($z > -5.0) {
            // Shoreline zone: very gentle slope into water
            $y = $z * 0.04; // Y=-0.2 at Z=-5
        } else {
            // Underwater: gradual seabed slope (not a cliff)
            $shoreY = -5.0 * 0.04; // -0.2 at shoreline
            $depth = -5.0 - $z;    // distance from shore
            // Smooth curve: fast at first, levels off deeper
            $y = $shoreY - $depth * 0.12 - $depth * $depth * 0.002;
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

        if ($z < -8.0) {
            $material = 'seafloor';
        } elseif ($z < -3.0) {
            $material = "sand_damp_{$variant}";
        } elseif ($z < -1.0) {
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
            MeshRegistry::register('water_plane', PlaneMesh::generate(140.0, 90.0, 192));
        }

        // One water surface — the procedural water shader (proc_mode 2)
        // handles depth-based absorption, wave normals, fresnel reflection,
        // sun specular, and shoreline foam directly. No separate deep-water
        // layer needed.
        $builder->entity('WaterSurface')
            ->with(new Transform3D(
                position: new Vec3(0.0, -0.3, -44.0),
            ))
            ->with(new MeshRenderer(meshId: 'water_plane', materialId: 'water_surface'));
    }

    /**
     * (Legacy waterHeightFn + depth-variant water materials removed — the
     * procedural water shader does it all at pixel level now.)
     */
    private function buildPalmTrees(SceneBuilder $builder): void
    {
        $palms = [
            ['pos' => new Vec3(-6.0, 0.0, 5.0),   'height' => 5.5, 'lean' => 0.15,  'coconuts' => 3],
            ['pos' => new Vec3(8.0, 0.0, 7.0),     'height' => 6.0, 'lean' => -0.1,  'coconuts' => 2],
            ['pos' => new Vec3(-14.0, 0.0, 12.0),  'height' => 4.5, 'lean' => 0.2,   'coconuts' => 4],
            ['pos' => new Vec3(3.0, 0.0, 16.0),    'height' => 5.0, 'lean' => -0.08, 'coconuts' => 2],
            ['pos' => new Vec3(16.0, 0.0, 4.0),    'height' => 5.8, 'lean' => 0.12,  'coconuts' => 3],
            ['pos' => new Vec3(-20.0, 0.0, 9.0),   'height' => 4.8, 'lean' => 0.18,  'coconuts' => 3],
            ['pos' => new Vec3(-3.0, 0.0, 20.0),   'height' => 6.2, 'lean' => -0.05, 'coconuts' => 2],
            ['pos' => new Vec3(22.0, 0.0, 10.0),   'height' => 5.2, 'lean' => 0.1,   'coconuts' => 4],
            ['pos' => new Vec3(-25.0, 0.0, 16.0),  'height' => 4.6, 'lean' => 0.22,  'coconuts' => 2],
            ['pos' => new Vec3(12.0, 0.0, 18.0),   'height' => 5.6, 'lean' => -0.13, 'coconuts' => 3],
        ];

        foreach ($palms as $i => $palm) {
            PalmBuilder::at($palm['pos'])
                ->height($palm['height'])
                ->lean($palm['lean'])
                ->fronds(30)
                ->coconuts($palm['coconuts'])
                ->index($i)
                ->build($builder);
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
            ['x' => -4.0, 'z' => -3.0, 'scale' => new Vec3(1.5, 1.0, 1.2), 'mat' => 'rock'],
            ['x' => 5.0, 'z' => -5.0, 'scale' => new Vec3(0.9, 0.6, 1.0), 'mat' => 'rock_dark'],
            ['x' => -10.0, 'z' => 2.0, 'scale' => new Vec3(1.8, 1.2, 1.5), 'mat' => 'rock'],
            ['x' => 12.0, 'z' => -2.0, 'scale' => new Vec3(0.8, 0.7, 0.9), 'mat' => 'rock_mossy'],
            ['x' => -2.0, 'z' => -6.0, 'scale' => new Vec3(2.2, 1.4, 2.0), 'mat' => 'rock_dark'],
            ['x' => 9.0, 'z' => 1.0, 'scale' => new Vec3(0.6, 0.5, 0.7), 'mat' => 'rock'],
            ['x' => -8.0, 'z' => -4.0, 'scale' => new Vec3(1.2, 0.8, 1.3), 'mat' => 'rock_mossy'],
            ['x' => 18.0, 'z' => -1.0, 'scale' => new Vec3(1.0, 0.9, 1.1), 'mat' => 'rock_dark'],
            ['x' => -16.0, 'z' => 0.0, 'scale' => new Vec3(1.6, 1.1, 1.4), 'mat' => 'rock'],
            ['x' => 7.0, 'z' => -7.0, 'scale' => new Vec3(0.5, 0.35, 0.6), 'mat' => 'rock_dark'],
        ];

        foreach ($rocks as $i => $rock) {
            // Place on terrain: query height + sink half the scaled height into ground
            $terrainY = $this->beachHeightFn($rock['x'], $rock['z'])['y'];
            $sinkFactor = $rock['scale']->y * 0.3; // partially buried
            $pos = new Vec3($rock['x'], $terrainY - $sinkFactor, $rock['z']);

            $rotation = Quaternion::fromEuler(
                sin($i * 1.7) * 0.2,
                $i * 0.8,
                cos($i * 2.3) * 0.15,
            );

            $builder->entity("Rock_{$i}")
                ->with(new Transform3D(
                    position: $pos,
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

    private function buildRainbow(SceneBuilder $builder): void
    {
        if (!MeshRegistry::has('rainbow_arc')) {
            MeshRegistry::register('rainbow_arc', RainbowArcMesh::generate(80.0, 2.5, 48, 8));
        }

        MaterialRegistry::register('rainbow', new Material(
            albedo: Color::hex('#FFFFFF'),
            emission: Color::hex('#000000'),
            alpha: 0.0, // hidden initially
        ));

        // Rainbow entity — positioned far away, hidden below horizon until triggered
        $builder->entity('Rainbow')
            ->with(new Transform3D(
                position: new Vec3(0.0, -200.0, -60.0),
                scale: new Vec3(1.0, 1.0, 1.0),
            ))
            ->with(new MeshRenderer(meshId: 'rainbow_arc', materialId: 'rainbow'));
    }

    private function registerMeshes(): void
    {
        if (!MeshRegistry::has('box')) {
            MeshRegistry::register('box', BoxMesh::generate(2.0, 2.0, 2.0));
        }
        if (!MeshRegistry::has('sphere')) {
            MeshRegistry::register('sphere', SphereMesh::generate(1.0, 24, 36));
        }
        if (!MeshRegistry::has('hemisphere')) {
            MeshRegistry::register('hemisphere', \App\Geometry\HemisphereMesh::generate(1.0, 16, 36));
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
        // Sky is rendered via a procedural cubemap skybox driven by DayNightSystem
        // (SetSkybox command). No overlay hemispheres, no sky_* materials needed.

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

        // Sun — single bright emissive sphere. DayNightSystem animates the
        // emission color/intensity based on time-of-day.
        MaterialRegistry::register('sun_disc', new Material(
            albedo: Color::hex('#000000'),
            roughness: 1.0,
            emission: Color::hex('#FFF2B0'),
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

        // Single animated water surface — the procedural water shader
        // (proc_mode 2) derives depth-based absorption, fresnel reflection,
        // wave normals, sun specular and shoreline foam all from one
        // material. No depth-variant or foam mesh materials needed.
        MaterialRegistry::register('water_surface', new Material(
            albedo: Color::hex('#3A8B9B'),
            roughness: 0.02,
            metallic: 0.8,
            alpha: 0.55,
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
            albedo: Color::hex('#9A9A9A'),
            roughness: 0.85,
        ));
        MaterialRegistry::register('rock_dark', new Material(
            albedo: Color::hex('#787878'),
            roughness: 0.9,
        ));
        MaterialRegistry::register('rock_mossy', new Material(
            albedo: Color::hex('#7A8A75'),
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
        $lanternPos = $this->rotateAroundHut($hx, $hy + $wallH - 0.1, $hz + $d * 0.5 + 0.3, $hx, $hz, $hutYaw);
        LanternBuilder::hanging(ropeLength: 0.5)
            ->withLight(intensity: 1.2, radius: 3.0, color: '#FFCC66')
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
