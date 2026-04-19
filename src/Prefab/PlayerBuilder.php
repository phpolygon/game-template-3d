<?php

declare(strict_types=1);

namespace App\Prefab;

use App\Component\PlayerBody;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\Math\Vec3;
use PHPolygon\Scene\SceneBuilder;

/**
 * Builds a stylised low-poly humanoid body for the Player entity.
 *
 * All coordinates are expressed in the player's local frame (Y up) with the
 * origin at the capsule centre — the Player's Transform3D.position. The
 * capsule runs from y = -halfHeight (feet) to y = +halfHeight (crown).
 * PlayerBodySystem places and rotates each part relative to the player each
 * frame.
 *
 * Proportions assume a 1.8 m tall character. If the capsule height changes,
 * pass a matching value so the limbs land on the feet plane.
 */
class PlayerBuilder
{
    private float $height = 1.8;

    public static function at(float $height = 1.8): self
    {
        $self = new self();
        $self->height = $height;
        return $self;
    }

    public function build(SceneBuilder $builder): void
    {
        $halfH = $this->height * 0.5;

        // Key Y offsets (relative to capsule centre):
        //   foot plane      = -halfH       (feet bottom)
        //   hip pivot       = -halfH + 0.88 (roughly 0 for 1.8 m capsule)
        //   shoulder pivot  = hip + 0.5
        //   neck pivot      = shoulder + 0.18
        $hipY       = -$halfH + 0.88;
        $shoulderY  = $hipY + 0.50;
        $neckY      = $shoulderY + 0.18;
        $legLen     = $hipY - (-$halfH + 0.08); // pivot → ankle top
        $armLen     = 0.60;

        // --- TORSO (shirt) ---
        $builder->entity('PlayerBody_Torso')
            ->with(new Transform3D(
                position: Vec3::zero(),
                scale: new Vec3(0.20, 0.24, 0.12),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: 'player_shirt'))
            ->with(new PlayerBody(
                part: PlayerBody::PART_TORSO,
                pivotOffset: new Vec3(0.0, $shoulderY - 0.24, 0.0),
                restCenter: Vec3::zero(),
            ));

        // --- HIP (pants block between torso and legs) ---
        $builder->entity('PlayerBody_Hip')
            ->with(new Transform3D(
                position: Vec3::zero(),
                scale: new Vec3(0.18, 0.10, 0.11),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: 'player_pants'))
            ->with(new PlayerBody(
                part: PlayerBody::PART_HIP,
                pivotOffset: new Vec3(0.0, $hipY + 0.08, 0.0),
                restCenter: Vec3::zero(),
            ));

        // --- NECK --- (hidden in first person — sits right under the camera)
        $builder->entity('PlayerBody_Neck')
            ->with(new Transform3D(
                position: Vec3::zero(),
                scale: new Vec3(0.05, 0.04, 0.05),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: 'player_skin'))
            ->with(new PlayerBody(
                part: PlayerBody::PART_HEAD,
                pivotOffset: new Vec3(0.0, $shoulderY + 0.09, 0.0),
                restCenter: Vec3::zero(),
                hideInFirstPerson: true,
            ));

        // --- HEAD ---
        // Local Z convention: the player faces -Z in local space (matches
        // FirstPersonCamera forward). Negative Z = forward / face side.
        $builder->entity('PlayerBody_Head')
            ->with(new Transform3D(
                position: Vec3::zero(),
                scale: new Vec3(0.12, 0.12, 0.12),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: 'player_skin'))
            ->with(new PlayerBody(
                part: PlayerBody::PART_HEAD,
                pivotOffset: new Vec3(0.0, $neckY, 0.0),
                restCenter: new Vec3(0.0, 0.12, -0.03),
                followsPitch: true,
                hideInFirstPerson: true,
            ));

        // --- HAIR (cap on top of head) ---
        $builder->entity('PlayerBody_Hair')
            ->with(new Transform3D(
                position: Vec3::zero(),
                scale: new Vec3(0.13, 0.04, 0.13),
            ))
            ->with(new MeshRenderer(meshId: 'box', materialId: 'player_hair'))
            ->with(new PlayerBody(
                part: PlayerBody::PART_HAIR,
                pivotOffset: new Vec3(0.0, $neckY, 0.0),
                restCenter: new Vec3(0.0, 0.24, -0.03),
                followsPitch: true,
                hideInFirstPerson: true,
            ));

        // --- EYES (small dark rectangles on the front of the head, -Z side) ---
        foreach ([['L', -0.04], ['R', 0.04]] as [$side, $dx]) {
            $builder->entity("PlayerBody_Eye_{$side}")
                ->with(new Transform3D(
                    position: Vec3::zero(),
                    scale: new Vec3(0.018, 0.012, 0.005),
                ))
                ->with(new MeshRenderer(meshId: 'box', materialId: 'player_eye'))
                ->with(new PlayerBody(
                    part: PlayerBody::PART_HAIR,
                    pivotOffset: new Vec3(0.0, $neckY, 0.0),
                    restCenter: new Vec3($dx, 0.12, -0.155),
                    followsPitch: true,
                    hideInFirstPerson: true,
                ));
        }

        // --- ARMS ---
        foreach ([['L', -0.23, PlayerBody::PART_ARM_LEFT, +1], ['R', 0.23, PlayerBody::PART_ARM_RIGHT, -1]] as [$side, $dx, $part, $sign]) {
            // Upper+lower arm as one segment.
            $builder->entity("PlayerBody_Arm_{$side}")
                ->with(new Transform3D(
                    position: Vec3::zero(),
                    scale: new Vec3(0.06, $armLen * 0.5, 0.06),
                ))
                ->with(new MeshRenderer(meshId: 'box', materialId: 'player_shirt'))
                ->with(new PlayerBody(
                    part: $part,
                    pivotOffset: new Vec3($dx, $shoulderY, 0.0),
                    restCenter: new Vec3(0.0, -$armLen * 0.5, 0.0),
                    swingAmp: 0.6,
                    swingSign: $sign,
                ));

            // Hand at the end of the arm.
            $handPart = $side === 'L' ? PlayerBody::PART_HAND_LEFT : PlayerBody::PART_HAND_RIGHT;
            $builder->entity("PlayerBody_Hand_{$side}")
                ->with(new Transform3D(
                    position: Vec3::zero(),
                    scale: new Vec3(0.055, 0.05, 0.07),
                ))
                ->with(new MeshRenderer(meshId: 'box', materialId: 'player_skin'))
                ->with(new PlayerBody(
                    part: $handPart,
                    pivotOffset: new Vec3($dx, $shoulderY, 0.0),
                    restCenter: new Vec3(0.0, -$armLen - 0.05, 0.0),
                    swingAmp: 0.6,
                    swingSign: $sign,
                ));
        }

        // --- LEGS ---
        foreach ([['L', -0.085, PlayerBody::PART_LEG_LEFT, -1], ['R', 0.085, PlayerBody::PART_LEG_RIGHT, +1]] as [$side, $dx, $part, $sign]) {
            $builder->entity("PlayerBody_Leg_{$side}")
                ->with(new Transform3D(
                    position: Vec3::zero(),
                    scale: new Vec3(0.07, $legLen * 0.5, 0.07),
                ))
                ->with(new MeshRenderer(meshId: 'box', materialId: 'player_pants'))
                ->with(new PlayerBody(
                    part: $part,
                    pivotOffset: new Vec3($dx, $hipY, 0.0),
                    restCenter: new Vec3(0.0, -$legLen * 0.5, 0.0),
                    swingAmp: 0.5,
                    swingSign: $sign,
                ));

            $footPart = $side === 'L' ? PlayerBody::PART_FOOT_LEFT : PlayerBody::PART_FOOT_RIGHT;
            $builder->entity("PlayerBody_Foot_{$side}")
                ->with(new Transform3D(
                    position: Vec3::zero(),
                    scale: new Vec3(0.075, 0.04, 0.12),
                ))
                ->with(new MeshRenderer(meshId: 'box', materialId: 'player_shoe'))
                ->with(new PlayerBody(
                    part: $footPart,
                    pivotOffset: new Vec3($dx, $hipY, 0.0),
                    // Toe points forward (−Z) so the foot extends past the shin toward the face.
                    restCenter: new Vec3(0.0, -$legLen - 0.01, -0.06),
                    swingAmp: 0.5,
                    swingSign: $sign,
                ));
        }
    }
}
