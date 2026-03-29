<?php

declare(strict_types=1);

namespace App\Geometry;

use PHPolygon\Geometry\MeshData;

/**
 * Generates a wall mesh made of individual horizontal planks.
 * Each plank has slight Z-offset, varying height, and thin gaps between planks.
 * Creates a rustic, weathered look when combined with the wood plank shader (proc_mode 7).
 *
 * Coordinate convention: same as BoxMesh — centered at origin, base size 2×2×depth.
 * X: -1 to +1, Y: -1 to +1, Z: centered around 0.
 * Scale works identically to BoxMesh: scale = desiredWorldSize / 2.
 */
class PlankWallMesh
{
    /**
     * @param int   $seed       Variation seed — different seed = different plank pattern
     * @param float $gapSize    Gap between planks (in normalized 0-2 space)
     * @param float $maxOffset  Maximum Z-offset per plank (depth variation)
     */
    public static function generate(
        int $seed = 0,
        float $gapSize = 0.016,
        float $maxOffset = 0.03,
    ): MeshData {
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];

        // Base size 2×2 (matching BoxMesh convention: -1 to +1)
        $halfW = 1.0;
        $halfH = 1.0;
        $halfD = 0.08; // thin wall

        $y = -$halfH; // start at bottom (-1)
        $plankIndex = 0;

        while ($y < $halfH) {
            // Varying plank height: 0.15 to 0.25 in normalized space
            $plankH = 0.15 + abs(self::pseudoRandom($plankIndex * 7.3 + $seed * 3.1)) * 0.10;

            // Don't exceed top
            if ($y + $plankH > $halfH) {
                $plankH = $halfH - $y;
            }
            if ($plankH < 0.03) {
                break;
            }

            // Per-plank random offsets
            $zOffset = self::pseudoRandom($plankIndex * 13.7 + $seed * 5.9) * $maxOffset;
            $tiltAngle = self::pseudoRandom($plankIndex * 19.3 + $seed * 11.1) * 0.015;
            $xShift = self::pseudoRandom($plankIndex * 23.1 + $seed * 7.7) * 0.008;

            $baseVtx = (int) (count($vertices) / 3);

            $y0 = $y;
            $y1 = $y + $plankH;

            $z0 = $halfD + $zOffset - $tiltAngle * $plankH * 0.5;
            $z1 = $halfD + $zOffset + $tiltAngle * $plankH * 0.5;

            $x0 = -$halfW + $xShift;
            $x1 = $halfW + $xShift;

            // Front face
            $vertices[] = (float) $x0; $vertices[] = (float) $y0; $vertices[] = (float) $z0;
            $normals[] = 0.0; $normals[] = 0.0; $normals[] = 1.0;
            $uvs[] = 0.0; $uvs[] = (float) (($y0 + $halfH) / ($halfH * 2));

            $vertices[] = (float) $x1; $vertices[] = (float) $y0; $vertices[] = (float) $z0;
            $normals[] = 0.0; $normals[] = 0.0; $normals[] = 1.0;
            $uvs[] = 1.0; $uvs[] = (float) (($y0 + $halfH) / ($halfH * 2));

            $vertices[] = (float) $x1; $vertices[] = (float) $y1; $vertices[] = (float) $z1;
            $normals[] = 0.0; $normals[] = 0.0; $normals[] = 1.0;
            $uvs[] = 1.0; $uvs[] = (float) (($y1 + $halfH) / ($halfH * 2));

            $vertices[] = (float) $x0; $vertices[] = (float) $y1; $vertices[] = (float) $z1;
            $normals[] = 0.0; $normals[] = 0.0; $normals[] = 1.0;
            $uvs[] = 0.0; $uvs[] = (float) (($y1 + $halfH) / ($halfH * 2));

            // Back face
            $zBack0 = -$halfD + $zOffset - $tiltAngle * $plankH * 0.5;
            $zBack1 = -$halfD + $zOffset + $tiltAngle * $plankH * 0.5;

            $vertices[] = (float) $x1; $vertices[] = (float) $y0; $vertices[] = (float) $zBack0;
            $normals[] = 0.0; $normals[] = 0.0; $normals[] = -1.0;
            $uvs[] = 0.0; $uvs[] = (float) (($y0 + $halfH) / ($halfH * 2));

            $vertices[] = (float) $x0; $vertices[] = (float) $y0; $vertices[] = (float) $zBack0;
            $normals[] = 0.0; $normals[] = 0.0; $normals[] = -1.0;
            $uvs[] = 1.0; $uvs[] = (float) (($y0 + $halfH) / ($halfH * 2));

            $vertices[] = (float) $x0; $vertices[] = (float) $y1; $vertices[] = (float) $zBack1;
            $normals[] = 0.0; $normals[] = 0.0; $normals[] = -1.0;
            $uvs[] = 1.0; $uvs[] = (float) (($y1 + $halfH) / ($halfH * 2));

            $vertices[] = (float) $x1; $vertices[] = (float) $y1; $vertices[] = (float) $zBack1;
            $normals[] = 0.0; $normals[] = 0.0; $normals[] = -1.0;
            $uvs[] = 0.0; $uvs[] = (float) (($y1 + $halfH) / ($halfH * 2));

            // Top face
            $vertices[] = (float) $x0; $vertices[] = (float) $y1; $vertices[] = (float) $z1;
            $normals[] = 0.0; $normals[] = 1.0; $normals[] = 0.0;
            $uvs[] = 0.0; $uvs[] = 1.0;

            $vertices[] = (float) $x1; $vertices[] = (float) $y1; $vertices[] = (float) $z1;
            $normals[] = 0.0; $normals[] = 1.0; $normals[] = 0.0;
            $uvs[] = 1.0; $uvs[] = 1.0;

            $vertices[] = (float) $x1; $vertices[] = (float) $y1; $vertices[] = (float) $zBack1;
            $normals[] = 0.0; $normals[] = 1.0; $normals[] = 0.0;
            $uvs[] = 1.0; $uvs[] = 0.0;

            $vertices[] = (float) $x0; $vertices[] = (float) $y1; $vertices[] = (float) $zBack1;
            $normals[] = 0.0; $normals[] = 1.0; $normals[] = 0.0;
            $uvs[] = 0.0; $uvs[] = 0.0;

            // Bottom face
            $vertices[] = (float) $x0; $vertices[] = (float) $y0; $vertices[] = (float) $zBack0;
            $normals[] = 0.0; $normals[] = -1.0; $normals[] = 0.0;
            $uvs[] = 0.0; $uvs[] = 0.0;

            $vertices[] = (float) $x1; $vertices[] = (float) $y0; $vertices[] = (float) $zBack0;
            $normals[] = 0.0; $normals[] = -1.0; $normals[] = 0.0;
            $uvs[] = 1.0; $uvs[] = 0.0;

            $vertices[] = (float) $x1; $vertices[] = (float) $y0; $vertices[] = (float) $z0;
            $normals[] = 0.0; $normals[] = -1.0; $normals[] = 0.0;
            $uvs[] = 1.0; $uvs[] = 1.0;

            $vertices[] = (float) $x0; $vertices[] = (float) $y0; $vertices[] = (float) $z0;
            $normals[] = 0.0; $normals[] = -1.0; $normals[] = 0.0;
            $uvs[] = 0.0; $uvs[] = 1.0;

            for ($face = 0; $face < 4; $face++) {
                $fv = $baseVtx + $face * 4;
                $indices[] = $fv;
                $indices[] = $fv + 1;
                $indices[] = $fv + 2;
                $indices[] = $fv;
                $indices[] = $fv + 2;
                $indices[] = $fv + 3;
            }

            $y += $plankH + $gapSize;
            $plankIndex++;
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }

    private static function pseudoRandom(float $seed): float
    {
        $n = sin($seed * 12.9898 + 78.233) * 43758.5453;
        return ($n - floor($n)) * 2.0 - 1.0;
    }
}
