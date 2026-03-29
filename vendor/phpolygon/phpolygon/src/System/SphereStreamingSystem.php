<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\HeightmapCollider3D;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\TerrainChunk;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\MaterialRegistry;
use PHPolygon\Rendering\SectorInfo;
use PHPolygon\Rendering\SphereStreamingRenderer;

/**
 * Integrates SphereStreamingRenderer with the ECS world.
 *
 * On initialization: generates all sectors as entities with TerrainChunk + HeightmapCollider3D.
 * On player movement: queues sector regeneration, prioritized by movement direction.
 *
 * Key principle: Camera rotation is free (everything in 360° is in VRAM).
 * Only player MOVEMENT triggers streaming. Movement is predictable and slow.
 * The streaming problem is reduced from 2D (position + rotation) to 1D (position only).
 */
class SphereStreamingSystem extends AbstractSystem
{
    private SphereStreamingRenderer $streamer;
    private bool $initialized = false;

    /** @var array<string, int> Sector key → entity ID */
    private array $sectorEntities = [];

    /** Height function for terrain generation */
    private ?\Closure $heightFunction = null;

    /** Material ID for terrain meshes */
    private string $terrainMaterialId = 'sand_terrain';

    /** Grid resolution per sector (for heightmap and mesh) */
    private int $sectorResolution = 16;

    public function __construct(
        float $renderRadius = 200.0,
        int $horizontalSectors = 24,
        int $verticalBands = 6,
    ) {
        $this->streamer = new SphereStreamingRenderer(
            renderRadius: $renderRadius,
            horizontalSectors: $horizontalSectors,
            verticalBands: $verticalBands,
            regenThreshold: $renderRadius / $verticalBands * 0.5,
        );
    }

    /**
     * Set the height function for procedural terrain generation.
     * @param callable(float, float): float $fn Takes (worldX, worldZ) → height Y
     */
    public function setHeightFunction(callable $fn): void
    {
        $this->heightFunction = \Closure::fromCallable($fn);
    }

    public function setTerrainMaterial(string $materialId): void
    {
        $this->terrainMaterialId = $materialId;
    }

    public function setSectorResolution(int $resolution): void
    {
        $this->sectorResolution = $resolution;
    }

    public function update(World $world, float $dt): void
    {
        if ($this->heightFunction === null) return;

        // Find player position + velocity
        $playerPos = Vec3::zero();
        $playerVel = Vec3::zero();
        foreach ($world->query(Transform3D::class, CharacterController3D::class) as $entity) {
            $t = $entity->get(Transform3D::class);
            $c = $entity->get(CharacterController3D::class);
            $playerPos = $t->position;
            $playerVel = $c->velocity;
            break;
        }
        // Fallback: camera position
        if ($playerPos->x === 0.0 && $playerPos->z === 0.0) {
            foreach ($world->query(Transform3D::class, Camera3DComponent::class) as $entity) {
                $playerPos = $entity->get(Transform3D::class)->position;
                break;
            }
        }

        if (!$this->initialized) {
            $this->streamer->initializeSphere(
                $playerPos,
                fn(SectorInfo $s) => $this->createSectorEntity($world, $s),
            );
            $this->initialized = true;
            return;
        }

        $this->streamer->update(
            $playerPos,
            $playerVel,
            fn(SectorInfo $s) => $this->createSectorEntity($world, $s),
            fn(SectorInfo $s) => $this->recycleSectorEntity($world, $s),
        );
    }

    private function createSectorEntity(World $world, SectorInfo $sector): void
    {
        $key = "{$sector->hIndex}_{$sector->vIndex}";

        // Generate terrain mesh for this sector
        $meshId = "terrain_sector_{$key}";
        $meshData = $this->generateSectorMesh($sector);
        MeshRegistry::register($meshId, $meshData);

        // Create or reuse entity
        if (isset($this->sectorEntities[$key])) {
            // Update existing entity
            $entityId = $this->sectorEntities[$key];
            if ($world->isAlive($entityId)) {
                $mr = $world->tryGetComponent($entityId, MeshRenderer::class);
                if ($mr instanceof MeshRenderer) {
                    $mr->meshId = $meshId;
                }
                // Update heightmap collider
                $hm = $world->tryGetComponent($entityId, HeightmapCollider3D::class);
                if ($hm instanceof HeightmapCollider3D) {
                    $hm->worldMinX = $sector->worldMinX;
                    $hm->worldMaxX = $sector->worldMaxX;
                    $hm->worldMinZ = $sector->worldMinZ;
                    $hm->worldMaxZ = $sector->worldMaxZ;
                    $hm->populateFromFunction($this->heightFunction);
                }
                return;
            }
        }

        // Create new entity
        $entity = $world->createEntity();
        $world->attachComponent($entity->id, new Transform3D());
        $world->attachComponent($entity->id, new MeshRenderer(meshId: $meshId, materialId: $this->terrainMaterialId));
        $world->attachComponent($entity->id, new TerrainChunk(
            chunkX: $sector->hIndex,
            chunkZ: $sector->vIndex,
            worldMinX: $sector->worldMinX,
            worldMaxX: $sector->worldMaxX,
            worldMinZ: $sector->worldMinZ,
            worldMaxZ: $sector->worldMaxZ,
        ));

        // HeightmapCollider — O(1) physics queries
        $hm = new HeightmapCollider3D(
            gridWidth: $this->sectorResolution,
            gridDepth: $this->sectorResolution,
            worldMinX: $sector->worldMinX,
            worldMaxX: $sector->worldMaxX,
            worldMinZ: $sector->worldMinZ,
            worldMaxZ: $sector->worldMaxZ,
        );
        $hm->populateFromFunction($this->heightFunction);
        $world->attachComponent($entity->id, $hm);

        $this->sectorEntities[$key] = $entity->id;
    }

    private function recycleSectorEntity(World $world, SectorInfo $sector): void
    {
        $key = "{$sector->hIndex}_{$sector->vIndex}";
        if (isset($this->sectorEntities[$key])) {
            $entityId = $this->sectorEntities[$key];
            if ($world->isAlive($entityId)) {
                // Hide by moving below world
                $t = $world->tryGetComponent($entityId, Transform3D::class);
                if ($t instanceof Transform3D) {
                    $t->position = new Vec3($t->position->x, -1000.0, $t->position->z);
                }
            }
        }
    }

    /**
     * Generate a terrain mesh for one sector using the height function.
     */
    private function generateSectorMesh(SectorInfo $sector): MeshData
    {
        $res = $this->sectorResolution;
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];

        $xStep = ($sector->worldMaxX - $sector->worldMinX) / max(1, $res - 1);
        $zStep = ($sector->worldMaxZ - $sector->worldMinZ) / max(1, $res - 1);

        // Generate vertices
        for ($z = 0; $z < $res; $z++) {
            for ($x = 0; $x < $res; $x++) {
                $wx = $sector->worldMinX + $x * $xStep;
                $wz = $sector->worldMinZ + $z * $zStep;
                $wy = ($this->heightFunction)($wx, $wz);

                $vertices[] = $wx;
                $vertices[] = $wy;
                $vertices[] = $wz;

                // Normal via finite differences
                $eps = max($xStep, $zStep) * 0.5;
                $hR = ($this->heightFunction)($wx + $eps, $wz);
                $hF = ($this->heightFunction)($wx, $wz + $eps);
                $nx = ($wy - $hR);
                $nz = ($wy - $hF);
                $ny = $eps;
                $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
                if ($len > 0.0001) { $nx /= $len; $ny /= $len; $nz /= $len; }
                else { $nx = 0; $ny = 1; $nz = 0; }

                $normals[] = $nx;
                $normals[] = $ny;
                $normals[] = $nz;

                // UVs: encode terrain zone for procedural sand shader
                $uvs[] = (float) $x / ($res - 1);
                $uvs[] = (float) $z / ($res - 1);
            }
        }

        // Generate indices (triangle grid)
        for ($z = 0; $z < $res - 1; $z++) {
            for ($x = 0; $x < $res - 1; $x++) {
                $a = $z * $res + $x;
                $b = $a + 1;
                $c = $a + $res;
                $d = $c + 1;
                $indices[] = $a; $indices[] = $c; $indices[] = $b;
                $indices[] = $b; $indices[] = $c; $indices[] = $d;
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
