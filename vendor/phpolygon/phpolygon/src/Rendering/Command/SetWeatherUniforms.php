<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Command;

readonly class SetWeatherUniforms
{
    public function __construct(
        public float $rainIntensity = 0.0,
        public float $snowCoverage = 0.0,
        public float $temperature = 20.0,
        public float $dewWetness = 0.0,
        public float $stormIntensity = 0.0,
    ) {}
}
