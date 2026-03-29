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
}
