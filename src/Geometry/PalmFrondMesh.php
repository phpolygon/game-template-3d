<?php

declare(strict_types=1);

namespace App\Geometry;

use PHPolygon\Geometry\MeshData;

/**
 * Generates a realistic coconut palm frond mesh.
 *
 * Structure: central rachis (spine) with dense leaflet pairs branching off.
 * Shape: elegant parabolic arc — rises slightly from crown, then curves
 * gracefully outward and downward under its own weight.
 * Leaflets: long, narrow, dense — like teeth of a comb, hanging slightly down.
 */
class PalmFrondMesh
{
    public static function generate(float $length = 3.2, int $leafletPairs = 26, int $seed = 0): MeshData
    {
        $vertices = [];
        $normals = [];
        $uvs = [];
        $indices = [];
        $vertIdx = 0;

        // =====================================================================
        // RACHIS (central spine) — parabolic arc
        // =====================================================================
        $spineSegments = $leafletPairs + 2;
        $spineWidth = 0.02;

        // Pre-compute spine points for reuse by leaflets
        $spinePoints = [];
        for ($i = 0; $i <= $spineSegments; $i++) {
            $t = (float) $i / $spineSegments;

            // Parabolic arc: rises briefly at base, then arcs outward and down
            // Z = forward direction (frond extends in -Z)
            $z = -$t * $length;

            // Y = gentle arc: slight rise then soft droop (elevation handles the rest)
            $rise = (1.0 - $t) * $t * 0.25 * $length; // mild upward (peaks at t=0.5)
            $droop = $t * $t * $length * 0.3;          // gentle gravity
            $y = $rise - $droop;

            // Subtle S-curve for organic feel
            $sway = sin($t * M_PI * 1.3 + $seed * 0.7) * 0.04 * $t;
            $x = $sway;

            $spinePoints[] = ['x' => $x, 'y' => $y, 'z' => $z, 't' => $t];

            // Rachis narrows toward tip
            $w = $spineWidth * (1.0 - $t * 0.75);

            $vertices[] = (float) ($x - $w); $vertices[] = (float) $y; $vertices[] = (float) $z;
            $normals[] = 0.0; $normals[] = 1.0; $normals[] = 0.0;
            $uvs[] = 0.5 - $w; $uvs[] = $t;

            $vertices[] = (float) ($x + $w); $vertices[] = (float) $y; $vertices[] = (float) $z;
            $normals[] = 0.0; $normals[] = 1.0; $normals[] = 0.0;
            $uvs[] = 0.5 + $w; $uvs[] = $t;
        }

        for ($i = 0; $i < $spineSegments; $i++) {
            $base = $i * 2;
            $indices[] = $base;     $indices[] = $base + 1; $indices[] = $base + 3;
            $indices[] = $base;     $indices[] = $base + 3; $indices[] = $base + 2;
        }

        $vertIdx = ($spineSegments + 1) * 2;

        // =====================================================================
        // LEAFLETS — dense, long, narrow strips like a comb
        // =====================================================================
        for ($pair = 0; $pair < $leafletPairs; $pair++) {
            $t = ((float) $pair + 0.5) / $leafletPairs;

            // Skip first 5% — no leaflets at the base (bare petiole)
            if ($t < 0.05) continue;

            $spIdx = min(count($spinePoints) - 1, (int) (($pair + 0.5) / $leafletPairs * $spineSegments));
            $sp = $spinePoints[$spIdx];
            $spineX = $sp['x'];
            $spineY = $sp['y'];
            $spineZ = $sp['z'];

            // Leaflet length: longest at 25-50%, tapering at base and tip
            $envelope = sin(max(0.0, ($t - 0.05) / 0.95) * M_PI);
            $leafletLen = 0.9 * $envelope * (1.0 - $t * 0.12);

            // Leaflet width — wide enough to overlap with neighbors for dense canopy
            $leafletW = 0.06 * (1.0 - $t * 0.25);

            // Leaflets angle: nearly perpendicular to spine, slight forward sweep
            $sweepAngle = 0.15 * $t; // more forward sweep near tip

            // Leaflets droop: gentle hang, more toward tip
            $leafletDroop = -0.08 - $t * 0.2;

            // Slight random variation
            $angleVar = sin($pair * 3.7 + $seed * 2.1) * 0.08;
            $lenVar = sin($pair * 5.3 + $seed * 1.7) * 0.06;
            $leafletLen *= (1.0 + $lenVar);

            for ($side = -1; $side <= 1; $side += 2) {
                $sideAngle = $side * (M_PI * 0.38 + $angleVar) + $sweepAngle;

                // Leaflet base (at spine, slight width along spine axis)
                $bx = $spineX;
                $by = $spineY;
                $bz1 = $spineZ - $leafletW * 0.5;
                $bz2 = $spineZ + $leafletW * 0.5;

                // Leaflet tip (extends outward + droops)
                $tipX = $spineX + $side * $leafletLen * cos($sideAngle);
                $tipY = $spineY + $leafletDroop * $leafletLen;
                $tipZ = $spineZ - $leafletLen * sin(abs($sideAngle)) * 0.2;

                // Mid-point for a slightly wider leaflet shape
                $midF = 0.5;
                $midX = $spineX + $side * $leafletLen * $midF * cos($sideAngle);
                $midY = $spineY + $leafletDroop * $leafletLen * $midF * 0.6;
                $midZ = $spineZ + $leafletW * 0.3 * $side;

                // Normal — tilted outward
                $nx = (float) ($side * 0.2);
                $ny = 0.95;
                $nLen = sqrt($nx * $nx + $ny * $ny);
                $nx /= $nLen; $ny /= $nLen;

                // Quad: 2 triangles for each leaflet
                // Triangle 1: base → base2 → mid
                $vertices[] = (float) $bx; $vertices[] = (float) $by; $vertices[] = (float) $bz1;
                $normals[] = $nx; $normals[] = $ny; $normals[] = 0.0;
                $uvs[] = 0.5; $uvs[] = $t;

                $vertices[] = (float) $bx; $vertices[] = (float) $by; $vertices[] = (float) $bz2;
                $normals[] = $nx; $normals[] = $ny; $normals[] = 0.0;
                $uvs[] = 0.5; $uvs[] = $t;

                $vertices[] = (float) $midX; $vertices[] = (float) $midY; $vertices[] = (float) $midZ;
                $normals[] = $nx; $normals[] = $ny; $normals[] = 0.0;
                $uvs[] = (float) (0.5 + $side * 0.3); $uvs[] = $t + 0.01;

                $indices[] = $vertIdx; $indices[] = $vertIdx + 1; $indices[] = $vertIdx + 2;

                // Triangle 2: mid → base2 → tip  (forms the outer half)
                $vertices[] = (float) $midX; $vertices[] = (float) $midY; $vertices[] = (float) $midZ;
                $normals[] = $nx; $normals[] = $ny; $normals[] = 0.0;
                $uvs[] = (float) (0.5 + $side * 0.3); $uvs[] = $t + 0.01;

                $vertices[] = (float) $bx; $vertices[] = (float) $by; $vertices[] = (float) $bz2;
                $normals[] = $nx; $normals[] = $ny; $normals[] = 0.0;
                $uvs[] = 0.5; $uvs[] = $t;

                $vertices[] = (float) $tipX; $vertices[] = (float) $tipY; $vertices[] = (float) $tipZ;
                $normals[] = $nx; $normals[] = $ny; $normals[] = 0.0;
                $uvs[] = (float) (0.5 + $side * 0.5); $uvs[] = $t;

                $indices[] = $vertIdx + 3; $indices[] = $vertIdx + 4; $indices[] = $vertIdx + 5;

                $vertIdx += 6;
            }
        }

        return new MeshData($vertices, $normals, $uvs, $indices);
    }
}
