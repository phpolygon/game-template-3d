<?php

declare(strict_types=1);

namespace App\Geometry;

use PHPolygon\Geometry\MeshData;

/**
 * Upper half of a UV sphere — dome with a flat circular base at y = 0.
 * Vertex layout matches SphereMesh: position is on the unit hemisphere
 * scaled by $radius, normal is the (un-normalised already-normalised)
 * direction from origin, UV.x runs around the base, UV.y from base (0)
 * to apex (1).
 */
class HemisphereMesh
{
    public static function generate(float $radius, int $stacks, int $slices): MeshData
    {
        $vertices = [];
        $normals  = [];
        $uvs      = [];
        $indices  = [];

        for ($i = 0; $i <= $stacks; $i++) {
            $phi    = (M_PI / 2.0) * ($i / $stacks);
            $sinPhi = sin($phi);
            $cosPhi = cos($phi);

            for ($j = 0; $j <= $slices; $j++) {
                $theta    = 2.0 * M_PI * $j / $slices;
                $sinTheta = sin($theta);
                $cosTheta = cos($theta);

                $x = $cosTheta * $sinPhi;
                $y = $cosPhi;
                $z = $sinTheta * $sinPhi;

                $vertices[] = $radius * $x;
                $vertices[] = $radius * $y;
                $vertices[] = $radius * $z;
                $normals[]  = $x;
                $normals[]  = $y;
                $normals[]  = $z;
                $uvs[]      = (float)$j / $slices;
                $uvs[]      = (float)$i / $stacks;
            }
        }

        for ($i = 0; $i < $stacks; $i++) {
            for ($j = 0; $j < $slices; $j++) {
                $a = $i * ($slices + 1) + $j;
                $b = $a + ($slices + 1);

                $indices[] = $a;
                $indices[] = $b;
                $indices[] = $a + 1;
                $indices[] = $b;
                $indices[] = $b + 1;
                $indices[] = $a + 1;
            }
        }

        $center = count($vertices) / 3;
        $vertices[] = 0.0; $vertices[] = 0.0; $vertices[] = 0.0;
        $normals[]  = 0.0; $normals[]  = -1.0; $normals[]  = 0.0;
        $uvs[]      = 0.5; $uvs[]      = 0.5;

        $rimStart = $stacks * ($slices + 1);
        for ($j = 0; $j <= $slices; $j++) {
            $theta    = 2.0 * M_PI * $j / $slices;
            $vertices[] = $radius * cos($theta);
            $vertices[] = 0.0;
            $vertices[] = $radius * sin($theta);
            $normals[]  = 0.0;
            $normals[]  = -1.0;
            $normals[]  = 0.0;
            $uvs[]      = 0.5 + 0.5 * cos($theta);
            $uvs[]      = 0.5 + 0.5 * sin($theta);
        }

        $baseStart = $center + 1;
        for ($j = 0; $j < $slices; $j++) {
            $indices[] = $center;
            $indices[] = $baseStart + $j + 1;
            $indices[] = $baseStart + $j;
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
