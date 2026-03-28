<?php

declare(strict_types=1);

namespace App\System;

use App\Component\FirstPersonCamera;
use App\Component\PlayerBody;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

class PlayerBodySystem extends AbstractSystem
{
    public function update(World $world, float $dt): void
    {
        // Find the player transform
        $playerTransform = null;
        $playerCamera = null;
        foreach ($world->query(Transform3D::class, FirstPersonCamera::class) as $entity) {
            $playerTransform = $world->getComponent($entity->id, Transform3D::class);
            $playerCamera = $world->getComponent($entity->id, FirstPersonCamera::class);
            break;
        }

        if ($playerTransform === null || $playerCamera === null) {
            return;
        }

        // Update all body parts to follow the player
        foreach ($world->query(Transform3D::class, PlayerBody::class) as $entity) {
            $bodyTransform = $world->getComponent($entity->id, Transform3D::class);

            // Body follows player position, offset down from camera
            // Feet at ground level, body rotates with yaw only (not pitch)
            $bodyTransform->position = new Vec3(
                $playerTransform->position->x,
                $playerTransform->position->y - 1.2,
                $playerTransform->position->z,
            );
            $bodyTransform->rotation = Quaternion::fromEuler(0.0, $playerCamera->yaw, 0.0);
        }
    }
}
