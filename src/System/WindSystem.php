<?php

declare(strict_types=1);

namespace App\System;

use App\Component\Wind;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

class WindSystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        foreach ($world->query(Wind::class) as $entity) {
            $wind = $world->getComponent($entity->id, Wind::class);
            $wind->time += $dt;

            $base = ($wind->maxIntensity + $wind->minIntensity) * 0.5;
            $range = ($wind->maxIntensity - $wind->minIntensity) * 0.5;

            $gust1 = sin($wind->time * $wind->gustFrequency * 2.0 * M_PI) * 0.5;
            $gust2 = sin($wind->time * $wind->gustFrequency * 1.37 * 2.0 * M_PI) * 0.3;
            $gust3 = sin($wind->time * $wind->gustFrequency * 0.41 * 2.0 * M_PI) * 0.2;

            $wind->intensity = $base + $range * ($gust1 + $gust2 + $gust3);
            $wind->intensity = max($wind->minIntensity, min($wind->maxIntensity, $wind->intensity));
        }
    }
}
