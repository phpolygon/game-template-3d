<?php

declare(strict_types=1);

namespace App\System;

use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Command\SetAmbientLight;
use PHPolygon\Rendering\Command\SetFog;
use PHPolygon\Rendering\RenderCommandList;

class AmbientLightSystem extends AbstractSystem
{
    public function __construct(
        private readonly RenderCommandList $commandList,
    ) {}

    public function render(World $world): void
    {
        // Sky-blue ambient — fills the underside of everything with scattered skylight
        // Slightly cool (blue-tinted) to mimic open-sky indirect lighting
        $this->commandList->add(new SetAmbientLight(
            color: Color::hex('#C8DCF0'),
            intensity: 0.28,
        ));

        // Atmospheric haze matching the horizon sky colour.
        // near = 60: no fog within 60m (whole beach is visible)
        // far  = 280: full fog at 280m (ocean disappears into haze)
        // This creates the illusion that water/horizon fades to sky,
        // giving strong depth cues without any extra geometry.
        $this->commandList->add(new SetFog(
            color: Color::hex('#C8DCF0'),
            near: 60.0,
            far: 280.0,
        ));
    }
}
