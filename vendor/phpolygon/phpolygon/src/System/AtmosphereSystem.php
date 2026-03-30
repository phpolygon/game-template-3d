<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Atmosphere;
use PHPolygon\Component\DayNightCycle;
use PHPolygon\Component\Season;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

/**
 * Physically-based atmospheric simulation.
 * Computes pressure, dew point, convection, cloud types, fronts, and precipitation.
 * Writes results into the Weather component (overriding the simple threshold-based engine system).
 */
class AtmosphereSystem extends AbstractSystem
{
    private const STANDARD_PRESSURE = 1013.25;
    private int $debugCounter = 0;

    public function update(World $world, float $dt): void
    {
        $this->debugCounter++;
        $atmo = null;
        $weather = null;
        $season = null;
        $dayNight = null;

        foreach ($world->query(Atmosphere::class) as $entity) {
            $atmo = $entity->get(Atmosphere::class);
            break;
        }
        if ($atmo === null) return;

        foreach ($world->query(Weather::class) as $e) { $weather = $e->get(Weather::class); break; }
        foreach ($world->query(Season::class) as $e) { $season = $e->get(Season::class); break; }
        foreach ($world->query(DayNightCycle::class) as $e) { $dayNight = $e->get(DayNightCycle::class); break; }

        $atmo->time += $dt;

        // Forced weather: skip overwriting Weather values while timer active
        if ($atmo->forcedTimer > 0.0) {
            $atmo->forcedTimer -= $dt;
            return;
        }

        $timeOfDay = $dayNight ? $dayNight->timeOfDay : 0.5;
        $yearProgress = $season ? $season->yearProgress : 0.25;
        $sunHeight = $dayNight ? $dayNight->getSunHeight() : 0.5;

        // =====================================================================
        // 1. PRESSURE SYSTEM
        // =====================================================================
        $seasonalMod = sin($yearProgress * 2.0 * M_PI) * 15.0;
        $diurnalMod = sin(($timeOfDay - 0.25) * 2.0 * M_PI) * 3.0;
        $cycleMod = sin($atmo->time * 0.005) * 20.0 + sin($atmo->time * 0.0017) * 12.0;

        // Front pressure modification
        $frontPressureMod = 0.0;
        if ($atmo->frontType === 1) { // Cold front
            $frontPressureMod = -12.0 * self::bell($atmo->frontProgress, 0.2, 0.1);
        } elseif ($atmo->frontType === 2) { // Warm front
            $frontPressureMod = -5.0 * self::bell($atmo->frontProgress, 0.5, 0.3);
        }

        $atmo->pressure = self::STANDARD_PRESSURE + $seasonalMod + $diurnalMod + $cycleMod + $frontPressureMod;
        $atmo->isHighPressure = $atmo->pressure > self::STANDARD_PRESSURE;
        $atmo->pressureGradient = min(1.0, abs($atmo->pressure - self::STANDARD_PRESSURE) / 30.0);

        // Pressure center moves slowly in a circle
        $atmo->pressureCyclePhase += $dt * 0.003;
        $pCenterX = cos($atmo->pressureCyclePhase) * 200.0;
        $pCenterZ = sin($atmo->pressureCyclePhase * 0.7) * 150.0;

        // =====================================================================
        // 2. WIND DIRECTION (Pressure Gradient + Coriolis)
        // =====================================================================
        $pressureAngle = atan2($pCenterZ, $pCenterX);
        $coriolisOffset = $atmo->isHighPressure ? M_PI / 6.0 : -M_PI / 6.0;
        $targetWindAngle = $pressureAngle + M_PI * 0.5 + $coriolisOffset;
        // Smooth transition
        $angleDiff = fmod($targetWindAngle - $atmo->windAngle + 3 * M_PI, 2 * M_PI) - M_PI;
        $atmo->windAngle += $angleDiff * 0.1 * $dt;

        // =====================================================================
        // 3. TEMPERATURE (Season + Time of Day + Fronts)
        // =====================================================================
        $baseTemp = $season ? $season->baseTemperature : 22.0;
        $dayTempMod = sin(($timeOfDay - 0.25) * 2.0 * M_PI) * 8.0;
        $cloudDamping = 1.0 - ($weather ? $weather->cloudCoverage : 0.0) * 0.4;

        // Front temperature modification
        $atmo->frontTempMod *= max(0.0, 1.0 - 0.3 * $dt); // Decay existing mod
        if ($atmo->frontType === 1) {
            $atmo->frontTempMod = -8.0 * self::smoothstep(0.0, 0.3, $atmo->frontProgress);
        } elseif ($atmo->frontType === 2) {
            $atmo->frontTempMod = 5.0 * self::smoothstep(0.0, 0.7, $atmo->frontProgress);
        }

        if ($weather !== null) {
            $weather->temperature = $baseTemp + $dayTempMod * $cloudDamping + $atmo->frontTempMod;
        }

        // =====================================================================
        // 4. HUMIDITY (Season + Evaporation + Precipitation Drain + Fronts)
        // =====================================================================
        $baseHumidity = $season ? $season->baseHumidity : 0.5;
        $humidityDrift = sin($atmo->time * 0.02) * 0.15 + sin($atmo->time * 0.007) * 0.1;

        // Front humidity modification
        $atmo->frontHumidityMod = 0.0;
        if ($atmo->frontType === 1) {
            $atmo->frontHumidityMod = 0.3 * self::bell($atmo->frontProgress, 0.3, 0.15);
        } elseif ($atmo->frontType === 2) {
            $atmo->frontHumidityMod = 0.2 * (1.0 - $atmo->frontProgress);
        }

        // Evaporation: sun + warm temperature adds moisture
        $temp = $weather ? $weather->temperature : 22.0;
        $atmo->evaporationRate = $sunHeight * max(0.0, $temp - 10.0) / 30.0 * 0.01;

        // Precipitation drains humidity
        $precipDrain = 0.0;
        if ($weather !== null && $atmo->rainRateMmH > 0) {
            $precipDrain = ($atmo->rainRateMmH / 50.0) * 0.02;
        }

        if ($weather !== null) {
            $weather->humidity = max(0.0, min(1.0,
                $baseHumidity + $humidityDrift + $atmo->frontHumidityMod
                + $atmo->evaporationRate * $dt - $precipDrain * $dt
            ));
        }

        // =====================================================================
        // 5. DEW POINT (Magnus formula approximation)
        // =====================================================================
        if ($weather !== null) {
            $T = $weather->temperature;
            $RH = max(0.01, $weather->humidity);
            $a = 17.27;
            $b = 237.7;
            $alpha = ($a * $T) / ($b + $T) + log($RH);
            $atmo->dewPoint = ($b * $alpha) / ($a - $alpha);
            $atmo->dewPointSpread = $T - $atmo->dewPoint;
        }

        // =====================================================================
        // 6. INSTABILITY + CONVECTION
        // =====================================================================
        $atmo->instability = max(0.0, min(1.0, (self::STANDARD_PRESSURE - $atmo->pressure) / 30.0));

        $solarHeating = $sunHeight * (1.0 - ($weather ? $weather->cloudCoverage : 0.0) * 0.7);
        $convBase = $solarHeating * max(0.0, $temp - 15.0) / 20.0 * (1.0 + $atmo->instability);
        // Afternoon boost
        $afternoonBoost = max(0.0, 1.0 - abs($timeOfDay - 0.55) * 5.0);
        $atmo->convectionStrength = min(1.0, $convBase * (0.5 + $afternoonBoost * 0.5));

        // =====================================================================
        // 7. CLOUD TYPES
        // =====================================================================
        $humidity = $weather ? $weather->humidity : 0.5;
        $conv = $atmo->convectionStrength;

        // Reset
        $atmo->cumulonimbusFraction = 0.0;
        $atmo->cumulusFraction = 0.0;
        $atmo->stratusFraction = 0.0;

        if ($atmo->instability > 0.6 && $humidity > 0.7 && $conv > 0.5) {
            // Thunderstorm conditions
            $atmo->cumulonimbusFraction = min(1.0, ($atmo->instability - 0.6) * 2.5 * $humidity);
        }
        if ($conv > 0.3 && $humidity < 0.7) {
            // Fair-weather cumulus
            $atmo->cumulusFraction = min(1.0, $conv * (1.0 - $humidity * 0.5));
        }
        if ($humidity > 0.6 && $atmo->instability < 0.4) {
            // Stable overcast stratus
            $atmo->stratusFraction = min(1.0, ($humidity - 0.4) / 0.6 * (1.0 - $atmo->instability));
        }

        // Cirrus: always some present
        $atmo->cirrusFraction = 0.2 + sin($atmo->time * 0.01) * 0.15;

        // Cloud base altitude (min 40m so clouds stay well above terrain/water)
        $atmo->cloudBaseAltitude = max(40.0, 45.0 + (1.0 - $humidity) * 15.0 - $atmo->cumulonimbusFraction * 5.0);

        // =====================================================================
        // 8. CLOUD COVERAGE → WEATHER
        // =====================================================================
        if ($weather !== null) {
            // Cloud coverage from humidity + cloud type fractions
            $targetCoverage = max(
                $atmo->stratusFraction * 0.9,
                $atmo->cumulusFraction * 0.4,
                $atmo->cumulonimbusFraction * 0.95,
            );
            if ($humidity > 0.4) {
                $targetCoverage = max($targetCoverage, ($humidity - 0.4) / 0.6 * 0.7);
            }
            $weather->cloudCoverage += ($targetCoverage - $weather->cloudCoverage) * 1.5 * $dt;
            $weather->cloudCoverage = max(0.0, min(1.0, $weather->cloudCoverage));
        }

        // =====================================================================
        // 9. PRECIPITATION
        // =====================================================================
        if ($weather !== null) {
            $canPrecipitate = $weather->cloudCoverage > 0.6 && $humidity > 0.6;

            if ($canPrecipitate) {
                $precipIntensity = ($weather->cloudCoverage - 0.6) / 0.4 * ($humidity - 0.6) / 0.4;
                $precipIntensity = max(0.0, min(1.0, $precipIntensity));
                // Cumulonimbus makes rain heavier
                $precipIntensity *= 1.0 + $atmo->cumulonimbusFraction * 1.5;

                if ($weather->temperature > 2.0) {
                    $weather->rainIntensity += ($precipIntensity - $weather->rainIntensity) * 0.5 * $dt;
                    $weather->snowIntensity *= max(0.0, 1.0 - 2.0 * $dt);
                } else {
                    $weather->snowIntensity += ($precipIntensity - $weather->snowIntensity) * 0.3 * $dt;
                    $weather->rainIntensity *= max(0.0, 1.0 - 2.0 * $dt);
                }
            } else {
                $weather->rainIntensity *= max(0.0, 1.0 - 0.5 * $dt);
                $weather->snowIntensity *= max(0.0, 1.0 - 0.3 * $dt);
            }

            if ($weather->rainIntensity < 0.01) $weather->rainIntensity = 0.0;
            if ($weather->snowIntensity < 0.01) $weather->snowIntensity = 0.0;

            $atmo->rainRateMmH = max($weather->rainIntensity, $weather->snowIntensity) * 50.0;
        }

        // =====================================================================
        // 10. STORM
        // =====================================================================
        if ($weather !== null) {
            $canStorm = $atmo->cumulonimbusFraction > 0.3 && $humidity > 0.7;
            if ($canStorm) {
                $stormTarget = $atmo->cumulonimbusFraction * $humidity;
                $weather->stormIntensity += (min(1.0, $stormTarget) - $weather->stormIntensity) * 0.2 * $dt;
            } else {
                $weather->stormIntensity *= max(0.0, 1.0 - 0.5 * $dt);
            }
            if ($weather->stormIntensity < 0.01) $weather->stormIntensity = 0.0;

            // Lightning
            $weather->lightningFlash *= max(0.0, 1.0 - 8.0 * $dt);
            if ($weather->stormIntensity > 0.3) {
                $weather->lightningTimer += $dt;
                $interval = 3.0 + (1.0 - $weather->stormIntensity) * 7.0;
                if ($weather->lightningTimer >= $interval) {
                    $weather->lightningFlash = 1.0;
                    $weather->lightningTimer = 0.0;
                }
            }
        }

        // =====================================================================
        // 11. FOG (from dew point spread)
        // =====================================================================
        if ($weather !== null) {
            $windIntensity = 0.5;
            foreach ($world->query(\PHPolygon\Component\Wind::class) as $e) {
                $windIntensity = $e->get(\PHPolygon\Component\Wind::class)->intensity;
                break;
            }

            $fogTarget = 0.0;
            if ($atmo->dewPointSpread < 2.5) {
                $fogTarget = 1.0 - $atmo->dewPointSpread / 2.5;
                $fogTarget *= max(0.0, 1.0 - $windIntensity * 1.5); // Wind dissipates fog
            }
            $weather->fogDensity += ($fogTarget - $weather->fogDensity) * 0.2 * $dt;
            if ($weather->fogDensity < 0.01) $weather->fogDensity = 0.0;
        }

        // =====================================================================
        // 12. SANDSTORM
        // =====================================================================
        if ($weather !== null) {
            $windIntensity = 0.5;
            foreach ($world->query(\PHPolygon\Component\Wind::class) as $e) {
                $windIntensity = $e->get(\PHPolygon\Component\Wind::class)->intensity;
                break;
            }
            $canSand = $windIntensity > 0.8 && $humidity < 0.3;
            if ($canSand) {
                $weather->sandstormIntensity += (0.8 - $weather->sandstormIntensity) * 0.3 * $dt;
            } else {
                $weather->sandstormIntensity *= max(0.0, 1.0 - 0.5 * $dt);
            }
            if ($weather->sandstormIntensity < 0.01) $weather->sandstormIntensity = 0.0;
        }

        // =====================================================================
        // 13. FRONTAL SYSTEMS
        // =====================================================================
        $atmo->nextFrontTimer -= $dt;
        if ($atmo->nextFrontTimer <= 0.0 && $atmo->frontType === 0) {
            // Trigger new front
            $atmo->frontType = ($atmo->frontTempMod <= 0.0 || sin($atmo->time * 0.003) > 0) ? 1 : 2;
            $atmo->frontProgress = 0.0;
            $atmo->frontDuration = 120.0 + self::pseudoRandom($atmo->time) * 90.0; // 120-210s
            $atmo->nextFrontTimer = 300.0 + abs(self::pseudoRandom($atmo->time * 1.7)) * 300.0; // 300-600s
        }

        if ($atmo->frontType !== 0) {
            $atmo->frontProgress += $dt / $atmo->frontDuration;
            if ($atmo->frontProgress >= 1.0) {
                $atmo->frontType = 0;
                $atmo->frontProgress = 0.0;
            }
        }

        // =====================================================================
        // 14. WEATHER STATE LABEL
        // =====================================================================
        if ($weather !== null) {
            if ($weather->stormIntensity > 0.3) {
                $weather->state = \PHPolygon\Component\WeatherState::Storm;
            } elseif ($weather->snowIntensity > 0.1) {
                $weather->state = \PHPolygon\Component\WeatherState::Snow;
            } elseif ($weather->rainIntensity > 0.1) {
                $weather->state = \PHPolygon\Component\WeatherState::Rain;
            } elseif ($weather->fogDensity > 0.3) {
                $weather->state = \PHPolygon\Component\WeatherState::Fog;
            } elseif ($weather->cloudCoverage > 0.5) {
                $weather->state = \PHPolygon\Component\WeatherState::Cloudy;
            } else {
                $weather->state = \PHPolygon\Component\WeatherState::Clear;
            }
        }

        if ($this->debugCounter % 120 === 1 && $weather !== null) {
            fprintf(STDERR, "[Atmo] pressure=%.0f grad=%.2f cloud=%.2f rain=%.2f storm=%.2f fog=%.2f hum=%.2f temp=%.1f dew=%.1f spread=%.1f forced=%.1f state=%s\n",
                $atmo->pressure, $atmo->pressureGradient,
                $weather->cloudCoverage, $weather->rainIntensity, $weather->stormIntensity,
                $weather->fogDensity, $weather->humidity, $weather->temperature,
                $atmo->dewPoint, $atmo->dewPointSpread, $atmo->forcedTimer,
                $weather->state->name ?? 'unknown');
        }
    }

    // --- Math helpers ---

    private static function smoothstep(float $edge0, float $edge1, float $x): float
    {
        $t = max(0.0, min(1.0, ($x - $edge0) / ($edge1 - $edge0)));
        return $t * $t * (3.0 - 2.0 * $t);
    }

    /** Bell curve centered at $center with width $sigma */
    private static function bell(float $x, float $center, float $sigma): float
    {
        $d = ($x - $center) / $sigma;
        return exp(-0.5 * $d * $d);
    }

    private static function pseudoRandom(float $seed): float
    {
        $n = sin($seed * 12.9898 + 78.233) * 43758.5453;
        return ($n - floor($n)) * 2.0 - 1.0; // -1 to 1
    }
}
