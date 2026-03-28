<?php

declare(strict_types=1);

namespace App\Geometry;

use PHPolygon\Geometry\MeshData;

/**
 * Generates a feather-shaped palm frond mesh.
 * Central spine with thin leaflets branching off alternately.
 * Droops and narrows toward the tip like a real coconut palm frond.
 */
class PalmFrondMesh
{
    /**
     * @param float $length   Total frond length
     * @param int $leafletPairs Number of leaflet pairs along the spine
     * @param int $seed       For variation between fronds
     */
    public static function generate(float $length = 2.5, int $leafletPairs = 12, int $seed = 0): MeshData
    {
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        $vertIdx = 0;

        // Spine segments — the central rachis
        $spineSegments = $leafletPairs + 1;
        $spineWidth = 0.015;

        // Generate spine vertices (flat strip along Z axis)
        for ($i = 0; $i <= $spineSegments; $i++) {
            $t = (float) $i / $spineSegments;
            $z = -$t * $length;

            // Droop curve — frond hangs down more toward tip
            $droop = $t * $t * $length * 0.6;
            $y = -$droop;

            // Slight S-curve
            $sway = sin($t * M_PI * 1.5 + $seed * 0.7) * 0.08 * $t;
            $x = $sway;

            // Spine narrows toward tip
            $w = $spineWidth * (1.0 - $t * 0.6);

            // Left vertex
            $vertices[] = (float) ($x - $w);
            $vertices[] = (float) $y;
            $vertices[] = (float) $z;
            $normals[] = 0.0; $normals[] = 1.0; $normals[] = 0.0;
            $uvs[] = 0.5 - $w; $uvs[] = (float) $t;

            // Right vertex
            $vertices[] = (float) ($x + $w);
            $vertices[] = (float) $y;
            $vertices[] = (float) $z;
            $normals[] = 0.0; $normals[] = 1.0; $normals[] = 0.0;
            $uvs[] = 0.5 + $w; $uvs[] = (float) $t;
        }

        // Spine indices
        for ($i = 0; $i < $spineSegments; $i++) {
            $base = $i * 2;
            $indices[] = $base;
            $indices[] = $base + 1;
            $indices[] = $base + 3;
            $indices[] = $base;
            $indices[] = $base + 3;
            $indices[] = $base + 2;
        }

        $vertIdx = ($spineSegments + 1) * 2;

        // Generate leaflets — thin triangles branching off the spine
        for ($pair = 0; $pair < $leafletPairs; $pair++) {
            $t = ((float) $pair + 0.5) / $leafletPairs;

            // Position along spine
            $spineZ = -$t * $length;
            $spineDroop = $t * $t * $length * 0.4;
            $spineY = -$spineDroop;
            $spineSway = sin($t * M_PI * 1.5 + $seed * 0.7) * 0.08 * $t;
            $spineX = $spineSway;

            // Leaflet properties — longer in the middle, shorter at base and tip
            $leafletLength = 0.55 * sin($t * M_PI) * (1.0 - $t * 0.3);
            $leafletWidth = 0.12 * (1.0 - $t * 0.4);

            // Droop on leaflets — hang down naturally
            $leafDroop = -0.1 - $t * 0.25;

            // Pseudo-random angle variation
            $angleVar = sin($pair * 3.7 + $seed * 2.1) * 0.15;

            // Both sides — left and right leaflets
            for ($side = -1; $side <= 1; $side += 2) {
                $angle = ($side * 0.7 + $angleVar); // ~40° off spine

                // Leaflet: triangle (base at spine, tip extends outward)
                // Base left
                $bx = $spineX;
                $by = $spineY;
                $bz = $spineZ - $leafletWidth * 0.5;

                // Base right
                $b2z = $spineZ + $leafletWidth * 0.5;

                // Tip
                $tipX = $spineX + $side * $leafletLength * cos($angle);
                $tipY = $spineY + $leafDroop * $leafletLength;
                $tipZ = $spineZ - $leafletLength * sin(abs($angle)) * 0.3;

                // Normal — roughly upward, slightly tilted outward
                $nx = (float) ($side * 0.2);
                $ny = 0.95;
                $nz = 0.0;
                $nLen = sqrt($nx * $nx + $ny * $ny);
                $nx /= $nLen;
                $ny /= $nLen;

                // Triangle: 3 vertices
                $vertices[] = (float) $bx;
                $vertices[] = (float) $by;
                $vertices[] = (float) $bz;
                $normals[] = (float) $nx; $normals[] = (float) $ny; $normals[] = 0.0;
                $uvs[] = 0.5; $uvs[] = (float) $t;

                $vertices[] = (float) $bx;
                $vertices[] = (float) $by;
                $vertices[] = (float) $b2z;
                $normals[] = (float) $nx; $normals[] = (float) $ny; $normals[] = 0.0;
                $uvs[] = 0.5; $uvs[] = (float) $t;

                $vertices[] = (float) $tipX;
                $vertices[] = (float) $tipY;
                $vertices[] = (float) $tipZ;
                $normals[] = (float) $nx; $normals[] = (float) $ny; $normals[] = 0.0;
                $uvs[] = (float) (0.5 + $side * 0.5); $uvs[] = (float) $t;

                // Two triangles for a wider leaflet (quad-like)
                $indices[] = $vertIdx;
                $indices[] = $vertIdx + 1;
                $indices[] = $vertIdx + 2;

                // Second triangle — wider base
                $mid2X = $spineX + $side * $leafletLength * 0.6 * cos($angle);
                $mid2Y = $spineY + $leafDroop * $leafletLength * 0.6;
                $mid2Z = $spineZ;

                $vertices[] = (float) $mid2X;
                $vertices[] = (float) $mid2Y;
                $vertices[] = (float) $mid2Z;
                $normals[] = (float) $nx; $normals[] = (float) $ny; $normals[] = 0.0;
                $uvs[] = (float) (0.5 + $side * 0.3); $uvs[] = (float) ($t + 0.02);

                $indices[] = $vertIdx + 1;
                $indices[] = $vertIdx + 2;
                $indices[] = $vertIdx + 3;

                $vertIdx += 4;
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
