<?php

declare(strict_types=1);

namespace App\System;

use App\Component\WaveStrip;
use App\Component\Wind;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Weather;
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

        // Read weather for storm + rain effects on waves
        $stormIntensity = 0.0;
        $rainIntensity = 0.0;
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            $stormIntensity = $weather->stormIntensity;
            $rainIntensity = $weather->rainIntensity;
            break;
        }

        // Storm dramatically amplifies waves (up to 3.5x)
        $stormAmp = 1.0 + $stormIntensity * 2.5;
        // Storm increases wave frequency
        $stormFreq = 1.0 + $stormIntensity * 0.5;

        foreach ($world->query(Transform3D::class, WaveStrip::class) as $entity) {
            $transform = $world->getComponent($entity->id, Transform3D::class);
            $wave = $world->getComponent($entity->id, WaveStrip::class);

            $t = $this->time * $wave->frequency * $stormFreq + $wave->phaseOffset;
            $amplitude = $wave->amplitude * (0.5 + $windIntensity * 0.8) * $stormAmp;

            $y = $wave->baseY
                + sin($t) * $amplitude
                + sin($t * 2.3 + 1.7) * $amplitude * 0.3
                + sin($t * 0.7 + 3.1) * $amplitude * 0.15;

            // Rain-driven chop: high-frequency ripples when raining
            if ($rainIntensity > 0.1) {
                $chop = sin($this->time * 8.0 + $wave->phaseOffset * 3.0) * $amplitude * $rainIntensity * 0.15
                      + sin($this->time * 12.0 + $wave->phaseOffset * 5.0) * $amplitude * $rainIntensity * 0.08;
                $y += $chop;
            }

            if ($wave->isFoam) {
                $mainWaveY = $wave->baseY + sin($t) * $amplitude;
                $foamVisible = $mainWaveY > $wave->baseY + $amplitude * 0.4;
                $foamY = $foamVisible ? $y + 0.05 : -10.0;
                $transform->position = new Vec3($transform->position->x, $foamY, $transform->position->z);
            } else {
                $transform->position = new Vec3($transform->position->x, $y, $transform->position->z);
            }

            $transform->scale = new Vec3(
                $transform->scale->x,
                1.0 + sin($t * 1.1) * 0.1 * $windIntensity * $stormAmp,
                $transform->scale->z,
            );
        }
    }
}
