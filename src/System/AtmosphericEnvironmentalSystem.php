<?php

declare(strict_types=1);

namespace App\System;

use App\Component\Atmosphere;
use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Season;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;
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

    // Rainbow state
    private float $prevRainIntensity = 0.0;
    private float $rainbowTimer = 0.0;
    private bool $rainbowActive = false;

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
        $this->coupleToDayNight($world, $dt);
        $this->updateRainbow($world, $dt);

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

    private function coupleToDayNight(World $world, float $dt): void
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
            // Storm darkens sky dramatically (up to 85%), not just clouds
            $cloudDark = $weather->cloudCoverage * 0.5;
            $stormDark = $weather->stormIntensity * 0.8;
            $dayNight->cloudDarkening = min(0.85, max($cloudDark, $stormDark));
            $dayNight->lightningFlash = $weather->lightningFlash;

            // Snow/rain accumulation (time-based, not instant)
            $atmoComp = null;
            foreach ($world->query(Atmosphere::class) as $ae) { $atmoComp = $ae->get(Atmosphere::class); break; }
            if ($atmoComp !== null) {
                // Snow builds up while snowing, melts when not
                if ($weather->snowIntensity > 0.05) {
                    // Accumulation rate: ~0.1 per second at full intensity → full cover in ~10s
                    $atmoComp->snowAccumulation = min(1.0,
                        $atmoComp->snowAccumulation + $weather->snowIntensity * $dt * 0.1);
                } else {
                    // Melt rate: slower than accumulation, depends on temperature
                    $meltRate = max(0.0, $weather->temperature) * 0.005 + 0.01;
                    $atmoComp->snowAccumulation = max(0.0,
                        $atmoComp->snowAccumulation - $meltRate * $dt);
                }

                // Wet ground builds up during rain, dries after
                if ($weather->rainIntensity > 0.05) {
                    $atmoComp->wetAccumulation = min(1.0,
                        $atmoComp->wetAccumulation + $weather->rainIntensity * $dt * 0.15);
                } else {
                    // Drying rate: faster in sun, slower at night
                    $dryRate = 0.02 + max(0.0, $dayNight->getSunHeight()) * 0.05;
                    $atmoComp->wetAccumulation = max(0.0,
                        $atmoComp->wetAccumulation - $dryRate * $dt);
                }
            }

            // Weather surface data → pushed to shader via DayNightSystem
            $dayNight->weatherRainIntensity = $atmoComp !== null
                ? max($weather->rainIntensity, $atmoComp->wetAccumulation)
                : $weather->rainIntensity;
            $dayNight->weatherSnowCoverage = $atmoComp !== null
                ? $atmoComp->snowAccumulation
                : $weather->snowIntensity;
            $dayNight->weatherTemperature = $weather->temperature;
            $dayNight->weatherStormIntensity = $weather->stormIntensity;

            // Dew wetness from dew point spread (high when spread < 5°C)
            $atmoEntity = null;
            foreach ($world->query(Atmosphere::class) as $e) { $atmoEntity = $e->get(Atmosphere::class); break; }
            $dayNight->weatherDewWetness = $atmoEntity !== null
                ? max(0.0, 1.0 - $atmoEntity->dewPointSpread / 5.0)
                : 0.0;

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

    private function updateRainbow(World $world, float $dt): void
    {
        $weather = null;
        $dayNight = null;
        foreach ($world->query(Weather::class) as $e) { $weather = $e->get(Weather::class); break; }
        foreach ($world->query(DayNightCycle::class) as $e) { $dayNight = $e->get(DayNightCycle::class); break; }
        if ($weather === null || $dayNight === null) return;

        // Trigger: rain was heavy (>0.2) and just stopped (<0.05), sun is up
        $wasRaining = $this->prevRainIntensity > 0.2;
        $stoppedRaining = $weather->rainIntensity < 0.05;
        $sunUp = $dayNight->getSunHeight() > 0.15;

        if ($wasRaining && $stoppedRaining && $sunUp && !$this->rainbowActive) {
            $this->rainbowActive = true;
            $this->rainbowTimer = 45.0; // visible for 45 seconds
            fprintf(STDERR, "[Rainbow] Appeared!\n");
        }

        $this->prevRainIntensity = $weather->rainIntensity;

        // Find rainbow entity and update position/alpha
        foreach ($world->query(Transform3D::class, MeshRenderer::class) as $entity) {
            $mr = $entity->get(MeshRenderer::class);
            if ($mr->materialId !== 'rainbow') continue;

            $transform = $entity->get(Transform3D::class);

            if ($this->rainbowActive) {
                $this->rainbowTimer -= $dt;

                // Fade: full opacity for first 30s, then fade over 15s
                $alpha = $this->rainbowTimer > 15.0 ? 0.35 : ($this->rainbowTimer / 15.0) * 0.35;

                // Position rainbow in front of the scene
                $transform->position = new Vec3(0.0, 0.0, -80.0);

                // Update material alpha
                MaterialRegistry::register('rainbow', new Material(
                    albedo: new Color(1.0, 1.0, 1.0),
                    alpha: max(0.0, $alpha),
                ));

                if ($this->rainbowTimer <= 0.0) {
                    $this->rainbowActive = false;
                    $transform->position = new Vec3(0.0, -200.0, -60.0); // hide
                    MaterialRegistry::register('rainbow', new Material(
                        albedo: new Color(1.0, 1.0, 1.0),
                        alpha: 0.0,
                    ));
                }
            }
            break;
        }
    }
}
