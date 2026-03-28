<?php

declare(strict_types=1);

namespace App\Geometry;

use PHPolygon\Geometry\MeshData;
use PHPolygon\Geometry\SphereMesh;

/**
 * Generates a deformed sphere that looks like a natural rock.
 * Noise displacement on vertices creates irregular, organic shapes.
 * Each seed produces a unique rock silhouette.
 */
class RockMesh
{
    public static function generate(float $radius = 1.0, int $seed = 0, int $stacks = 10, int $slices = 14): MeshData
    {
        // Start with a sphere
        $sphere = SphereMesh::generate($radius, $stacks, $slices);

        $vertices = $sphere->vertices;
        $normals = $sphere->normals;

        // Deform each vertex with layered noise
        $vertexCount = (int) (count($vertices) / 3);
        for ($i = 0; $i < $vertexCount; $i++) {
            $vx = $vertices[$i * 3];
            $vy = $vertices[$i * 3 + 1];
            $vz = $vertices[$i * 3 + 2];

            // Normalize to get direction from center
            $len = sqrt($vx * $vx + $vy * $vy + $vz * $vz);
            if ($len < 0.001) {
                continue;
            }
            $nx = $vx / $len;
            $ny = $vy / $len;
            $nz = $vz / $len;

            // Multi-octave displacement
            $d = 0.0;
            // Large bumps — defines overall rock shape
            $d += self::noise3D($nx * 2.0 + $seed * 7.3, $ny * 2.0 + $seed * 3.1, $nz * 2.0 + $seed * 11.7) * 0.35;
            // Medium detail — secondary bumps
            $d += self::noise3D($nx * 5.0 + $seed * 13.7, $ny * 5.0 + $seed * 9.3, $nz * 5.0 + $seed * 5.1) * 0.15;
            // Fine cracks/pits
            $d += self::noise3D($nx * 12.0 + $seed * 2.9, $ny * 12.0 + $seed * 17.3, $nz * 12.0 + $seed * 7.7) * 0.06;

            // Flatten bottom slightly — rocks sit on ground
            if ($ny < -0.3) {
                $d -= ($ny + 0.3) * 0.5; // push bottom inward
            }

            $newLen = $len * (1.0 + $d);
            $vertices[$i * 3] = (float) ($nx * $newLen);
            $vertices[$i * 3 + 1] = (float) ($ny * $newLen);
            $vertices[$i * 3 + 2] = (float) ($nz * $newLen);
        }

        // Recompute normals from deformed geometry
        $normals = self::computeNormals($vertices, $sphere->indices);

        return new MeshData($vertices, $normals, $sphere->uvs, $sphere->indices);
    }

    /**
     * Simple 3D noise function (value noise with smooth interpolation).
     */
    private static function noise3D(float $x, float $y, float $z): float
    {
        $ix = floor($x);
        $iy = floor($y);
        $iz = floor($z);
        $fx = $x - floor($x);
        $fy = $y - floor($y);
        $fz = $z - floor($z);

        // Smoothstep
        $fx = $fx * $fx * (3.0 - 2.0 * $fx);
        $fy = $fy * $fy * (3.0 - 2.0 * $fy);
        $fz = $fz * $fz * (3.0 - 2.0 * $fz);

        // 8 corner hashes
        $n000 = self::hash3($ix, $iy, $iz);
        $n100 = self::hash3($ix + 1, $iy, $iz);
        $n010 = self::hash3($ix, $iy + 1, $iz);
        $n110 = self::hash3($ix + 1, $iy + 1, $iz);
        $n001 = self::hash3($ix, $iy, $iz + 1);
        $n101 = self::hash3($ix + 1, $iy, $iz + 1);
        $n011 = self::hash3($ix, $iy + 1, $iz + 1);
        $n111 = self::hash3($ix + 1, $iy + 1, $iz + 1);

        // Trilinear interpolation
        $x00 = $n000 + ($n100 - $n000) * $fx;
        $x10 = $n010 + ($n110 - $n010) * $fx;
        $x01 = $n001 + ($n101 - $n001) * $fx;
        $x11 = $n011 + ($n111 - $n011) * $fx;

        $y0 = $x00 + ($x10 - $x00) * $fy;
        $y1 = $x01 + ($x11 - $x01) * $fy;

        return ($y0 + ($y1 - $y0) * $fz) * 2.0 - 1.0; // range [-1, 1]
    }

    private static function hash3(float $x, float $y, float $z): float
    {
        $n = sin($x * 127.1 + $y * 311.7 + $z * 74.7) * 43758.5453;
        return $n - floor($n);
    }

    /**
     * Recompute smooth vertex normals from triangle faces.
     * @param float[] $vertices
     * @param int[] $indices
     * @return float[]
     */
    public static function computeNormalsPublic(array $vertices, array $indices): array
    {
        return self::computeNormals($vertices, $indices);
    }

    /**
     * @param float[] $vertices
     * @param int[] $indices
     * @return float[]
     */
    private static function computeNormals(array $vertices, array $indices): array
    {
        $vertexCount = (int) (count($vertices) / 3);
        $normals = array_fill(0, $vertexCount * 3, 0.0);

        // Accumulate face normals per vertex
        $triCount = (int) (count($indices) / 3);
        for ($t = 0; $t < $triCount; $t++) {
            $i0 = $indices[$t * 3];
            $i1 = $indices[$t * 3 + 1];
            $i2 = $indices[$t * 3 + 2];

            $ax = $vertices[$i1 * 3] - $vertices[$i0 * 3];
            $ay = $vertices[$i1 * 3 + 1] - $vertices[$i0 * 3 + 1];
            $az = $vertices[$i1 * 3 + 2] - $vertices[$i0 * 3 + 2];

            $bx = $vertices[$i2 * 3] - $vertices[$i0 * 3];
            $by = $vertices[$i2 * 3 + 1] - $vertices[$i0 * 3 + 1];
            $bz = $vertices[$i2 * 3 + 2] - $vertices[$i0 * 3 + 2];

            // Cross product
            $nx = $ay * $bz - $az * $by;
            $ny = $az * $bx - $ax * $bz;
            $nz = $ax * $by - $ay * $bx;

            foreach ([$i0, $i1, $i2] as $vi) {
                $normals[$vi * 3] += $nx;
                $normals[$vi * 3 + 1] += $ny;
                $normals[$vi * 3 + 2] += $nz;
            }
        }

        // Normalize
        for ($i = 0; $i < $vertexCount; $i++) {
            $nx = $normals[$i * 3];
            $ny = $normals[$i * 3 + 1];
            $nz = $normals[$i * 3 + 2];
            $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
            if ($len > 0.0001) {
                $normals[$i * 3] = (float) ($nx / $len);
                $normals[$i * 3 + 1] = (float) ($ny / $len);
                $normals[$i * 3 + 2] = (float) ($nz / $len);
            }
        }

        return $normals;
    }
}
