<?php

declare(strict_types=1);

namespace App\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

#[Serializable]
class WaveStrip extends AbstractComponent
{
    #[Property]
    public float $phaseOffset = 0.0;

    #[Property]
    public float $amplitude = 0.3;

    #[Property]
    public float $frequency = 1.5;

    #[Property]
    public float $baseY = -0.3;

    #[Property]
    public bool $isFoam = false;
}
