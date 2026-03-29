<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Vec3;

/**
 * Sphere Streaming Renderer — renders a full 360° sphere around the player.
 *
 * Core insight: Camera rotation is instant, player movement is slow and predictable.
 * By pre-rendering everything in a sphere around the player position, rotation is
 * free — the content is already in VRAM. Regeneration only happens on player MOVEMENT.
 *
 * This reduces the streaming problem from 2D (position + rotation) to 1D (position only).
 *
 * Architecture:
 * - World is divided into sectors (angular slices of the sphere)
 * - All sectors within render radius are always loaded in VRAM
 * - When player moves, new sectors ahead of movement are generated
 * - Old sectors behind are recycled (ring buffer pattern)
 * - No frustum culling needed — everything visible is already rendered
 * - No LOD popping — you can never look at unloaded geometry
 *
 * VRAM trade-off: More geometry in memory, but procedural content is small.
 * A 200m radius sphere at medium density ≈ 50-100MB VRAM — trivial for modern GPUs.
 */
class SphereStreamingRenderer
{
    /** Sectors around the player (angular grid) */
    private array $sectors = [];

    /** Current player position (last generation center) */
    private Vec3 $lastGenPosition;

    /** Distance threshold to trigger regeneration */
    private float $regenThreshold;

    /** Whether initial generation has happened */
    private bool $initialized = false;

    /** Sector generation queue (prioritized by movement direction) */
    private array $genQueue = [];

    /** Sectors per ring (horizontal slices of sphere) */
    private int $horizontalSectors;

    /** Vertical bands (above/below horizon) */
    private int $verticalBands;

    /** Maximum generation calls per frame (budget) */
    private int $maxGensPerFrame;

    private int $debugCounter = 0;

    public function __construct(
        private readonly float $renderRadius = 200.0,
        int $horizontalSectors = 24,
        int $verticalBands = 6,
        float $regenThreshold = 5.0,
        int $maxGensPerFrame = 4,
    ) {
        $this->horizontalSectors = $horizontalSectors;
        $this->verticalBands = $verticalBands;
        $this->regenThreshold = $regenThreshold;
        $this->maxGensPerFrame = $maxGensPerFrame;
        $this->lastGenPosition = Vec3::zero();
    }

    /**
     * Initialize the full sphere around the starting position.
     * Call once at scene start — generates ALL sectors.
     *
     * @param Vec3 $playerPos Current player position
     * @param callable(SectorInfo): void $generateSector Called for each sector to generate
     */
    public function initializeSphere(Vec3 $playerPos, callable $generateSector): void
    {
        $this->lastGenPosition = clone $playerPos;
        $this->sectors = [];

        for ($v = 0; $v < $this->verticalBands; $v++) {
            for ($h = 0; $h < $this->horizontalSectors; $h++) {
                $sector = $this->computeSectorInfo($playerPos, $h, $v);
                $sector->loaded = true;
                $generateSector($sector);
                $this->sectors[$this->sectorKey($h, $v)] = $sector;
            }
        }

        $this->initialized = true;
        fprintf(STDERR, "[SphereStream] Initialized: %d sectors (%.0fm radius)\n",
            count($this->sectors), $this->renderRadius);
    }

    /**
     * Update streaming based on player movement.
     * Only regenerates sectors when player has moved beyond threshold.
     * Prioritizes sectors in the movement direction.
     *
     * @param Vec3 $playerPos Current player position
     * @param Vec3 $playerVelocity Current movement velocity (for prediction)
     * @param callable(SectorInfo): void $generateSector Generate a new sector
     * @param callable(SectorInfo): void $recycleSector Free a sector's VRAM
     * @return int Number of sectors regenerated this frame
     */
    public function update(
        Vec3 $playerPos,
        Vec3 $playerVelocity,
        callable $generateSector,
        callable $recycleSector,
    ): int {
        if (!$this->initialized) return 0;

        $this->debugCounter++;

        // Check if player has moved enough to trigger regeneration
        $dx = $playerPos->x - $this->lastGenPosition->x;
        $dz = $playerPos->z - $this->lastGenPosition->z;
        $moveDistance = sqrt($dx * $dx + $dz * $dz);

        if ($moveDistance < $this->regenThreshold && empty($this->genQueue)) {
            return 0;
        }

        // Movement direction (for prioritization)
        $moveDirX = $moveDistance > 0.01 ? $dx / $moveDistance : 0.0;
        $moveDirZ = $moveDistance > 0.01 ? $dz / $moveDistance : 0.0;

        // Predict where player will be in 1 second
        $predictPos = new Vec3(
            $playerPos->x + $playerVelocity->x * 1.0,
            $playerPos->y,
            $playerPos->z + $playerVelocity->z * 1.0,
        );

        // If moved beyond threshold, queue full regeneration centered on new position
        if ($moveDistance >= $this->regenThreshold) {
            $this->lastGenPosition = clone $playerPos;
            $this->genQueue = [];

            // Identify sectors that need regeneration
            $newSectors = [];
            for ($v = 0; $v < $this->verticalBands; $v++) {
                for ($h = 0; $h < $this->horizontalSectors; $h++) {
                    $sector = $this->computeSectorInfo($playerPos, $h, $v);
                    $key = $this->sectorKey($h, $v);

                    // Check if this sector's world position has changed significantly
                    $existing = $this->sectors[$key] ?? null;
                    if ($existing !== null && $this->sectorsOverlap($existing, $sector)) {
                        // Sector still valid — keep it
                        $sector->loaded = true;
                        $newSectors[$key] = $sector;
                    } else {
                        // Sector needs regeneration — queue it
                        $sector->loaded = false;

                        // Priority: sectors in movement direction get generated first
                        $sectorDirX = $sector->centerX - $playerPos->x;
                        $sectorDirZ = $sector->centerZ - $playerPos->z;
                        $sectorDist = sqrt($sectorDirX * $sectorDirX + $sectorDirZ * $sectorDirZ);
                        if ($sectorDist > 0.01) {
                            $sectorDirX /= $sectorDist;
                            $sectorDirZ /= $sectorDist;
                        }
                        $dotMovement = $sectorDirX * $moveDirX + $sectorDirZ * $moveDirZ;
                        $sector->priority = $dotMovement; // Higher = more aligned with movement

                        $newSectors[$key] = $sector;
                        $this->genQueue[] = $sector;
                    }

                    // Recycle old sector if displaced
                    if ($existing !== null && !$this->sectorsOverlap($existing, $sector)) {
                        $recycleSector($existing);
                    }
                }
            }

            // Sort queue: movement-direction sectors first
            usort($this->genQueue, fn($a, $b) => $b->priority <=> $a->priority);

            $this->sectors = $newSectors;
        }

        // Process generation queue (budgeted per frame)
        $generated = 0;
        while ($generated < $this->maxGensPerFrame && !empty($this->genQueue)) {
            $sector = array_shift($this->genQueue);
            $generateSector($sector);
            $sector->loaded = true;
            $key = $this->sectorKey($sector->hIndex, $sector->vIndex);
            $this->sectors[$key] = $sector;
            $generated++;
        }

        if ($this->debugCounter % 120 === 1) {
            $loadedCount = 0;
            foreach ($this->sectors as $s) {
                if ($s->loaded) $loadedCount++;
            }
            fprintf(STDERR, "[SphereStream] loaded=%d/%d queue=%d moved=%.1f\n",
                $loadedCount, count($this->sectors), count($this->genQueue), $moveDistance);
        }

        return $generated;
    }

    /**
     * Check if all sectors are loaded (no pending generation).
     */
    public function isFullyLoaded(): bool
    {
        return empty($this->genQueue);
    }

    /**
     * Get the render radius.
     */
    public function getRenderRadius(): float
    {
        return $this->renderRadius;
    }

    // =========================================================================
    // Sector computation
    // =========================================================================

    private function computeSectorInfo(Vec3 $center, int $h, int $v): SectorInfo
    {
        $hAngle = ($h / $this->horizontalSectors) * M_PI * 2.0;
        $hAngleNext = (($h + 1) / $this->horizontalSectors) * M_PI * 2.0;

        // Vertical: band 0 = ground level, higher bands = elevated sectors
        $vFrac = $v / $this->verticalBands;
        $vFracNext = ($v + 1) / $this->verticalBands;
        $innerRadius = $this->renderRadius * $vFrac;
        $outerRadius = $this->renderRadius * $vFracNext;

        // Sector center in world space (horizontal ring)
        $midAngle = ($hAngle + $hAngleNext) * 0.5;
        $midRadius = ($innerRadius + $outerRadius) * 0.5;

        $sector = new SectorInfo();
        $sector->hIndex = $h;
        $sector->vIndex = $v;
        $sector->angleStart = $hAngle;
        $sector->angleEnd = $hAngleNext;
        $sector->innerRadius = $innerRadius;
        $sector->outerRadius = $outerRadius;
        $sector->centerX = $center->x + cos($midAngle) * $midRadius;
        $sector->centerZ = $center->z + sin($midAngle) * $midRadius;
        $sector->worldMinX = min(
            $center->x + cos($hAngle) * $innerRadius,
            $center->x + cos($hAngleNext) * $innerRadius,
            $center->x + cos($hAngle) * $outerRadius,
            $center->x + cos($hAngleNext) * $outerRadius,
        );
        $sector->worldMaxX = max(
            $center->x + cos($hAngle) * $innerRadius,
            $center->x + cos($hAngleNext) * $innerRadius,
            $center->x + cos($hAngle) * $outerRadius,
            $center->x + cos($hAngleNext) * $outerRadius,
        );
        $sector->worldMinZ = min(
            $center->z + sin($hAngle) * $innerRadius,
            $center->z + sin($hAngleNext) * $innerRadius,
            $center->z + sin($hAngle) * $outerRadius,
            $center->z + sin($hAngleNext) * $outerRadius,
        );
        $sector->worldMaxZ = max(
            $center->z + sin($hAngle) * $innerRadius,
            $center->z + sin($hAngleNext) * $innerRadius,
            $center->z + sin($hAngle) * $outerRadius,
            $center->z + sin($hAngleNext) * $outerRadius,
        );

        return $sector;
    }

    private function sectorsOverlap(SectorInfo $a, SectorInfo $b): bool
    {
        // Two sectors overlap if their world-space centers are within half a sector width
        $dx = $a->centerX - $b->centerX;
        $dz = $a->centerZ - $b->centerZ;
        $dist = sqrt($dx * $dx + $dz * $dz);
        $sectorWidth = $this->renderRadius / $this->verticalBands;
        return $dist < $sectorWidth * 0.5;
    }

    private function sectorKey(int $h, int $v): string
    {
        return "{$h}_{$v}";
    }
}

/**
 * Information about a single sector in the render sphere.
 */
class SectorInfo
{
    public int $hIndex = 0;
    public int $vIndex = 0;
    public float $angleStart = 0.0;
    public float $angleEnd = 0.0;
    public float $innerRadius = 0.0;
    public float $outerRadius = 0.0;
    public float $centerX = 0.0;
    public float $centerZ = 0.0;
    public float $worldMinX = 0.0;
    public float $worldMaxX = 0.0;
    public float $worldMinZ = 0.0;
    public float $worldMaxZ = 0.0;
    public bool $loaded = false;
    public float $priority = 0.0;
}
