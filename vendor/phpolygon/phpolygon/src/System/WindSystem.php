<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Atmosphere;
use PHPolygon\Component\Wind;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;

class WindSystem extends AbstractSystem
{
    private int $debugCounter = 0;

    public function update(World $world, float $dt): void
    {
        $this->debugCounter++;
        // Read atmosphere for pressure-driven wind direction
        $atmo = null;
        foreach ($world->query(Atmosphere::class) as $e) {
            $atmo = $e->get(Atmosphere::class);
            break;
        }

        foreach ($world->query(Wind::class) as $entity) {
            $wind = $world->getComponent($entity->id, Wind::class);
            $wind->time += $dt;

            // Dynamic wind direction from atmospheric pressure
            if ($atmo !== null) {
                // Smooth angle transition
                $angleDiff = fmod($atmo->windAngle - $wind->windAngle + 3 * M_PI, 2 * M_PI) - M_PI;
                $wind->windAngle += $angleDiff * 0.3 * $dt;
                $wind->direction = new Vec3(cos($wind->windAngle), 0.0, sin($wind->windAngle));

                // Pressure gradient influences base intensity
                $base = $wind->minIntensity + $atmo->pressureGradient * ($wind->maxIntensity - $wind->minIntensity);
            } else {
                $base = ($wind->maxIntensity + $wind->minIntensity) * 0.5;
            }

            $range = ($wind->maxIntensity - $wind->minIntensity) * 0.5;

            // Gust harmonics (unchanged — organic variation on top of base)
            $gust1 = sin($wind->time * $wind->gustFrequency * 2.0 * M_PI) * 0.5;
            $gust2 = sin($wind->time * $wind->gustFrequency * 1.37 * 2.0 * M_PI) * 0.3;
            $gust3 = sin($wind->time * $wind->gustFrequency * 0.41 * 2.0 * M_PI) * 0.2;

            $wind->intensity = $base + $range * ($gust1 + $gust2 + $gust3);
            $wind->intensity = max($wind->minIntensity, min($wind->maxIntensity, $wind->intensity));

            if ($this->debugCounter % 120 === 1) {
                fprintf(STDERR, "[Wind] intensity=%.2f min=%.2f max=%.2f angle=%.2f dir=(%.2f,%.2f) atmo=%s\n",
                    $wind->intensity, $wind->minIntensity, $wind->maxIntensity, $wind->windAngle,
                    $wind->direction->x, $wind->direction->z, $atmo !== null ? 'yes' : 'NO');
            }
        }
    }
}
