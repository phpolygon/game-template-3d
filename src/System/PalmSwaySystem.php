<?php

declare(strict_types=1);

namespace App\System;

use PHPolygon\Component\PalmSway;
use App\Component\Wind;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

class PalmSwaySystem extends AbstractSystem
{
    /** @var array<int, Vec3> Initial positions keyed by entity ID */
    private array $basePositions = [];

    public function update(World $world, float $dt): void
    {
        $windIntensity = 0.5;
        $windTime = 0.0;
        foreach ($world->query(Wind::class) as $entity) {
            $wind = $world->getComponent($entity->id, Wind::class);
            $windIntensity = $wind->intensity;
            $windTime = $wind->time;
            break;
        }

        foreach ($world->query(Transform3D::class, PalmSway::class) as $entity) {
            $transform = $world->getComponent($entity->id, Transform3D::class);
            $sway = $world->getComponent($entity->id, PalmSway::class);

            // Capture initial position on first frame
            if (!isset($this->basePositions[$entity->id])) {
                $this->basePositions[$entity->id] = clone $transform->position;
            }

            $base = $this->basePositions[$entity->id];
            $t = $windTime + $sway->phaseOffset;

            // Wind sway angle — layered sine waves for organic movement
            $swayAngle = sin($t * 1.5) * 0.12
                       + sin($t * 2.3) * 0.06
                       + sin($t * 0.7) * 0.04;
            $swayAngle *= $windIntensity * $sway->swayStrength;

            if ($sway->isTrunk) {
                $halfHeight = $base->y;

                $bendZ = Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), $swayAngle * 0.5);
                $bendX = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), $swayAngle * 0.2);
                $rotation = $bendZ->multiply($bendX);

                $transform->rotation = $rotation;

                $upVec = new Vec3(0.0, $halfHeight, 0.0);
                $rotatedCenter = $rotation->rotateVec3($upVec);

                $transform->position = new Vec3(
                    $base->x + $rotatedCenter->x,
                    $rotatedCenter->y,
                    $base->z + $rotatedCenter->z,
                );
            } else {
                $leafSwayAngle = $swayAngle * 1.5;

                $swingX = sin($t * 2.0) * $windIntensity * $sway->swayStrength * 0.8;
                $swingZ = cos($t * 1.7) * $windIntensity * $sway->swayStrength * 0.25;

                $transform->position = new Vec3(
                    $base->x + $swingX,
                    $base->y + sin($t * 1.3) * 0.08 * $windIntensity,
                    $base->z + $swingZ,
                );

                $tiltZ = Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), $leafSwayAngle * 0.8);
                $tiltX = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), $leafSwayAngle * 0.3);
                $transform->rotation = $tiltZ->multiply($tiltX);
            }
        }
    }
}
