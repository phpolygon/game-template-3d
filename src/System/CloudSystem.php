<?php

declare(strict_types=1);

namespace App\System;

use App\Component\Atmosphere;
use App\Component\CloudDrift;
use App\Component\Wind;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Animates cloud entities based on wind, and controls visibility/height/darkening
 * from the Weather and Atmosphere systems.
 */
class CloudSystem extends AbstractSystem
{
    private float $time = 0.0;
    private float $lastStormIntensity = 0.0;
    private int $debugCounter = 0;

    public function update(World $world, float $dt): void
    {
        $this->time += $dt;
        $this->debugCounter++;

        // Read wind
        $windIntensity = 0.5;
        $windDirX = 1.0;
        $windDirZ = 0.0;
        foreach ($world->query(Wind::class) as $entity) {
            $wind = $world->getComponent($entity->id, Wind::class);
            $windIntensity = $wind->intensity;
            $windDirX = $wind->direction->x;
            $windDirZ = $wind->direction->z;
            break;
        }

        // Read weather for cloud coverage
        $cloudCoverage = 0.3;
        $stormIntensity = 0.0;
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);
            $cloudCoverage = $weather->cloudCoverage;
            $stormIntensity = $weather->stormIntensity;
            break;
        }

        // Read atmosphere for cloud base altitude and cloud types
        $cloudBaseAltitude = 45.0;
        $stratusFraction = 0.0;
        $cbFraction = 0.0;
        $cumulusFraction = 0.3;
        foreach ($world->query(Atmosphere::class) as $entity) {
            $atmo = $entity->get(Atmosphere::class);
            $cloudBaseAltitude = $atmo->cloudBaseAltitude;
            $stratusFraction = $atmo->stratusFraction;
            $cbFraction = $atmo->cumulonimbusFraction;
            $cumulusFraction = $atmo->cumulusFraction;
            break;
        }

        // Total puff count for visibility calculation
        $totalPuffs = 0;
        foreach ($world->query(Transform3D::class, CloudDrift::class) as $entity) {
            $totalPuffs++;
        }
        $visibleCount = (int) ($totalPuffs * min(1.0, $cloudCoverage * 1.3)); // Slight overscale for coverage

        // Update cloud materials for storm darkening (only when intensity changes noticeably)
        if (abs($stormIntensity - $this->lastStormIntensity) > 0.02) {
            $this->updateCloudMaterials($stormIntensity);
            $this->lastStormIntensity = $stormIntensity;
        }

        foreach ($world->query(Transform3D::class, CloudDrift::class) as $entity) {
            $transform = $world->getComponent($entity->id, Transform3D::class);
            $cloud = $world->getComponent($entity->id, CloudDrift::class);

            if ($cloud->baseY === 0.0) {
                $cloud->baseY = $transform->position->y;
                $cloud->baseScaleX = $transform->scale->x;
                $cloud->baseScaleY = $transform->scale->y;
                $cloud->baseScaleZ = $transform->scale->z;
            }

            // Assign cloud type based on atmosphere fractions
            $cloudFrac = (float) $cloud->cloudIndex / max(1, $totalPuffs);
            if ($cloudFrac < $cbFraction) {
                $cloud->cloudType = 2; // cumulonimbus
            } elseif ($cloudFrac < $cbFraction + $stratusFraction) {
                $cloud->cloudType = 1; // stratus
            } else {
                $cloud->cloudType = 0; // cumulus
            }

            // Apply type-based scale morphing
            $targetSX = $cloud->baseScaleX;
            $targetSY = $cloud->baseScaleY;
            $targetSZ = $cloud->baseScaleZ;
            if ($cloud->cloudType === 1) {
                // Stratus: flat and wide
                $targetSX *= 1.8;
                $targetSY *= 0.35;
                $targetSZ *= 1.8;
            } elseif ($cloud->cloudType === 2) {
                // Cumulonimbus: tall and dramatic
                $targetSX *= 1.3;
                $targetSY *= 2.2;
                $targetSZ *= 1.3;
            }
            $lerpRate = 0.5 * $dt;
            $transform->scale = new \PHPolygon\Math\Vec3(
                $transform->scale->x + ($targetSX - $transform->scale->x) * $lerpRate,
                $transform->scale->y + ($targetSY - $transform->scale->y) * $lerpRate,
                $transform->scale->z + ($targetSZ - $transform->scale->z) * $lerpRate,
            );

            // Visibility: hide clouds beyond visible count
            if ($cloud->cloudIndex >= $visibleCount) {
                $transform->position = new Vec3(
                    $transform->position->x,
                    -100.0, // below horizon
                    $transform->position->z,
                );
                continue;
            }

            // Wind-driven drift in BOTH X and Z (dynamic direction)
            $driftSpeed = $cloud->speed * (0.3 + $windIntensity * 0.7);
            $newX = $transform->position->x + $windDirX * $driftSpeed * $dt;
            $newZ = $transform->position->z + $windDirZ * $driftSpeed * $dt;

            // Wrap-around (now in both directions)
            if ($newX > $cloud->resetMaxX) $newX = $cloud->resetMinX;
            if ($newX < $cloud->resetMinX) $newX = $cloud->resetMaxX;
            if ($newZ > 20.0) $newZ = -120.0;  // Z wrap for depth
            if ($newZ < -120.0) $newZ = 20.0;

            // Cloud altitude: shift from original height by atmosphere delta, clamped ±8m
            $altitudeDelta = max(-8.0, min(8.0, $cloudBaseAltitude - 45.0));

            // Vertical bobbing (more turbulent in storms)
            $bobAmp = $cloud->bobAmplitude * (1.0 + $stormIntensity * 2.0);
            $bobY = sin($this->time * $cloud->bobFrequency + $cloud->phaseOffset) * $bobAmp;

            $transform->position = new Vec3($newX, $cloud->baseY + $altitudeDelta + $bobY, $newZ);
        }

        if ($this->debugCounter % 120 === 1) {
            fprintf(STDERR, "[Cloud] total=%d visible=%d coverage=%.2f storm=%.2f baseAlt=%.0f wind=(%.2f,%.2f)\n",
                $totalPuffs, $visibleCount, $cloudCoverage, $stormIntensity, $cloudBaseAltitude,
                $windDirX, $windDirZ);
        }
    }

    /**
     * Darken cloud materials during storms.
     */
    private function updateCloudMaterials(float $stormIntensity): void
    {
        $bright = 1.0 - $stormIntensity * 0.6;
        $alpha = min(0.95, 0.8 + $stormIntensity * 0.15);

        MaterialRegistry::register('cloud_bright', new Material(
            albedo: new Color(0.0, 0.0, 0.0),
            emission: new Color($bright * 0.67, $bright * 0.67, $bright * 0.67),
            alpha: min(0.95, 0.9 + $stormIntensity * 0.05),
        ));
        MaterialRegistry::register('cloud_top', new Material(
            albedo: new Color(0.0, 0.0, 0.0),
            emission: new Color($bright * 0.53, $bright * 0.53, $bright * 0.53),
            alpha: min(0.92, 0.85 + $stormIntensity * 0.07),
        ));
        MaterialRegistry::register('cloud_base', new Material(
            albedo: new Color(0.0, 0.0, 0.0),
            emission: new Color($bright * 0.33, $bright * 0.34, $bright * 0.40),
            alpha: $alpha,
        ));
    }
}
