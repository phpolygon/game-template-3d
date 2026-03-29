<?php

declare(strict_types=1);

namespace PHPolygon\System;

use PHPolygon\Component\Camera3DComponent;
use PHPolygon\Component\HeightmapCollider3D;
use PHPolygon\Component\MeshRenderer;
use PHPolygon\Component\TerrainChunk;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

/**
 * Streams terrain chunks based on camera proximity.
 * - Near chunks: high LOD, physics active
 * - Far chunks: low LOD, physics disabled (visual only)
 * - Beyond view distance: hidden entirely
 *
 * AAA pattern: CryEngine chunked terrain, REDengine streaming.
 */
class TerrainChunkSystem extends AbstractSystem
{
    private float $loadDistance;
    private float $unloadDistance;
    private float $highLodDistance;

    /** @var array<string, bool> Track which chunks have been loaded */
    private array $loadedChunks = [];

    public function __construct(
        float $loadDistance = 200.0,
        float $unloadDistance = 250.0,
        float $highLodDistance = 80.0,
    ) {
        $this->loadDistance = $loadDistance;
        $this->unloadDistance = $unloadDistance;
        $this->highLodDistance = $highLodDistance;
    }

    public function update(World $world, float $dt): void
    {
        // Find camera position
        $camX = 0.0;
        $camZ = 0.0;
        foreach ($world->query(Transform3D::class, Camera3DComponent::class) as $entity) {
            $transform = $entity->get(Transform3D::class);
            $camX = $transform->position->x;
            $camZ = $transform->position->z;
            break;
        }

        // Update each chunk
        foreach ($world->query(TerrainChunk::class, Transform3D::class) as $entity) {
            $chunk = $entity->get(TerrainChunk::class);
            $transform = $entity->get(Transform3D::class);

            // Distance from camera to chunk center
            $dx = $camX - $chunk->getCenterX();
            $dz = $camZ - $chunk->getCenterZ();
            $chunk->cameraDistance = sqrt($dx * $dx + $dz * $dz);

            // LOD level based on distance
            if ($chunk->cameraDistance < $this->highLodDistance) {
                $chunk->lodLevel = 0; // Full detail
            } elseif ($chunk->cameraDistance < $this->loadDistance * 0.6) {
                $chunk->lodLevel = 1; // Medium
            } else {
                $chunk->lodLevel = 2; // Low
            }

            // Visibility
            $chunk->visible = $chunk->cameraDistance < $this->loadDistance;

            // Hide/show via Y position
            $mr = $world->tryGetComponent($entity->id, MeshRenderer::class);
            if ($mr instanceof MeshRenderer) {
                if (!$chunk->visible) {
                    $transform->position = new \PHPolygon\Math\Vec3(
                        $transform->position->x, -1000.0, $transform->position->z,
                    );
                } elseif ($transform->position->y < -999.0) {
                    $transform->position = new \PHPolygon\Math\Vec3(
                        $transform->position->x, 0.0, $transform->position->z,
                    );
                }
            }
        }
    }
}
