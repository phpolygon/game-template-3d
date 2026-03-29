<?php

declare(strict_types=1);

namespace App\Prefab;

use App\Component\Coconut;
use App\Geometry\PalmFrondMesh;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\PalmSway;
use PHPolygon\Component\Transform3D;
use PHPolygon\Geometry\MeshRegistry;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;

/**
 * Builds a realistic coconut palm tree.
 *
 * Reference: real coconut palms have:
 * - Slim, slightly curved trunk with fine ring scars
 * - Dense umbrella/firework-shaped crown of 25-30 fronds
 * - Fronds arc in a parabola: upright at base, curving out and hanging at tips
 * - 3 visible layers: young spears (up), main canopy (out), old fronds (hanging down)
 */
class PalmBuilder
{
    private Vec3 $position;
    private float $height = 5.5;
    private float $lean = 0.12;
    private int $frondCount = 30;
    private int $coconutCount = 0;
    private int $trunkSegments = 12;
    private int $index = 0;

    private function __construct(Vec3 $position)
    {
        $this->position = $position;
    }

    public static function at(Vec3 $position): self
    {
        return new self($position);
    }

    public function height(float $height): self { $this->height = $height; return $this; }
    public function lean(float $lean): self { $this->lean = $lean; return $this; }
    public function fronds(int $count): self { $this->frondCount = $count; return $this; }
    public function coconuts(int $count): self { $this->coconutCount = $count; return $this; }
    public function index(int $index): self { $this->index = $index; return $this; }

    public function build(SceneBuilder $builder): Vec3
    {
        self::ensureFrondMeshes();

        $prefix = "Palm_{$this->index}";

        $crownPos = $this->buildTrunk($builder, $prefix);
        $this->buildCrownBulge($builder, $prefix, $crownPos);
        $this->buildFronds($builder, $prefix, $crownPos);

        if ($this->coconutCount > 0) {
            $this->buildCoconuts($builder, $prefix, $crownPos);
        }

        return $crownPos;
    }

    // =========================================================================
    // TRUNK — slim, smooth spline with subtle curve
    // =========================================================================

    private function buildTrunk(SceneBuilder $builder, string $prefix): Vec3
    {
        // Organic curve: gentle lean + S-bend + per-tree variation
        $curveX = $this->lean * 0.7;
        $curveZ = sin($this->index * 2.3) * 0.06;
        $sBend = cos($this->index * 1.7) * 0.10;
        $wobX = sin($this->index * 4.1) * 0.02;
        $wobZ = cos($this->index * 3.7) * 0.015;

        $joints = [];
        for ($s = 0; $s <= $this->trunkSegments; $s++) {
            $t = (float) $s / $this->trunkSegments;
            $ox = $curveX * $t * $t
                + $sBend * sin($t * M_PI) * $t
                + $wobX * sin($t * M_PI * 2.0) * $t;
            $oz = $curveZ * $t * $t
                + $wobZ * sin($t * M_PI * 1.5) * $t;
            $joints[] = new Vec3(
                $this->position->x + $ox * $this->height,
                $this->position->y + $t * $this->height,
                $this->position->z + $oz * $this->height,
            );
        }

        for ($s = 0; $s < $this->trunkSegments; $s++) {
            $bot = $joints[$s];
            $top = $joints[$s + 1];
            $tMid = ((float) $s + 0.5) / $this->trunkSegments;

            $dx = $top->x - $bot->x;
            $dy = $top->y - $bot->y;
            $dz = $top->z - $bot->z;
            $segLen = sqrt($dx * $dx + $dy * $dy + $dz * $dz);
            if ($segLen < 0.001) continue;

            $rotation = self::rotationFromYUp($dx / $segLen, $dy / $segLen, $dz / $segLen);
            $center = new Vec3(($bot->x + $top->x) * 0.5, ($bot->y + $top->y) * 0.5, ($bot->z + $top->z) * 0.5);

            // Slim trunk: 0.16 base → 0.08 top, with root flare at very bottom
            $rootFlare = $s === 0 ? 0.06 : ($s === 1 ? 0.03 : 0.0);
            $radius = 0.16 - $tMid * 0.08 + $rootFlare;

            $matId = 'palm_trunk';
            if ($tMid > 0.7 && $this->index % 3 !== 0) {
                $matId = 'palm_trunk_dark';
            }

            // Generous overlap to eliminate segment gaps
            $entity = $builder->entity("{$prefix}_T_{$s}")
                ->with(new Transform3D(
                    position: $center,
                    rotation: $rotation,
                    scale: new Vec3($radius, $segLen * 0.56, $radius),
                ))
                ->with(new MeshRenderer(meshId: 'cylinder', materialId: $matId));

            // Trunk segments are static — sway is handled by crown bulge + fronds only
            // (individual segment PalmSway caused segments to drift apart)

            // Ring scars every 3rd segment
            if ($s % 3 === 2 && $s < $this->trunkSegments - 1) {
                $builder->entity("{$prefix}_R_{$s}")
                    ->with(new Transform3D(
                        position: $bot,
                        rotation: $rotation,
                        scale: new Vec3($radius + 0.015, 0.018, $radius + 0.015),
                    ))
                    ->with(new MeshRenderer(meshId: 'cylinder', materialId: 'palm_trunk_ring'));
            }
        }

        // Collider
        $midJ = $joints[(int) ($this->trunkSegments / 2)];
        $builder->entity("{$prefix}_Col")
            ->with(new Transform3D(
                position: new Vec3($midJ->x, $this->position->y + $this->height * 0.5, $midJ->z),
            ))
            ->with(new BoxCollider3D(size: new Vec3(0.7, $this->height, 0.7), isStatic: true));

        return $joints[$this->trunkSegments];
    }

    // =========================================================================
    // CROWN BULGE — compact knob where fronds emerge
    // =========================================================================

    private function buildCrownBulge(SceneBuilder $builder, string $prefix, Vec3 $crownPos): void
    {
        $builder->entity("{$prefix}_Bulge")
            ->with(new Transform3D(
                position: $crownPos,
                scale: new Vec3(0.24, 0.28, 0.24),
            ))
            ->with(new MeshRenderer(meshId: 'sphere', materialId: 'palm_trunk'))
            ->with(new PalmSway(swayStrength: 0.4, phaseOffset: $this->index * 1.3, isTrunk: true));
    }

    // =========================================================================
    // FRONDS — umbrella/firework shape in 3 layers
    // =========================================================================

    private function buildFronds(SceneBuilder $builder, string $prefix, Vec3 $crownPos): void
    {
        $baseLen = 2.0 + ($this->height - 4.5) * 0.2;
        $yawBase = $this->index * 0.55;
        $fIdx = 0;

        // --- LAYER 1: Young spears (top) — 15% of fronds, steep upward ---
        $n1 = max(3, (int) ($this->frondCount * 0.15));
        for ($f = 0; $f < $n1; $f++) {
            $yaw = ($f / $n1) * M_PI * 2.0 + $yawBase + 0.4;
            $yaw += sin($f * 3.1 + $this->index * 2.0) * 0.15;
            // 30-60° upward
            $elev = 0.35 + sin($f * 2.7 + $this->index) * 0.15;
            $len = $baseLen * (0.45 + sin($f * 2.1) * 0.08);

            $this->addFrond($builder, $prefix, $crownPos, $fIdx++,
                $yaw, $elev, $len, 'palm_leaves_light');
        }

        // --- LAYER 2: Main canopy (middle) — 55% of fronds, horizontal to slightly drooping ---
        $n2 = max(8, (int) ($this->frondCount * 0.55));
        for ($f = 0; $f < $n2; $f++) {
            $yaw = ($f / $n2) * M_PI * 2.0 + $yawBase;
            $yaw += sin($f * 2.7 + $this->index * 1.3) * 0.18;
            // -5° to -25° — mostly horizontal, slight droop
            $elev = -0.05 - sin($f * 1.9 + $this->index * 0.7) * 0.1 - ($f % 3) * 0.04;
            $len = $baseLen * (0.9 + sin($f * 3.1 + $this->index) * 0.1);

            $matId = ($this->index + $f) % 2 === 0 ? 'palm_leaves' : 'palm_leaves_light';
            if (($f + $this->index) % 9 === 0) $matId = 'palm_branch';

            $this->addFrond($builder, $prefix, $crownPos, $fIdx++,
                $yaw, $elev, $len, $matId);
        }

        // --- LAYER 3: Old hanging fronds (bottom) — 30% of fronds, drooping heavily ---
        $n3 = $this->frondCount - $n1 - $n2;
        for ($f = 0; $f < $n3; $f++) {
            $yaw = ($f / max(1, $n3)) * M_PI * 2.0 + $yawBase + 0.7;
            $yaw += sin($f * 1.9 + $this->index * 0.5) * 0.22;
            // -35° to -65° — hanging down
            $elev = -0.35 - sin($f * 2.3 + $this->index * 1.1) * 0.15 - 0.15;
            $len = $baseLen * (1.0 + sin($f * 4.1 + $this->index) * 0.08);

            // Old fronds are yellowing
            $matId = (($f + $this->index) % 2 === 0) ? 'palm_branch' : 'palm_leaves';

            $this->addFrond($builder, $prefix, $crownPos, $fIdx++,
                $yaw, $elev, $len, $matId);
        }
    }

    private function addFrond(
        SceneBuilder $builder, string $prefix, Vec3 $crownPos,
        int $fi, float $yaw, float $elev, float $len, string $matId,
    ): void {
        $rot = Quaternion::fromEuler($elev, $yaw, 0.0);
        $variant = ($this->index + $fi) % 4;

        // Offset frond origin outward from crown center
        $outward = $rot->rotateVec3(new Vec3(0.0, 0.12, 0.0));
        $origin = $crownPos->add($outward);

        $s = $len * 0.32;
        $builder->entity("{$prefix}_F_{$fi}")
            ->with(new Transform3D(position: $origin, rotation: $rot, scale: new Vec3($s, $s, $s)))
            ->with(new MeshRenderer(meshId: "palm_frond_{$variant}", materialId: $matId))
            ->with(new PalmSway(
                swayStrength: 0.4 + sin($fi * 2.1 + $this->index * 0.9) * 0.2,
                phaseOffset: $this->index * 1.3 + $fi * 0.35,
                isTrunk: false,
            ));
    }

    // =========================================================================
    // COCONUTS
    // =========================================================================

    private function buildCoconuts(SceneBuilder $builder, string $prefix, Vec3 $crownPos): void
    {
        for ($c = 0; $c < $this->coconutCount; $c++) {
            $angle = ($c / $this->coconutCount) * M_PI * 2.0 + $this->index * 1.5;
            $hang = 0.2 + sin($c * 2.3 + $this->index * 1.1) * 0.1;
            $threshold = 1.5 + sin($this->index * 7.3 + $c * 3.1) * 0.5;
            $delay = 2.0 + sin($this->index * 5.7 + $c * 2.3) * 1.5;

            $builder->entity("{$prefix}_Nut_{$c}")
                ->with(new Transform3D(
                    position: new Vec3(
                        $crownPos->x + cos($angle) * 0.2,
                        $crownPos->y - $hang,
                        $crownPos->z + sin($angle) * 0.2,
                    ),
                    scale: new Vec3(0.09, 0.11, 0.09),
                ))
                ->with(new MeshRenderer(meshId: 'sphere', materialId: 'coconut'))
                ->with(new Coconut(detachThreshold: $threshold, detachDelay: $delay));
        }
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private static function rotationFromYUp(float $dx, float $dy, float $dz): Quaternion
    {
        $dot = max(-1.0, min(1.0, $dy));
        $angle = acos($dot);
        $ax = $dz; $az = -$dx;
        $al = sqrt($ax * $ax + $az * $az);
        if ($al > 0.0001) {
            return Quaternion::fromAxisAngle(new Vec3($ax / $al, 0.0, $az / $al), $angle);
        }
        return Quaternion::identity();
    }

    private static function ensureFrondMeshes(): void
    {
        for ($v = 0; $v < 4; $v++) {
            $id = "palm_frond_{$v}";
            if (!MeshRegistry::has($id)) {
                MeshRegistry::register($id, PalmFrondMesh::generate(3.2, 26, $v));
            }
        }
    }
}
