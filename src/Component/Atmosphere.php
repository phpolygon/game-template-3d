<?php

declare(strict_types=1);

namespace App\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Category;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

/**
 * Atmospheric state for realistic weather physics.
 * Computed by AtmosphereSystem, read by WindSystem, CloudSystem, DayNightSystem.
 */
#[Serializable]
#[Category('Environment')]
class Atmosphere extends AbstractComponent
{
    // --- Pressure system ---

    /** Atmospheric pressure in hPa (standard sea level: 1013.25) */
    #[Hidden]
    public float $pressure = 1013.25;

    /** Normalized pressure gradient magnitude (0-1, drives wind speed) */
    #[Hidden]
    public float $pressureGradient = 0.0;

    /** Whether the nearest pressure system is high (true) or low (false) */
    #[Hidden]
    public bool $isHighPressure = true;

    /** Slow-moving phase for pressure system circulation */
    #[Hidden]
    public float $pressureCyclePhase = 0.0;

    // --- Dew point ---

    /** Dew point temperature in °C (Magnus formula) */
    #[Hidden]
    public float $dewPoint = 12.0;

    /** Temperature minus dew point. Fog when < 2.5°C */
    #[Hidden]
    public float $dewPointSpread = 10.0;

    // --- Wind direction ---

    /** Wind angle in radians (from pressure gradient + Coriolis approximation) */
    #[Hidden]
    public float $windAngle = 0.0;

    // --- Cloud types (fractions 0-1) ---

    /** Fair-weather cumulus (convection-driven, puffy) */
    #[Hidden]
    public float $cumulusFraction = 0.0;

    /** Overcast stratus (stable layer, uniform) */
    #[Hidden]
    public float $stratusFraction = 0.0;

    /** Thunderstorm cumulonimbus (strong convection + moisture) */
    #[Hidden]
    public float $cumulonimbusFraction = 0.0;

    /** High-altitude cirrus (wispy, affects light only) */
    #[Hidden]
    public float $cirrusFraction = 0.2;

    /** Cloud base altitude in meters (lower when humid, higher when dry) */
    #[Hidden]
    public float $cloudBaseAltitude = 45.0;

    // --- Convection ---

    /** Thermal convection strength 0-1 (heated ground → rising air) */
    #[Hidden]
    public float $convectionStrength = 0.0;

    /** Atmospheric instability index 0-1 (low pressure = unstable) */
    #[Hidden]
    public float $instability = 0.0;

    // --- Frontal systems ---

    /** Current front type: 0=none, 1=cold front, 2=warm front */
    #[Hidden]
    public int $frontType = 0;

    /** Front passage progress 0-1 */
    #[Hidden]
    public float $frontProgress = 0.0;

    /** Duration of current front in seconds */
    #[Hidden]
    public float $frontDuration = 180.0;

    /** Countdown to next front event (seconds) */
    #[Hidden]
    public float $nextFrontTimer = 300.0;

    /** Temperature modifier from frontal passage */
    #[Hidden]
    public float $frontTempMod = 0.0;

    /** Humidity modifier from frontal passage */
    #[Hidden]
    public float $frontHumidityMod = 0.0;

    // --- Precipitation physics ---

    /** Rain rate in mm/h equivalent */
    #[Hidden]
    public float $rainRateMmH = 0.0;

    /** Humidity gain rate from evaporation (sun + warm surfaces) */
    #[Hidden]
    public float $evaporationRate = 0.0;

    // --- Internal ---

    #[Hidden]
    public float $time = 0.0;

    /** Seconds remaining where AtmosphereSystem should not overwrite Weather values (for testing shortcuts) */
    #[Hidden]
    public float $forcedTimer = 0.0;
}
