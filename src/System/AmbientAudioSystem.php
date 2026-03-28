<?php

declare(strict_types=1);

namespace App\System;

use App\Audio\WavSynthesizer;
use App\Component\FirstPersonCamera;
use App\Component\Wind;
use PHPolygon\Audio\AudioManager;
use PHPolygon\Component\Transform3D;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

class AmbientAudioSystem extends AbstractSystem
{
    private bool $initialized = false;
    private int $oceanPlaybackId = 0;
    private int $windPlaybackId = 0;
    private int $seagullPlaybackId = 0;
    private float $seagullTimer = 0.0;
    private float $seagullInterval = 8.0;

    private float $footstepTimer = 0.0;
    private float $footstepInterval = 0.45;
    private float $lastPlayerX = 0.0;
    private float $lastPlayerZ = 0.0;

    public function __construct(
        private readonly AudioManager $audio,
        private readonly string $assetsPath,
    ) {}

    public function update(World $world, float $dt): void
    {
        if (!$this->initialized) {
            $this->generateAndLoadSounds();
            $this->startAmbientLoops();
            $this->initialized = true;
        }

        $this->updateWindVolume($world);
        $this->updateFootsteps($world, $dt);
        $this->updateSeagulls($dt);
    }

    private function generateAndLoadSounds(): void
    {
        $audioDir = $this->assetsPath . '/audio';

        $files = [
            'ocean_waves' => fn () => WavSynthesizer::generateOceanWaves(10.0),
            'wind_ambient' => fn () => WavSynthesizer::generateWind(10.0),
            'footstep_sand' => fn () => WavSynthesizer::generateFootstepSand(),
            'seagulls' => fn () => WavSynthesizer::generateSeagulls(8.0),
        ];

        foreach ($files as $id => $generator) {
            $path = "{$audioDir}/{$id}.wav";
            if (!file_exists($path)) {
                file_put_contents($path, $generator());
            }
            $this->audio->loadClip($id, $path);
        }
    }

    private function startAmbientLoops(): void
    {
        $this->oceanPlaybackId = $this->audio->playOnChannel(
            'ocean_waves',
            \PHPolygon\Audio\AudioChannel::SFX,
            0.4,
            true,
        );

        $this->windPlaybackId = $this->audio->playOnChannel(
            'wind_ambient',
            \PHPolygon\Audio\AudioChannel::SFX,
            0.25,
            true,
        );
    }

    private function updateWindVolume(World $world): void
    {
        foreach ($world->query(Wind::class) as $entity) {
            $wind = $world->getComponent($entity->id, Wind::class);
            $volume = 0.15 + $wind->intensity * 0.35;
            $this->audio->setChannelVolume(\PHPolygon\Audio\AudioChannel::SFX, $volume);
            break;
        }
    }

    private function updateFootsteps(World $world, float $dt): void
    {
        foreach ($world->query(Transform3D::class, FirstPersonCamera::class) as $entity) {
            $transform = $world->getComponent($entity->id, Transform3D::class);
            $px = $transform->position->x;
            $pz = $transform->position->z;

            $dx = $px - $this->lastPlayerX;
            $dz = $pz - $this->lastPlayerZ;
            $speed = sqrt($dx * $dx + $dz * $dz) / max($dt, 0.001);

            $this->lastPlayerX = $px;
            $this->lastPlayerZ = $pz;

            if ($speed > 1.0 && $transform->position->y < 2.0) {
                $this->footstepTimer += $dt;
                if ($this->footstepTimer >= $this->footstepInterval) {
                    $this->footstepTimer = 0.0;
                    $volume = 0.15 + min(0.25, $speed * 0.03);
                    $this->audio->playSfx('footstep_sand', $volume);
                }
            } else {
                $this->footstepTimer = $this->footstepInterval * 0.5;
            }
            break;
        }
    }

    private function updateSeagulls(float $dt): void
    {
        $this->seagullTimer += $dt;

        if ($this->seagullTimer >= $this->seagullInterval) {
            $this->seagullTimer = 0.0;
            $this->seagullInterval = 6.0 + sin($this->seagullTimer * 0.3) * 4.0;
            $this->audio->playSfx('seagulls', 0.2);
        }
    }
}
