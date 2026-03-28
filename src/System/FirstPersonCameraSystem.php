<?php

declare(strict_types=1);

namespace App\System;

use App\Component\FirstPersonCamera;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;
use PHPolygon\Math\Quaternion;
use PHPolygon\Math\Vec3;
use PHPolygon\Runtime\InputInterface;
use PHPolygon\Runtime\Window;

class FirstPersonCameraSystem extends AbstractSystem
{
    private bool $cursorCaptured = false;
    private float $lastMouseX = 0.0;
    private float $lastMouseY = 0.0;
    private bool $firstMouse = true;

    public function __construct(
        private readonly InputInterface $input,
        private readonly Window $window,
    ) {}

    public function update(World $world, float $dt): void
    {
        foreach ($world->query(Transform3D::class, FirstPersonCamera::class) as $entity) {
            $transform = $world->getComponent($entity->id, Transform3D::class);
            $camera = $world->getComponent($entity->id, FirstPersonCamera::class);

            // Toggle mouse capture with right click
            if ($this->input->isMouseButtonPressed(GLFW_MOUSE_BUTTON_RIGHT)) {
                $this->cursorCaptured = !$this->cursorCaptured;
                $this->firstMouse = true;
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

            // Flatten to XZ plane for movement
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

            $moveLen = sqrt($move->x ** 2 + $move->y ** 2 + $move->z ** 2);
            if ($moveLen > 0.001) {
                $move = new Vec3(
                    $move->x / $moveLen * $camera->moveSpeed * $dt,
                    0.0,
                    $move->z / $moveLen * $camera->moveSpeed * $dt,
                );
                $transform->position = $transform->position->add($move);
            }
        }
    }
}
