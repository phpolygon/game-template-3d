<?php

declare(strict_types=1);

namespace App\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

#[Serializable]
class CloudDrift extends AbstractComponent
{
    #[Property]
    public float $speed = 1.0;

    #[Property]
    public float $resetMinX = -80.0;

    #[Property]
    public float $resetMaxX = 80.0;

    #[Property]
    public float $bobAmplitude = 0.3;

    #[Property]
    public float $bobFrequency = 0.2;

    #[Property]
    public float $phaseOffset = 0.0;

    public float $baseY = 0.0;

    /** Index for visibility ordering (lower indices shown first) */
    public int $cloudIndex = 0;

    /** Target alpha for smooth show/hide transitions */
    public float $targetAlpha = 1.0;

    /** Cloud type: 0=cumulus, 1=stratus, 2=cumulonimbus */
    public int $cloudType = 0;

    /** Original scale for type morphing */
    public float $baseScaleX = 0.0;
    public float $baseScaleY = 0.0;
    public float $baseScaleZ = 0.0;
}
