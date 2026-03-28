<?php

declare(strict_types=1);

namespace App\System;

use App\Component\FirstPersonCamera;
use PHPolygon\Component\CharacterController3D;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\Runtime\Window;

class FirstPersonCameraSystem extends AbstractSystem
{
    private const JUMP_FORCE = 6.0;
    private const WATER_SURFACE_Y = -0.25;
    private const WATER_ZONE_Z = -8.0;
    private const SWIM_SPEED_FACTOR = 0.5;
    private const BUOYANCY_FORCE = 12.0;

    private bool $cursorCaptured = false;
    private float $lastMouseX = 0.0;
    private float $lastMouseY = 0.0;
    private bool $firstMouse = true;
    private bool $initialCaptureDone = false;

    public function __construct(
        private readonly InputInterface $input,
        private readonly Window $window,
    ) {}

    public function update(World $world, float $dt): void
    {
        foreach ($world->query(Transform3D::class, FirstPersonCamera::class) as $entity) {
            $transform = $world->getComponent($entity->id, Transform3D::class);
            $camera = $world->getComponent($entity->id, FirstPersonCamera::class);

            // Auto-capture mouse on first frame
            if (!$this->initialCaptureDone) {
                $this->cursorCaptured = true;
                $this->firstMouse = true;
                $this->initialCaptureDone = true;
                $this->window->setCursorDisabled();
            }

            // Escape releases mouse, left click re-captures
            if ($this->input->isKeyPressed(GLFW_KEY_ESCAPE)) {
                $this->cursorCaptured = false;
                $this->firstMouse = true;
                $this->window->setCursorNormal();
            }
            if (!$this->cursorCaptured && $this->input->isMouseButtonPressed(GLFW_MOUSE_BUTTON_LEFT)) {
                $this->cursorCaptured = true;
                $this->firstMouse = true;
                $this->window->setCursorDisabled();
            }

            // Mouse look
            if ($this->cursorCaptured) {
                $mouseX = $this->input->getMouseX();
                $mouseY = $this->input->getMouseY();

                if ($this->firstMouse) {
                    $this->lastMouseX = $mouseX;
                    $this->lastMouseY = $mouseY;
                    $this->firstMouse = false;
                }

                $deltaX = $mouseX - $this->lastMouseX;
                $deltaY = $mouseY - $this->lastMouseY;
                $this->lastMouseX = $mouseX;
                $this->lastMouseY = $mouseY;

                $camera->yaw -= $deltaX * $camera->sensitivity;
                $camera->pitch -= $deltaY * $camera->sensitivity;
                $camera->pitch = max(-M_PI / 2 * 0.95, min(M_PI / 2 * 0.95, $camera->pitch));
            }

            // Build rotation from yaw/pitch
            $rotation = Quaternion::fromEuler($camera->pitch, $camera->yaw, 0.0);
            $transform->rotation = $rotation;

            // WASD movement relative to camera direction
            $forward = $rotation->rotateVec3(new Vec3(0.0, 0.0, -1.0));
            $right = $rotation->rotateVec3(new Vec3(1.0, 0.0, 0.0));

            // Check if player is in water (needed for movement mode)
            $isInWater = $transform->position->y < self::WATER_SURFACE_Y
                && $transform->position->z < self::WATER_ZONE_Z;

            if ($isInWater) {
                // Swimming: full 3D movement in look direction (allows diving)
                $fwdLen = sqrt($forward->x ** 2 + $forward->y ** 2 + $forward->z ** 2);
                if ($fwdLen > 0.001) {
                    $forward = new Vec3($forward->x / $fwdLen, $forward->y / $fwdLen, $forward->z / $fwdLen);
                }
                $right = new Vec3($right->x, 0.0, $right->z);
                $rLen = sqrt($right->x ** 2 + $right->z ** 2);
                if ($rLen > 0.001) {
                    $right = new Vec3($right->x / $rLen, 0.0, $right->z / $rLen);
                }
            } else {
                // Land: flatten to XZ plane
                $forward = new Vec3($forward->x, 0.0, $forward->z);
                $fwdLen = sqrt($forward->x ** 2 + $forward->z ** 2);
                if ($fwdLen > 0.001) {
                    $forward = new Vec3($forward->x / $fwdLen, 0.0, $forward->z / $fwdLen);
                }
                $right = new Vec3($right->x, 0.0, $right->z);
                $rLen = sqrt($right->x ** 2 + $right->z ** 2);
                if ($rLen > 0.001) {
                    $right = new Vec3($right->x / $rLen, 0.0, $right->z / $rLen);
                }
            }

            $move = Vec3::zero();
            if ($this->input->isKeyDown(GLFW_KEY_W)) {
                $move = $move->add($forward);
            }
            if ($this->input->isKeyDown(GLFW_KEY_S)) {
                $move = $move->sub($forward);
            }
            if ($this->input->isKeyDown(GLFW_KEY_D)) {
                $move = $move->add($right);
            }
            if ($this->input->isKeyDown(GLFW_KEY_A)) {
                $move = $move->sub($right);
            }

            $speedFactor = $isInWater ? self::SWIM_SPEED_FACTOR : 1.0;

            $moveLen = sqrt($move->x ** 2 + $move->y ** 2 + $move->z ** 2);
            if ($moveLen > 0.001) {
                $speed = $camera->moveSpeed * $speedFactor * $dt;
                $move = new Vec3(
                    $move->x / $moveLen * $speed,
                    $isInWater ? $move->y / $moveLen * $speed : 0.0,
                    $move->z / $moveLen * $speed,
                );
                $transform->position = $transform->position->add($move);
            }

            $controller = $world->tryGetComponent($entity->id, CharacterController3D::class);
            if ($controller !== null) {
                if ($isInWater) {
                    // Swimming: counteract gravity, buoyancy toward surface, Space rises
                    $depthBelowSurface = self::WATER_SURFACE_Y - $transform->position->y;

                    // Base buoyancy: counteract gravity (9.81) + push toward surface
                    $buoyancy = 9.81 + $depthBelowSurface * self::BUOYANCY_FORCE;

                    if ($this->input->isKeyDown(GLFW_KEY_SPACE)) {
                        $buoyancy += self::BUOYANCY_FORCE;
                    }
                    if ($this->input->isKeyDown(GLFW_KEY_LEFT_SHIFT)) {
                        $buoyancy -= self::BUOYANCY_FORCE * 1.5; // dive down
                    }

                    // Heavy water drag on all axes
                    $controller->velocity = new Vec3(
                        $controller->velocity->x * 0.92,
                        $controller->velocity->y * 0.88 + $buoyancy * $dt,
                        $controller->velocity->z * 0.92,
                    );
                } else {
                    // Land: normal jump
                    if ($controller->isGrounded && $this->input->isKeyPressed(GLFW_KEY_SPACE)) {
                        $controller->velocity = new Vec3(
                            $controller->velocity->x,
                            self::JUMP_FORCE,
                            $controller->velocity->z,
                        );
                    }
                }
            }
        }
    }
}
