<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Terrain chunk for streamed world loading.
 * Each chunk covers a rectangular area of the world and contains:
 * - A mesh ID for visual rendering
 * - A HeightmapCollider3D for physics
 * - LOD level based on distance to camera
 *
 * Chunks are loaded/unloaded by TerrainChunkSystem based on camera proximity.
 */
#[Serializable]
#[Category('Terrain')]
class TerrainChunk extends AbstractComponent
{
    /** Chunk grid coordinates */
    #[Property]
    public int $chunkX;

    #[Property]
    public int $chunkZ;

    /** World-space bounds */
    #[Property]
    public float $worldMinX;

    #[Property]
    public float $worldMaxX;

    #[Property]
    public float $worldMinZ;

    #[Property]
    public float $worldMaxZ;

    /** Current LOD level (0 = highest detail, higher = coarser) */
    #[Hidden]
    public int $lodLevel = 0;

    /** Whether the chunk's mesh and collider are loaded */
    #[Hidden]
    public bool $loaded = false;

    /** Whether the chunk is currently visible (within view distance) */
    #[Hidden]
    public bool $visible = true;

    /** Mesh ID in MeshRegistry (generated on load) */
    #[Hidden]
    public string $meshId = '';

    /** Distance to camera (updated each frame for LOD/streaming decisions) */
    #[Hidden]
    public float $cameraDistance = 0.0;

    public function __construct(
        int $chunkX = 0,
        int $chunkZ = 0,
        float $worldMinX = 0.0,
        float $worldMaxX = 0.0,
        float $worldMinZ = 0.0,
        float $worldMaxZ = 0.0,
    ) {
        $this->chunkX = $chunkX;
        $this->chunkZ = $chunkZ;
        $this->worldMinX = $worldMinX;
        $this->worldMaxX = $worldMaxX;
        $this->worldMinZ = $worldMinZ;
        $this->worldMaxZ = $worldMaxZ;
    }

    public function getCenterX(): float
    {
        return ($this->worldMinX + $this->worldMaxX) * 0.5;
    }

    public function getCenterZ(): float
    {
        return ($this->worldMinZ + $this->worldMaxZ) * 0.5;
    }
}
