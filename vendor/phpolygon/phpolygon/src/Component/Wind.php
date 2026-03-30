<?php

declare(strict_types=1);

namespace PHPolygon\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

#[Serializable]
class Wind extends AbstractComponent
{
    #[Property]
    public float $intensity = 0.5;

    #[Property]
    public float $maxIntensity = 1.0;

    #[Property]
    public float $minIntensity = 0.1;

    #[Property]
    public float $gustFrequency = 0.3;

    #[Property]
    public float $gustAmplitude = 0.4;

    #[Property]
    public float $time = 0.0;

    public Vec3 $direction;

    /** Current wind angle in radians (set by WindSystem from Atmosphere) */
    public float $windAngle = 0.0;

    public function __construct()
    {
        $this->direction = new Vec3(1.0, 0.0, -0.3);
    }
}
