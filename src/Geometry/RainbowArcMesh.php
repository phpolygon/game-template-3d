<?php

declare(strict_types=1);

namespace App\Geometry;

use PHPolygon\Geometry\MeshData;

/**
 * Generates a semicircular arc (half-torus) for rainbow rendering.
 * UV.y encodes the band position (0-1 across the rainbow spectrum).
 */
class RainbowArcMesh
{
    public static function generate(float $radius = 80.0, float $tubeRadius = 3.0, int $arcSegments = 48, int $tubeSegments = 8): MeshData
    {
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];

        // Semicircle from 0 to PI (left horizon to right horizon)
        for ($i = 0; $i <= $arcSegments; $i++) {
            $arcT = (float) $i / $arcSegments;
            $arcAngle = $arcT * M_PI; // 0 to PI

            // Center of tube at this arc position
            $cx = cos($arcAngle) * $radius;
            $cy = sin($arcAngle) * $radius;
            $cz = 0.0;

            // Tangent along arc
            $tx = -sin($arcAngle);
            $ty = cos($arcAngle);

            for ($j = 0; $j <= $tubeSegments; $j++) {
                $tubeT = (float) $j / $tubeSegments;
                $tubeAngle = $tubeT * M_PI * 2.0;

                // Normal in tube cross-section (radial direction from arc center)
                $nx = cos($arcAngle) * cos($tubeAngle);
                $ny = sin($arcAngle) * cos($tubeAngle);
                $nz = sin($tubeAngle);

                $x = $cx + $nx * $tubeRadius;
                $y = $cy + $ny * $tubeRadius;
                $z = $cz + $nz * $tubeRadius;

                $vertices[] = (float) $x;
                $vertices[] = (float) $y;
                $vertices[] = (float) $z;
                $normals[] = (float) $nx;
                $normals[] = (float) $ny;
                $normals[] = (float) $nz;
                // UV.x = arc position, UV.y = tube position (encodes rainbow band)
                $uvs[] = $arcT;
                $uvs[] = $tubeT;
            }
        }

        // Indices
        $ringVerts = $tubeSegments + 1;
        for ($i = 0; $i < $arcSegments; $i++) {
            for ($j = 0; $j < $tubeSegments; $j++) {
                $a = $i * $ringVerts + $j;
                $b = $a + $ringVerts;
                $indices[] = $a;
                $indices[] = $b;
                $indices[] = $a + 1;
                $indices[] = $b;
                $indices[] = $b + 1;
                $indices[] = $a + 1;
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
