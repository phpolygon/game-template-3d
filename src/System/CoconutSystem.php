<?php

declare(strict_types=1);

namespace App\System;

use App\Component\Coconut;
use App\Component\Wind;
use PHPolygon\Component\BodyType;
use PHPolygon\Component\BoxCollider3D;
use PHPolygon\Component\RigidBody3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Vec3;

/**
 * Monitors wind stress on coconuts and detaches them when sustained storm wind hits.
 * Once detached, adds RigidBody3D + BoxCollider3D so they fall and roll with wind.
 */
class CoconutSystem extends AbstractSystem
{
    private int $debugCounter = 0;

    public function update(World $world, float $dt): void
    {
        $this->debugCounter++;

        $windIntensity = 0.5;
        $windDirX = 1.0;
        $windDirZ = 0.0;
        foreach ($world->query(Wind::class) as $entity) {
            $wind = $world->getComponent($entity->id, Wind::class);
            $windIntensity = $wind->intensity;
            $windDirX = $wind->direction->x;
            $windDirZ = $wind->direction->z;
            break;
        }

        $attachedCount = 0;
        $detachedCount = 0;

        foreach ($world->query(Transform3D::class, Coconut::class) as $entity) {
            $coconut = $world->getComponent($entity->id, Coconut::class);
            $transform = $world->getComponent($entity->id, Transform3D::class);

            if ($coconut->detached) {
                $detachedCount++;

                // Apply wind force to fallen coconuts (via RigidBody3D)
                $rb = $world->getComponent($entity->id, RigidBody3D::class);
                if ($rb !== null && !$rb->isSleeping && $windIntensity > 0.5) {
                    $windForce = ($windIntensity - 0.5) * 0.3;
                    $rb->velocity = new Vec3(
                        $rb->velocity->x + $windDirX * $windForce * $dt,
                        $rb->velocity->y,
                        $rb->velocity->z + $windDirZ * $windForce * $dt,
                    );
                }
                continue;
            }

            $attachedCount++;

            // Store original position on first frame
            if ($coconut->attachedPosition === null) {
                $coconut->attachedPosition = clone $transform->position;
            }

            // Sway coconut while attached (gentle bob)
            $t = $this->debugCounter * $dt + $coconut->detachThreshold * 10.0; // phase from threshold for variation
            $swayX = sin($t * 1.5) * $windIntensity * 0.05;
            $swayZ = cos($t * 1.2) * $windIntensity * 0.03;
            $transform->position = new Vec3(
                $coconut->attachedPosition->x + $swayX,
                $coconut->attachedPosition->y + sin($t * 2.0) * $windIntensity * 0.02,
                $coconut->attachedPosition->z + $swayZ,
            );

            // Check wind stress
            if ($windIntensity >= $coconut->detachThreshold) {
                $coconut->stressTime += $dt;

                if ($coconut->stressTime >= $coconut->detachDelay) {
                    // DETACH!
                    $coconut->detached = true;

                    // Add physics components for free-fall
                    $world->attachComponent($entity->id, new RigidBody3D(
                        bodyType: BodyType::Dynamic,
                        mass: 1.5,
                        gravityScale: 1.0,
                        linearDamping: 0.08,
                        restitution: 0.35,
                        friction: 0.6,
                    ));
                    $world->attachComponent($entity->id, new BoxCollider3D(
                        size: new Vec3(0.22, 0.26, 0.22),
                        isStatic: false,
                    ));

                    // Initial velocity: wind direction + slight random scatter
                    $rb = $world->getComponent($entity->id, RigidBody3D::class);
                    if ($rb !== null) {
                        $launchSpeed = $windIntensity * 0.8;
                        $rb->velocity = new Vec3(
                            $windDirX * $launchSpeed + sin($coconut->detachThreshold * 37.0) * 0.5,
                            -0.5, // slight downward
                            $windDirZ * $launchSpeed + cos($coconut->detachThreshold * 23.0) * 0.5,
                        );
                    }

                    fprintf(STDERR, "[Coconut] DETACHED at (%.1f, %.1f, %.1f) wind=%.2f\n",
                        $transform->position->x, $transform->position->y, $transform->position->z, $windIntensity);
                }
            } else {
                // Wind dropped below threshold — stress decays
                $coconut->stressTime = max(0.0, $coconut->stressTime - $dt * 0.5);
            }
        }

        if ($this->debugCounter % 120 === 1) {
            fprintf(STDERR, "[Coconut] attached=%d detached=%d wind=%.2f\n",
                $attachedCount, $detachedCount, $windIntensity);
        }
    }
}
