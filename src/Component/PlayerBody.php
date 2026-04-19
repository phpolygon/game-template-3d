<?php

declare(strict_types=1);

namespace App\Component;

use PHPolygon\ECS\AbstractComponent;
use PHPolygon\ECS\Attribute\Property;
use PHPolygon\ECS\Attribute\Serializable;
use PHPolygon\Math\Vec3;

/**
 * Marks an entity as part of the player's body. PlayerBodySystem places and
 * animates these entities each frame relative to the Player entity.
 *
 * Geometry layout (all coordinates in the player's local frame, Y up):
 *   pivotOffset is where the limb attaches to the body (shoulder / hip /
 *   neck). restCenter is the mesh centre expressed relative to that pivot
 *   when the limb is at rest (straight down for arms/legs, forward-up for
 *   the head). Swing rotation happens about the pivot around local X.
 */
#[Serializable]
class PlayerBody extends AbstractComponent
{
    public const PART_HEAD       = 'head';
    public const PART_HAIR       = 'hair';
    public const PART_TORSO      = 'torso';
    public const PART_HIP        = 'hip';
    public const PART_ARM_LEFT   = 'arm_left';
    public const PART_ARM_RIGHT  = 'arm_right';
    public const PART_HAND_LEFT  = 'hand_left';
    public const PART_HAND_RIGHT = 'hand_right';
    public const PART_LEG_LEFT   = 'leg_left';
    public const PART_LEG_RIGHT  = 'leg_right';
    public const PART_FOOT_LEFT  = 'foot_left';
    public const PART_FOOT_RIGHT = 'foot_right';

    #[Property]
    public string $playerEntityName = 'Player';

    /** Which body part this entity represents (one of the PART_* constants). */
    #[Property]
    public string $part = '';

    /** Attachment point relative to the player's root position. */
    #[Property]
    public Vec3 $pivotOffset;

    /** Mesh centre relative to the pivot when the limb is at rest. */
    #[Property]
    public Vec3 $restCenter;

    /** Swing amplitude in radians for the walk cycle (0 = static). */
    #[Property]
    public float $swingAmp = 0.0;

    /** Phase sign: +1 / -1 so opposite limbs swing in anti-phase, 0 for static parts. */
    #[Property]
    public int $swingSign = 0;

    /** If true, the part follows the camera pitch (head nodding). */
    #[Property]
    public bool $followsPitch = false;

    /**
     * Collapse this part to zero scale when the player's first-person camera
     * is the active view. Used for head/hair/eyes that would otherwise clip
     * through the camera. Off-screen renders (mirror, portraits) should
     * restore the scale before drawing so the part is visible there.
     */
    #[Property]
    public bool $hideInFirstPerson = false;

    /** Original scale captured on first update so hideInFirstPerson can restore it. */
    public ?Vec3 $baseScale = null;

    public function __construct(
        string $part = '',
        ?Vec3 $pivotOffset = null,
        ?Vec3 $restCenter = null,
        float $swingAmp = 0.0,
        int $swingSign = 0,
        bool $followsPitch = false,
        bool $hideInFirstPerson = false,
    ) {
        $this->part = $part;
        $this->pivotOffset = $pivotOffset ?? Vec3::zero();
        $this->restCenter = $restCenter ?? Vec3::zero();
        $this->swingAmp = $swingAmp;
        $this->swingSign = $swingSign;
        $this->followsPitch = $followsPitch;
        $this->hideInFirstPerson = $hideInFirstPerson;
    }
}
