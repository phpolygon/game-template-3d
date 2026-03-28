<?php

declare(strict_types=1);

namespace App\System;

use App\Component\PalmSway;
use App\Component\Wind;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

class PalmSwaySystem extends AbstractSystem
{
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

            if ($sway->baseY === 0.0 && $transform->position->y > 0.1) {
                $sway->baseX = $transform->position->x;
                $sway->baseY = $transform->position->y;
                $sway->baseZ = $transform->position->z;
            }

            $t = $windTime + $sway->phaseOffset;
            $swayAngle = sin($t * 1.5) * 0.15 + sin($t * 2.3) * 0.08 + sin($t * 0.7) * 0.05;
            $swayAngle *= $windIntensity * $sway->swayStrength;

            if ($sway->isTrunk) {
                $bendX = Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), $swayAngle * 0.4);
                $bendZ = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), $swayAngle * 0.15);
                $transform->rotation = $bendX->multiply($bendZ);
            } else {
                $swingX = sin($t * 2.0) * $windIntensity * $sway->swayStrength * 0.6;
                $swingZ = cos($t * 1.7) * $windIntensity * $sway->swayStrength * 0.2;
                $transform->position = new Vec3(
                    $sway->baseX + $swingX,
                    $sway->baseY + sin($t * 1.3) * 0.1 * $windIntensity,
                    $sway->baseZ + $swingZ,
                );

                $tiltX = Quaternion::fromAxisAngle(new Vec3(0.0, 0.0, 1.0), $swayAngle * 0.7);
                $tiltZ = Quaternion::fromAxisAngle(new Vec3(1.0, 0.0, 0.0), $swayAngle * 0.3);
                $transform->rotation = $tiltX->multiply($tiltZ);
            }
        }
    }
}
