<?php

declare(strict_types=1);

namespace App\System;

use App\Component\Atmosphere;
use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\Season;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\System\SeasonSystem;

/**
 * Replaces the engine's EnvironmentalSystem with physics-based atmospheric simulation.
 * Delegates to SeasonSystem (engine) + AtmosphereSystem (game) instead of WeatherSystem (engine).
 * Provides the same cross-system coupling (Weather→Wind, Weather→DayNight).
 */
class AtmosphericEnvironmentalSystem extends AbstractSystem
{
    private SeasonSystem $seasonSystem;
    private AtmosphereSystem $atmosphereSystem;
    private int $debugCounter = 0;

    public function __construct()
    {
        $this->seasonSystem = new SeasonSystem();
        $this->atmosphereSystem = new AtmosphereSystem();
    }

    public function update(World $world, float $dt): void
    {
        $this->debugCounter++;

        // 1. Advance seasons (vegetation, base temperature/humidity)
        $this->seasonSystem->update($world, $dt);

        // 2. Run atmospheric physics (pressure, dew point, convection → writes Weather)
        $this->atmosphereSystem->update($world, $dt);

        // 3. Cross-system coupling
        $this->coupleToWind($world);
        $this->coupleToDayNight($world);

        if ($this->debugCounter % 120 === 1) {
            $dayNight = null;
            foreach ($world->query(DayNightCycle::class) as $e) { $dayNight = $e->get(DayNightCycle::class); break; }
            if ($dayNight !== null) {
                fprintf(STDERR, "[EnvSys] fogNearOvr=%.1f fogFarOvr=%.1f cloudDark=%.2f\n",
                    $dayNight->fogNearOverride, $dayNight->fogFarOverride, $dayNight->cloudDarkening);
            }
        }
    }

    private function coupleToWind(World $world): void
    {
        $weather = null;
        $atmo = null;
        foreach ($world->query(Weather::class) as $e) { $weather = $e->get(Weather::class); break; }
        foreach ($world->query(Atmosphere::class) as $e) { $atmo = $e->get(Atmosphere::class); break; }
        if ($weather === null) return;

        foreach ($world->query(\App\Component\Wind::class) as $entity) {
            $wind = $entity->get(\App\Component\Wind::class);

            // Pressure gradient drives wind base intensity
            // Storm dramatically increases wind (up to 3.5x normal for hurricane-force palm sway)
            if ($atmo !== null) {
                $wind->maxIntensity = 0.8 + $atmo->pressureGradient * 0.7 + $weather->stormIntensity * 2.0;
                $wind->minIntensity = 0.1 + $atmo->pressureGradient * 0.2 + $weather->stormIntensity * 0.8 + $weather->rainIntensity * 0.1;
            } else {
                $wind->maxIntensity = 1.0 + $weather->stormIntensity * 2.0;
                $wind->minIntensity = 0.15 + $weather->stormIntensity * 0.5 + $weather->rainIntensity * 0.15;
            }
            break;
        }
    }

    private function coupleToDayNight(World $world): void
    {
        $season = null;
        $weather = null;
        $dayNight = null;

        foreach ($world->query(Season::class) as $e) { $season = $e->get(Season::class); break; }
        foreach ($world->query(Weather::class) as $e) { $weather = $e->get(Weather::class); break; }
        foreach ($world->query(DayNightCycle::class) as $e) { $dayNight = $e->get(DayNightCycle::class); break; }

        if ($dayNight === null) return;

        if ($season !== null) {
            $dayNight->axialTilt = $season->axialTilt;
        }
        if ($weather !== null) {
            $dayNight->cloudDarkening = $weather->cloudCoverage * 0.5;
            $dayNight->lightningFlash = $weather->lightningFlash;

            // Fog visibility: fogDensity 0→1 maps to normal distance → near-zero visibility
            if ($weather->fogDensity > 0.05) {
                $baseFogNear = 60.0 + (1.0 - ($dayNight->getSunHeight())) * 20.0;
                $baseFogFar = 280.0 - (1.0 - ($dayNight->getSunHeight())) * 80.0;

                // Dense fog: near shrinks to 2m, far shrinks to 25m at max density
                $fogFactor = $weather->fogDensity;
                $dayNight->fogNearOverride = $baseFogNear * (1.0 - $fogFactor) + 2.0 * $fogFactor;
                $dayNight->fogFarOverride = $baseFogFar * (1.0 - $fogFactor) + 25.0 * $fogFactor;
            } else {
                // No fog override — let DayNightSystem compute normally
                $dayNight->fogNearOverride = -1.0;
                $dayNight->fogFarOverride = -1.0;
            }

            // Sandstorm also reduces visibility
            if ($weather->sandstormIntensity > 0.1) {
                $sandFactor = $weather->sandstormIntensity;
                $currentNear = $dayNight->fogNearOverride >= 0 ? $dayNight->fogNearOverride : 60.0;
                $currentFar = $dayNight->fogFarOverride >= 0 ? $dayNight->fogFarOverride : 200.0;
                $dayNight->fogNearOverride = $currentNear * (1.0 - $sandFactor) + 5.0 * $sandFactor;
                $dayNight->fogFarOverride = $currentFar * (1.0 - $sandFactor) + 40.0 * $sandFactor;
            }
        }
    }
}
