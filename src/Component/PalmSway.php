<?php

declare(strict_types=1);

namespace App\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

#[Serializable]
class PalmSway extends AbstractComponent
{
    #[Property]
    public float $swayStrength = 1.0;

    #[Property]
    public float $phaseOffset = 0.0;

    #[Property]
    public bool $isTrunk = true;

    public float $baseY = 0.0;
    public float $baseX = 0.0;
    public float $baseZ = 0.0;
}
