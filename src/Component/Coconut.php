<?php

declare(strict_types=1);

namespace App\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Hidden;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/**
 * Marks a coconut that can detach and fall during strong wind.
 * While attached: sways with PalmSway. When detached: becomes a RigidBody3D.
 */
#[Serializable]
class Coconut extends AbstractComponent
{
    /** Wind intensity threshold to detach (randomized per coconut) */
    #[Property]
    public float $detachThreshold;

    /** How long (seconds) sustained wind above threshold before detaching */
    #[Property]
    public float $detachDelay;

    /** Whether this coconut has fallen off */
    #[Hidden]
    public bool $detached = false;

    /** Accumulated time above wind threshold */
    #[Hidden]
    public float $stressTime = 0.0;

    /** Original attached position (for sway while attached) */
    #[Hidden]
    public ?Vec3 $attachedPosition = null;

    public function __construct(
        float $detachThreshold = 1.8,
        float $detachDelay = 3.0,
    ) {
        $this->detachThreshold = $detachThreshold;
        $this->detachDelay = $detachDelay;
    }
}
