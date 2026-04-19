<?php

declare(strict_types=1);

namespace App\System;

use App\Component\FirstPersonCamera;
use App\Component\PlayerBody;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

/**
 * Places every PlayerBody part relative to the Player each frame and drives
 * the walk and jump animation.
 *
 * For each part:
 *   pivotWorld = playerPos + yawRot(pivotOffset)
 *   bentCenter = yawRot(bendRot(restCenter))
 *   position   = pivotWorld + bentCenter
 *   rotation   = yaw * bend
 *
 * bendRot is a rotation about local X. It comes from:
 *   - the walk cycle (arms/legs swinging in contralateral phase)
 *   - the jump/air pose (legs tuck, arms lift)
 *   - the camera pitch (head nods)
 */
class PlayerBodySystem extends AbstractSystem
{
    private float $walkPhase = 0.0;
    private float $airTime = 0.0;
    private ?Vec3 $prevPos = null;

    public function update(World $world, float $dt): void
    {
        $playerPos = null;
        $yaw = 0.0;
        $pitch = 0.0;
        $horizSpeed = 0.0;
        $grounded = true;

        foreach ($world->query(Transform3D::class, FirstPersonCamera::class, CharacterController3D::class) as $entity) {
            $t = $world->getComponent($entity->id, Transform3D::class);
            $fpc = $world->getComponent($entity->id, FirstPersonCamera::class);
            $cc = $world->getComponent($entity->id, CharacterController3D::class);
            $playerPos = $t->position;
            $yaw = $fpc->yaw;
            $pitch = $fpc->pitch;
            $grounded = $cc->isGrounded;

            // Horizontal speed from position delta — FirstPersonCameraSystem
            // writes movement straight into transform.position, so velocity.xz
            // is always zero on land and can't be used for the walk cycle.
            if ($this->prevPos !== null && $dt > 0.0001) {
                $dxz = sqrt(
                    ($playerPos->x - $this->prevPos->x) ** 2
                    + ($playerPos->z - $this->prevPos->z) ** 2,
                );
                $horizSpeed = $dxz / $dt;
            }
            $this->prevPos = new Vec3($playerPos->x, $playerPos->y, $playerPos->z);
            break;
        }

        if ($playerPos === null) {
            return;
        }

        $speedFactor = min(1.0, $horizSpeed / 4.0);
        $isMoving = $horizSpeed > 0.3;

        // Walk phase advances faster at higher speed; still ticks slowly at
        // rest so the transition back from a paused stride is smooth.
        $this->walkPhase += $dt * ($isMoving && $grounded ? 3.0 + $speedFactor * 5.0 : 1.0);

        // Air time ramps up while airborne and decays on landing.
        if (!$grounded) {
            $this->airTime += $dt;
        } else {
            $this->airTime = max(0.0, $this->airTime - $dt * 4.0);
        }
        $airFactor = min(1.0, $this->airTime * 3.0);

        $yawQ = Quaternion::fromEuler(0.0, $yaw, 0.0);
        $xAxis = new Vec3(1.0, 0.0, 0.0);

        // Head pitch clamped so the head doesn't snap past the torso.
        $headPitch = max(-1.0, min(1.0, $pitch * 0.6));

        $legParts = [
            PlayerBody::PART_LEG_LEFT  => true,
            PlayerBody::PART_LEG_RIGHT => true,
            PlayerBody::PART_FOOT_LEFT => true,
            PlayerBody::PART_FOOT_RIGHT => true,
        ];
        $armParts = [
            PlayerBody::PART_ARM_LEFT   => true,
            PlayerBody::PART_ARM_RIGHT  => true,
            PlayerBody::PART_HAND_LEFT  => true,
            PlayerBody::PART_HAND_RIGHT => true,
        ];

        $tinyScale = new Vec3(0.0001, 0.0001, 0.0001);

        foreach ($world->query(Transform3D::class, PlayerBody::class) as $entity) {
            $t = $world->getComponent($entity->id, Transform3D::class);
            $body = $world->getComponent($entity->id, PlayerBody::class);

            // Capture the authored scale once so a future mirror render pass
            // (or a third-person toggle) can restore it.
            if ($body->baseScale === null) {
                $body->baseScale = new Vec3($t->scale->x, $t->scale->y, $t->scale->z);
            }

            $t->scale = $body->hideInFirstPerson ? $tinyScale : $body->baseScale;

            $bendAngle = 0.0;

            if ($body->followsPitch) {
                $bendAngle = $headPitch;
            } elseif ($body->swingSign !== 0 && $body->swingAmp > 0.0) {
                $walkBend = sin($this->walkPhase) * $body->swingAmp * (float) $body->swingSign
                          * $speedFactor * (1.0 - $airFactor);

                $airBend = 0.0;
                if (isset($legParts[$body->part])) {
                    $airBend = 0.55 * $airFactor;
                } elseif (isset($armParts[$body->part])) {
                    $airBend = -0.35 * $airFactor;
                }

                $bendAngle = $walkBend + $airBend;
            }

            $bendQ = Quaternion::fromAxisAngle($xAxis, $bendAngle);

            // World pivot = player position + yaw-rotated local pivot offset.
            $pivotWorld = $playerPos->add($yawQ->rotateVec3($body->pivotOffset));

            // Bent mesh centre, still in body-local frame, then yawed into world.
            $bentCenterLocal = $bendQ->rotateVec3($body->restCenter);
            $bentCenterWorld = $yawQ->rotateVec3($bentCenterLocal);

            $t->position = $pivotWorld->add($bentCenterWorld);
            $t->rotation = $yawQ->multiply($bendQ);
        }
    }
}
