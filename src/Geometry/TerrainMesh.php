<?php

declare(strict_types=1);

namespace App\Geometry;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Math\Vec3;

/**
 * Generates a subdivided XZ plane with height baked into vertices.
 * UV.x encodes the terrain zone (0.0=damp, 0.25=mid, 0.5=dry, 0.75=dune).
 * UV.y encodes a per-vertex variant hash (0..1) for color variation.
 * Normals are computed from central differences on the height function.
 */
class TerrainMesh
{
    /**
     * @param callable(float $x, float $z): array{y: float, material: string} $heightFn
     */
    public static function generate(
        float $xMin,
        float $xMax,
        float $zMin,
        float $zMax,
        float $step,
        callable $heightFn,
    ): MeshData {
        $cols = (int) ceil(($xMax - $xMin) / $step) + 1;
        $rows = (int) ceil(($zMax - $zMin) / $step) + 1;

        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];

        // Zone name → UV.x encoding
        $zoneMap = [
            'damp' => 0.0,
            'mid' => 0.25,
            'dry' => 0.5,
            'dune' => 0.75,
        ];

        // Generate vertices with baked height
        for ($iz = 0; $iz < $rows; $iz++) {
            for ($ix = 0; $ix < $cols; $ix++) {
                $x = (float) ($xMin + $ix * $step);
                $z = (float) ($zMin + $iz * $step);

                $info = $heightFn($x, $z);
                $y = (float) $info['y'];
                $materialId = $info['material'];

                $vertices[] = $x;
                $vertices[] = $y;
                $vertices[] = $z;

                // Compute normal via central differences
                $eps = $step * 0.5;
                $hL = (float) $heightFn($x - $eps, $z)['y'];
                $hR = (float) $heightFn($x + $eps, $z)['y'];
                $hB = (float) $heightFn($x, $z - $eps)['y'];
                $hF = (float) $heightFn($x, $z + $eps)['y'];

                $nx = ($hL - $hR) / (2.0 * $eps);
                $nz = ($hB - $hF) / (2.0 * $eps);
                $ny = 1.0;
                $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
                $normals[] = (float) ($nx / $len);
                $normals[] = (float) ($ny / $len);
                $normals[] = (float) ($nz / $len);

                // Encode zone into UV.x
                // Material format: "sand_{zone}_{variant}" e.g. "sand_dry_2"
                $parts = explode('_', $materialId);
                $zoneName = $parts[1] ?? 'dry';
                $variant = isset($parts[2]) ? (int) $parts[2] : 0;

                $uvs[] = (float) ($zoneMap[$zoneName] ?? 0.5);
                $uvs[] = (float) ($variant / 3.0); // 0..1 from variant 0..3
            }
        }

        // Generate indices
        for ($iz = 0; $iz < $rows - 1; $iz++) {
            for ($ix = 0; $ix < $cols - 1; $ix++) {
                $tl = $iz * $cols + $ix;
                $tr = $tl + 1;
                $bl = $tl + $cols;
                $br = $bl + 1;

                $indices[] = $tl;
                $indices[] = $tr;
                $indices[] = $br;
                $indices[] = $tl;
                $indices[] = $br;
                $indices[] = $bl;
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
