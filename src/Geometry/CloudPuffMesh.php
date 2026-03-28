<?php

declare(strict_types=1);

namespace App\Geometry;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\SphereMesh;

/**
 * Generates a deformed, flattened sphere that looks like a cloud puff.
 * Naturally lumpy with a flat bottom (anvil shape).
 */
class CloudPuffMesh
{
    public static function generate(int $seed = 0): MeshData
    {
        $sphere = SphereMesh::generate(1.0, 8, 12);

        $vertices = $sphere->vertices;
        $vertexCount = (int) (count($vertices) / 3);

        for ($i = 0; $i < $vertexCount; $i++) {
            $vx = $vertices[$i * 3];
            $vy = $vertices[$i * 3 + 1];
            $vz = $vertices[$i * 3 + 2];

            $len = sqrt($vx * $vx + $vy * $vy + $vz * $vz);
            if ($len < 0.001) continue;

            $nx = $vx / $len;
            $ny = $vy / $len;
            $nz = $vz / $len;

            // Large lumps
            $d = self::noise3D($nx * 2.0 + $seed * 5.3, $ny * 2.0 + $seed * 11.7, $nz * 2.0 + $seed * 3.1) * 0.3;
            // Medium detail
            $d += self::noise3D($nx * 4.5 + $seed * 7.1, $ny * 4.5 + $seed * 2.3, $nz * 4.5 + $seed * 9.9) * 0.12;

            // Flatten bottom — clouds are flat underneath
            if ($ny < -0.2) {
                $d -= ($ny + 0.2) * 1.5;
            }

            // Flatten vertically — clouds are wider than tall
            $newLen = $len * (1.0 + $d);
            $vertices[$i * 3] = (float) ($nx * $newLen);
            $vertices[$i * 3 + 1] = (float) ($ny * $newLen * 0.4); // squash Y
            $vertices[$i * 3 + 2] = (float) ($nz * $newLen);
        }

        $normals = RockMesh::computeNormalsPublic($vertices, $sphere->indices);

        return new MeshData($vertices, $normals, $sphere->uvs, $sphere->indices);
    }

    private static function noise3D(float $x, float $y, float $z): float
    {
        // Use the same sin-based hash as RockMesh to avoid integer overflow
        $ix = floor($x); $iy = floor($y); $iz = floor($z);
        $fx = $x - floor($x); $fy = $y - floor($y); $fz = $z - floor($z);
        $fx = $fx * $fx * (3.0 - 2.0 * $fx);
        $fy = $fy * $fy * (3.0 - 2.0 * $fy);
        $fz = $fz * $fz * (3.0 - 2.0 * $fz);

        $h = fn(float $a, float $b, float $c) => self::hash3($a, $b, $c);

        $n000 = $h($ix, $iy, $iz);       $n100 = $h($ix+1, $iy, $iz);
        $n010 = $h($ix, $iy+1, $iz);     $n110 = $h($ix+1, $iy+1, $iz);
        $n001 = $h($ix, $iy, $iz+1);     $n101 = $h($ix+1, $iy, $iz+1);
        $n011 = $h($ix, $iy+1, $iz+1);   $n111 = $h($ix+1, $iy+1, $iz+1);

        $x00 = $n000 + ($n100 - $n000) * $fx; $x10 = $n010 + ($n110 - $n010) * $fx;
        $x01 = $n001 + ($n101 - $n001) * $fx; $x11 = $n011 + ($n111 - $n011) * $fx;
        $y0 = $x00 + ($x10 - $x00) * $fy;
        $y1 = $x01 + ($x11 - $x01) * $fy;
        return ($y0 + ($y1 - $y0) * $fz) * 2.0 - 1.0;
    }

    private static function hash3(float $x, float $y, float $z): float
    {
        $n = sin($x * 127.1 + $y * 311.7 + $z * 74.7) * 43758.5453;
        return $n - floor($n);
    }
}
