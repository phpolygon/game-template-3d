<?php

declare(strict_types=1);

namespace App\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;

#[Serializable]
class FirstPersonCamera extends AbstractComponent
{
    #[Property]
    public float $sensitivity = 0.002;

    #[Property]
    public float $moveSpeed = 5.0;

    #[Property]
    public float $yaw = 0.0;

    #[Property]
    public float $pitch = 0.0;
}
