<?php

declare(strict_types=1);

namespace App\System;

use PHPolygon\Component\PalmSway;
use PHPolygon\Component\Wind;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

class PalmSwaySystem extends AbstractSystem
{
    /** @var array<int, Vec3> Initial positions keyed by entity ID */
    private array $basePositions = [];

    /** @var array<int, Quaternion> Initial rotations keyed by entity ID */
    private array $baseRotations = [];

    private int $debugCounter = 0;

    public function update(World $world, float $dt): void
    {
        $this->debugCounter++;

        $windIntensity = 0.5;
        $windTime = 0.0;
        $windFound = false;
        foreach ($world->query(Wind::class) as $entity) {
            $wind = $world->getComponent($entity->id, Wind::class);
            $windIntensity = $wind->intensity;
            $windTime = $wind->time;
            $windFound = true;
            break;
        }

        $palmCount = 0;
        foreach ($world->query(Transform3D::class, PalmSway::class) as $entity) {
            $palmCount++;
            $transform = $world->getComponent($entity->id, Transform3D::class);
            $sway = $world->getComponent($entity->id, PalmSway::class);

            // Capture initial position AND rotation on first frame
            if (!isset($this->basePositions[$entity->id])) {
                $this->basePositions[$entity->id] = clone $transform->position;
                $this->baseRotations[$entity->id] = clone $transform->rotation;
            }

            $base = $this->basePositions[$entity->id];
            $baseRot = $this->baseRotations[$entity->id];
            $t = $windTime + $sway->phaseOffset;

            // Wind sway angle — layered sine waves for organic movement
            $swayAngle = sin($t * 1.5) * 0.12
                       + sin($t * 2.3) * 0.06
                       + sin($t * 0.7) * 0.04;

            // Storm gusts
            if ($windIntensity > 1.0) {
                $excess = $windIntensity - 1.0;
                $swayAngle += sin($t * 4.1 + $sway->phaseOffset * 3.0) * 0.08 * $excess;
                $swayAngle += sin($t * 6.7 + $sway->phaseOffset * 5.0) * 0.05 * $excess;
            }

            // Non-linear wind response
            $effectiveWind = $windIntensity <= 1.0
                ? $windIntensity
                : 1.0 + ($windIntensity - 1.0) * 1.8;
            $swayAngle *= $effectiveWind * $sway->swayStrength;

            // Pure rotation, no position drift: bulge, trunk top and fronds
            // rotate about their own centers but stay anchored. This prevents
            // visible gaps opening between the trunk top, the crown bulge and
            // the fronds when the wind kicks up — we don't have transform
            // parenting, so the only way to keep them coherent is to share
            // the same pivot-in-place motion.
            if ($sway->isTrunk) {
                $bendZ = Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), $swayAngle * 0.4);
                $bendX = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), $swayAngle * 0.15);
                $windRot = $bendZ->multiply($bendX);
                $transform->rotation = $windRot->multiply($baseRot);
            } else {
                $leafAngle = $swayAngle * 1.2;
                $tiltZ = Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), $leafAngle * 0.6);
                $tiltX = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), $leafAngle * 0.25);
                $windRot = $tiltZ->multiply($tiltX);
                $transform->rotation = $windRot->multiply($baseRot);
            }
            $transform->position = new Vec3($base->x, $base->y, $base->z);
        }

        if ($this->debugCounter % 120 === 1) {
            fprintf(STDERR, "[PalmSway] entities=%d wind=%.2f windTime=%.1f windFound=%s\n",
                $palmCount, $windIntensity, $windTime, $windFound ? 'yes' : 'NO');
        }
    }
}
