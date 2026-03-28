<?php

declare(strict_types=1);

namespace App\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

#[Serializable]
class FootprintMark extends AbstractComponent
{
    #[Property]
    public float $lifetime = 8.0;

    #[Property]
    public float $age = 0.0;

    #[Property]
    public float $maxLifetime = 8.0;
}
