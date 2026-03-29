<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Heightmap-based terrain collider.
 * Stores a grid of height values for fast terrain collision queries.
 * Much faster than MeshCollider3D for terrain — no BVH needed,
 * direct O(1) height lookup per (x, z) position.
 *
 * AAA standard: CryEngine/Dunia (Far Cry), REDengine (Cyberpunk)
 * all use heightmap colliders for terrain.
 */
#[Serializable]
#[Category('Physics')]
class HeightmapCollider3D extends AbstractComponent
{
    /** Grid resolution in X direction */
    #[Property]
    public int $gridWidth;

    /** Grid resolution in Z direction */
    #[Property]
    public int $gridDepth;

    /** World-space bounds: min X */
    #[Property]
    public float $worldMinX;

    /** World-space bounds: max X */
    #[Property]
    public float $worldMaxX;

    /** World-space bounds: min Z */
    #[Property]
    public float $worldMinZ;

    /** World-space bounds: max Z */
    #[Property]
    public float $worldMaxZ;

    /** Flat array of height values [gridWidth × gridDepth], row-major (Z rows, X columns) */
    #[Hidden]
    public array $heights = [];

    /** Whether this collider has been populated with data */
    #[Hidden]
    public bool $populated = false;

    public function __construct(
        int $gridWidth = 64,
        int $gridDepth = 64,
        float $worldMinX = -50.0,
        float $worldMaxX = 50.0,
        float $worldMinZ = -50.0,
        float $worldMaxZ = 50.0,
    ) {
        $this->gridWidth = $gridWidth;
        $this->gridDepth = $gridDepth;
        $this->worldMinX = $worldMinX;
        $this->worldMaxX = $worldMaxX;
        $this->worldMinZ = $worldMinZ;
        $this->worldMaxZ = $worldMaxZ;
    }

    /**
     * Populate heights from a height function.
     * @param callable(float, float): float $heightFn Takes (worldX, worldZ) → height Y
     */
    public function populateFromFunction(callable $heightFn): void
    {
        $this->heights = [];
        $cellW = ($this->worldMaxX - $this->worldMinX) / max(1, $this->gridWidth - 1);
        $cellD = ($this->worldMaxZ - $this->worldMinZ) / max(1, $this->gridDepth - 1);

        for ($z = 0; $z < $this->gridDepth; $z++) {
            $wz = $this->worldMinZ + $z * $cellD;
            for ($x = 0; $x < $this->gridWidth; $x++) {
                $wx = $this->worldMinX + $x * $cellW;
                $this->heights[$z * $this->gridWidth + $x] = $heightFn($wx, $wz);
            }
        }
        $this->populated = true;
    }

    /**
     * Query height at a world position with bilinear interpolation.
     * Returns null if position is outside the heightmap bounds.
     */
    public function getHeightAt(float $worldX, float $worldZ): ?float
    {
        if (!$this->populated) return null;
        if ($worldX < $this->worldMinX || $worldX > $this->worldMaxX ||
            $worldZ < $this->worldMinZ || $worldZ > $this->worldMaxZ) {
            return null;
        }

        // Map world coords to grid coords
        $gx = ($worldX - $this->worldMinX) / ($this->worldMaxX - $this->worldMinX) * ($this->gridWidth - 1);
        $gz = ($worldZ - $this->worldMinZ) / ($this->worldMaxZ - $this->worldMinZ) * ($this->gridDepth - 1);

        $ix = (int) floor($gx);
        $iz = (int) floor($gz);
        $fx = $gx - $ix;
        $fz = $gz - $iz;

        // Clamp to grid bounds
        $ix = max(0, min($ix, $this->gridWidth - 2));
        $iz = max(0, min($iz, $this->gridDepth - 2));

        // Bilinear interpolation of 4 surrounding heights
        $h00 = $this->heights[$iz * $this->gridWidth + $ix] ?? 0.0;
        $h10 = $this->heights[$iz * $this->gridWidth + $ix + 1] ?? 0.0;
        $h01 = $this->heights[($iz + 1) * $this->gridWidth + $ix] ?? 0.0;
        $h11 = $this->heights[($iz + 1) * $this->gridWidth + $ix + 1] ?? 0.0;

        $h0 = $h00 + ($h10 - $h00) * $fx;
        $h1 = $h01 + ($h11 - $h01) * $fx;

        return $h0 + ($h1 - $h0) * $fz;
    }

    /**
     * Get the surface normal at a world position (via finite differences).
     */
    public function getNormalAt(float $worldX, float $worldZ): ?array
    {
        $eps = ($this->worldMaxX - $this->worldMinX) / $this->gridWidth;
        $hC = $this->getHeightAt($worldX, $worldZ);
        $hR = $this->getHeightAt($worldX + $eps, $worldZ);
        $hF = $this->getHeightAt($worldX, $worldZ + $eps);

        if ($hC === null || $hR === null || $hF === null) return null;

        // Cross product of tangent vectors
        $tx = $eps;  $ty = $hR - $hC; $tz = 0.0;
        $bx = 0.0;   $by = $hF - $hC; $bz = $eps;

        $nx = $ty * $bz - $tz * $by;
        $ny = $tz * $bx - $tx * $bz;
        $nz = $tx * $by - $ty * $bx;
        $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
        if ($len < 0.0001) return [0.0, 1.0, 0.0];

        return [$nx / $len, $ny / $len, $nz / $len];
    }
}
