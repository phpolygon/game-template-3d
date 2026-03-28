<?php

declare(strict_types=1);

namespace App\System;

use App\Component\WaveStrip;
use App\Component\Wind;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;

class WaveSystem extends AbstractSystem
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

        foreach ($world->query(Transform3D::class, WaveStrip::class) as $entity) {
            $transform = $world->getComponent($entity->id, Transform3D::class);
            $wave = $world->getComponent($entity->id, WaveStrip::class);

            $t = $this->time * $wave->frequency + $wave->phaseOffset;
            $amplitude = $wave->amplitude * (0.5 + $windIntensity * 0.5);

            $y = $wave->baseY
                + sin($t) * $amplitude
                + sin($t * 2.3 + 1.7) * $amplitude * 0.3
                + sin($t * 0.7 + 3.1) * $amplitude * 0.15;

            if ($wave->isFoam) {
                $mainWaveY = $wave->baseY + sin($t) * $amplitude;
                $foamVisible = $mainWaveY > $wave->baseY + $amplitude * 0.4;
                $foamY = $foamVisible ? $y + 0.05 : -10.0;
                $transform->position = new Vec3($transform->position->x, $foamY, $transform->position->z);
            } else {
                $transform->position = new Vec3($transform->position->x, $y, $transform->position->z);
            }

            $tiltAngle = cos($t) * $amplitude * 0.15;
            $transform->scale = new Vec3(
                $transform->scale->x,
                1.0 + sin($t * 1.1) * 0.1 * $windIntensity,
                $transform->scale->z,
            );
        }
    }
}
