<?php

declare(strict_types=1);

namespace App\Prefab;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * A single sand grain — a small flat box with individual position and rotation.
 * Thousands of these form the beach terrain. Each grain has its own tilt,
 * creating organic unevenness instead of hard plane edges.
 */
class SandGrain
{
    private const GRAIN_WIDTH = 0.52;
    private const GRAIN_HEIGHT = 0.015;
    private const GRAIN_DEPTH = 0.52;

    /**
     * Generate instanced transform matrices for a beach terrain.
     *
     * @param float $xMin  Left edge of terrain
     * @param float $xMax  Right edge of terrain
     * @param float $zMin  Water-side edge
     * @param float $zMax  Back-beach edge
     * @param float $step  Distance between grain centers
     * @param callable(float $x, float $z): array{y: float, material: string} $heightFn
     *     Returns height and material ID for a given (x, z) position
     * @return array<string, list<Mat4>> Material ID => list of transform matrices
     */
    public static function generateTerrain(
        float $xMin,
        float $xMax,
        float $zMin,
        float $zMax,
        float $step,
        callable $heightFn,
    ): array {
        $matricesByMaterial = [];

        // Pre-compute grain scale — uniform for pixel look, slightly larger than step for overlap
        $grainScale = new Vec3($step * 1.05, self::GRAIN_HEIGHT, $step * 1.05);
        $rotation = Quaternion::identity();

        for ($x = $xMin; $x <= $xMax; $x += $step) {
            for ($z = $zMin; $z <= $zMax; $z += $step) {
                $px = $x;
                $pz = $z;

                $info = $heightFn($px, $pz);
                $py = $info['y'];
                $materialId = $info['material'];

                $matrix = Mat4::trs(new Vec3($px, $py, $pz), $rotation, $grainScale);

                if (!isset($matricesByMaterial[$materialId])) {
                    $matricesByMaterial[$materialId] = [];
                }
                $matricesByMaterial[$materialId][] = $matrix;
            }
        }

        return $matricesByMaterial;
    }

    /**
     * Deterministic pseudo-random in range [-1, 1] based on a seed value.
     * Same input always produces the same output — no randomness between frames.
     */
    public static function pseudoRandom(float $seed): float
    {
        $n = sin($seed * 12.9898 + 78.233) * 43758.5453;
        return ($n - floor($n)) * 2.0 - 1.0;
    }
}
