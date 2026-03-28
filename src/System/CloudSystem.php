<?php

declare(strict_types=1);

namespace App\System;

use App\Component\CloudDrift;
use App\Component\Wind;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;

class CloudSystem extends AbstractSystem
{
    private float $time = 0.0;

    public function update(World $world, float $dt): void
    {
        $this->time += $dt;

        $windIntensity = 0.5;
        foreach ($world->query(Wind::class) as $entity) {
            $wind = $world->getComponent($entity->id, Wind::class);
            $windIntensity = $wind->intensity;
            break;
        }

        foreach ($world->query(Transform3D::class, CloudDrift::class) as $entity) {
            $transform = $world->getComponent($entity->id, Transform3D::class);
            $cloud = $world->getComponent($entity->id, CloudDrift::class);

            if ($cloud->baseY === 0.0) {
                $cloud->baseY = $transform->position->y;
            }

            $driftSpeed = $cloud->speed * (0.3 + $windIntensity * 0.7);
            $newX = $transform->position->x + $driftSpeed * $dt;

            if ($newX > $cloud->resetMaxX) {
                $newX = $cloud->resetMinX;
            }

            $bobY = sin($this->time * $cloud->bobFrequency + $cloud->phaseOffset) * $cloud->bobAmplitude;

            $transform->position = new Vec3(
                $newX,
                $cloud->baseY + $bobY,
                $transform->position->z,
            );
        }
    }
}
