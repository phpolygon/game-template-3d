<?php

declare(strict_types=1);

namespace App\Prefab;

use PHPolygon\Math\Mat4;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Water surface built from individual pixel-like elements.
 * Each pixel has its own slight tilt, creating organic wave patterns
 * instead of hard stripe boundaries.
 */
class WaterPixel
{
    /**
     * Generate instanced transform matrices for a water surface.
     *
     * @param float $xMin  Left edge
     * @param float $xMax  Right edge
     * @param float $zMin  Far ocean edge
     * @param float $zMax  Shore edge
     * @param float $step  Distance between pixel centers
     * @param callable(float $x, float $z): array{y: float, material: string} $heightFn
     * @return array<string, list<Mat4>> Material ID => transform matrices
     */
    public static function generateSurface(
        float $xMin,
        float $xMax,
        float $zMin,
        float $zMax,
        float $step,
        callable $heightFn,
    ): array {
        $matricesByMaterial = [];

        for ($x = $xMin; $x <= $xMax; $x += $step) {
            for ($z = $zMin; $z <= $zMax; $z += $step) {
                // Slight jitter — water isn't a perfect grid
                $jitterX = self::pseudoRandom($x * 47.3 + $z * 83.1) * $step * 0.25;
                $jitterZ = self::pseudoRandom($x * 61.7 + $z * 29.3) * $step * 0.25;
                $px = $x + $jitterX;
                $pz = $z + $jitterZ;

                $info = $heightFn($px, $pz);
                $py = $info['y'];
                $materialId = $info['material'];

                // Water pixels tilt more than sand — wave motion
                $tiltX = self::pseudoRandom($px * 23.7 + $pz * 57.3) * 0.03;
                $tiltZ = self::pseudoRandom($px * 41.1 + $pz * 13.9) * 0.03;
                $rotY = self::pseudoRandom($px * 67.3 + $pz * 31.7) * M_PI;

                $rotation = Quaternion::fromEuler($tiltX, $rotY, $tiltZ);
                $scale = new Vec3(
                    $step * 1.05,
                    0.01,
                    $step * 1.05,
                );

                $matrix = Mat4::trs(new Vec3($px, $py, $pz), $rotation, $scale);

                if (!isset($matricesByMaterial[$materialId])) {
                    $matricesByMaterial[$materialId] = [];
                }
                $matricesByMaterial[$materialId][] = $matrix;
            }
        }

        return $matricesByMaterial;
    }

    private static function pseudoRandom(float $seed): float
    {
        $n = sin($seed * 12.9898 + 78.233) * 43758.5453;
        return ($n - floor($n)) * 2.0 - 1.0;
    }
}
