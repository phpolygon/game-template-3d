<?php

declare(strict_types=1);

namespace App\System;

use App\Component\FirstPersonCamera;
use App\Component\FootprintMark;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;

class FootprintSystem extends AbstractSystem
{
    private float $lastFootprintX = PHP_FLOAT_MAX;
    private float $lastFootprintZ = PHP_FLOAT_MAX;
    private float $footprintInterval = 1.2;
    private bool $leftFoot = true;

    public function update(World $world, float $dt): void
    {
        $this->fadeAndCleanup($world, $dt);
        $this->tryCreateFootprint($world);
    }

    private function fadeAndCleanup(World $world, float $dt): void
    {
        foreach ($world->query(Transform3D::class, FootprintMark::class) as $entity) {
            $mark = $world->getComponent($entity->id, FootprintMark::class);
            $mark->age += $dt;

            if ($mark->age >= $mark->maxLifetime) {
                $world->destroyEntity($entity->id);
                continue;
            }

            $fade = 1.0 - ($mark->age / $mark->maxLifetime);
            $transform = $world->getComponent($entity->id, Transform3D::class);
            $transform->scale = new Vec3(
                0.15 * $fade + 0.05,
                1.0,
                0.25 * $fade + 0.05,
            );
        }
    }

    private function tryCreateFootprint(World $world): void
    {
        foreach ($world->query(Transform3D::class, FirstPersonCamera::class) as $entity) {
            $playerT = $world->getComponent($entity->id, Transform3D::class);
            $camera = $world->getComponent($entity->id, FirstPersonCamera::class);
            $px = $playerT->position->x;
            $pz = $playerT->position->z;

            if ($pz < -7.0 || $playerT->position->y > 0.5) {
                return;
            }

            $dx = $px - $this->lastFootprintX;
            $dz = $pz - $this->lastFootprintZ;
            $dist = sqrt($dx * $dx + $dz * $dz);

            if ($dist < $this->footprintInterval) {
                return;
            }

            $this->lastFootprintX = $px;
            $this->lastFootprintZ = $pz;

            $sideOffset = $this->leftFoot ? -0.15 : 0.15;
            $this->leftFoot = !$this->leftFoot;

            $right = Quaternion::fromEuler(0.0, $camera->yaw, 0.0)
                ->rotateVec3(new Vec3(1.0, 0.0, 0.0));

            $fpEntity = $world->createEntity();
            $fpEntity->attach(new Transform3D(
                position: new Vec3(
                    $px + $right->x * $sideOffset,
                    0.005,
                    $pz + $right->z * $sideOffset,
                ),
                rotation: Quaternion::fromEuler(0.0, $camera->yaw, 0.0),
                scale: new Vec3(0.15, 1.0, 0.25),
            ));
            $fpEntity->attach(new MeshRenderer(meshId: 'plane', materialId: 'footprint'));
            $fpEntity->attach(new FootprintMark()); // @phpstan-ignore-line
        }
    }
}
