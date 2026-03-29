<?php

declare(strict_types=1);

namespace App\System;

use App\Audio\WavSynthesizer;
use App\Component\FirstPersonCamera;
use App\Component\Wind;
use PHPolygon\Audio\AudioChannel;
use PHPolygon\Audio\AudioManager;
use PHPolygon\Component\Transform3D;
use PHPolygon\Component\Weather;
use PHPolygon\ECS\AbstractSystem;
use PHPolygon\ECS\World;

class AmbientAudioSystem extends AbstractSystem
{
    private bool $initialized = false;

    // Ambient loop playback IDs
    private int $oceanPlaybackId = 0;
    private int $windPlaybackId = 0;
    private int $rainPlaybackId = 0;
    private int $stormWindPlaybackId = 0;
    private int $seagullPlaybackId = 0;

    // Weather audio state
    private bool $rainPlaying = false;
    private bool $stormWindPlaying = false;
    private float $thunderCooldown = 0.0;
    private float $lastLightningFlash = 0.0;

    // Ambient timers
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
        $this->updateRainAudio($world);
        $this->updateThunderAudio($world, $dt);
        $this->updateStormWindAudio($world);
        $this->updateFootsteps($world, $dt);
        $this->updateSeagulls($world, $dt);
    }

    private function generateAndLoadSounds(): void
    {
        $audioDir = $this->assetsPath . '/audio';

        $files = [
            'ocean_waves' => fn () => WavSynthesizer::generateOceanWaves(10.0),
            'wind_ambient' => fn () => WavSynthesizer::generateWind(10.0),
            'footstep_sand' => fn () => WavSynthesizer::generateFootstepSand(),
            'seagulls' => fn () => WavSynthesizer::generateSeagulls(8.0),
            'rain_loop' => fn () => WavSynthesizer::generateRain(10.0),
            'thunder' => fn () => WavSynthesizer::generateThunder(4.0),
            'wind_howl' => fn () => WavSynthesizer::generateWindHowl(10.0),
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
            'ocean_waves', AudioChannel::SFX, 0.4, true,
        );
        $this->windPlaybackId = $this->audio->playOnChannel(
            'wind_ambient', AudioChannel::SFX, 0.25, true,
        );
    }

    // =========================================================================
    // Wind — per-playback volume (doesn't affect other SFX)
    // =========================================================================

    private function updateWindVolume(World $world): void
    {
        foreach ($world->query(Wind::class) as $entity) {
            $wind = $world->getComponent($entity->id, Wind::class);
            $volume = 0.15 + $wind->intensity * 0.35;
            $this->audio->setPlaybackVolume($this->windPlaybackId, $volume);
            break;
        }
    }

    // =========================================================================
    // Rain audio — loop starts/stops with rain, volume scales with intensity
    // =========================================================================

    private function updateRainAudio(World $world): void
    {
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);

            if ($weather->rainIntensity > 0.05 && !$this->rainPlaying) {
                $this->rainPlaybackId = $this->audio->playOnChannel(
                    'rain_loop', AudioChannel::SFX, $weather->rainIntensity * 0.5, true,
                );
                $this->rainPlaying = true;
            } elseif ($weather->rainIntensity <= 0.05 && $this->rainPlaying) {
                $this->audio->stop($this->rainPlaybackId);
                $this->rainPlaying = false;
            } elseif ($this->rainPlaying) {
                $this->audio->setPlaybackVolume($this->rainPlaybackId, $weather->rainIntensity * 0.5);
            }
            break;
        }
    }

    // =========================================================================
    // Thunder — delayed after lightning flash (1.5-6s = distance simulation)
    // =========================================================================

    private function updateThunderAudio(World $world, float $dt): void
    {
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);

            // Detect new lightning flash (jump from low to high)
            if ($weather->lightningFlash > 0.8 && $this->lastLightningFlash < 0.3) {
                // Schedule thunder with random delay (distance)
                $this->thunderCooldown = 1.5 + sin($weather->lightningFlash * 47.3) * 2.0 + 1.5;
            }
            $this->lastLightningFlash = $weather->lightningFlash;

            // Play thunder when cooldown expires
            if ($this->thunderCooldown > 0.0) {
                $this->thunderCooldown -= $dt;
                if ($this->thunderCooldown <= 0.0) {
                    $volume = 0.3 + $weather->stormIntensity * 0.4;
                    $this->audio->playSfx('thunder', $volume);
                    $this->thunderCooldown = 0.0;
                }
            }
            break;
        }
    }

    // =========================================================================
    // Storm wind howl — loop at high storm intensity
    // =========================================================================

    private function updateStormWindAudio(World $world): void
    {
        foreach ($world->query(Weather::class) as $entity) {
            $weather = $entity->get(Weather::class);

            if ($weather->stormIntensity > 0.2 && !$this->stormWindPlaying) {
                $vol = ($weather->stormIntensity - 0.2) * 0.6;
                $this->stormWindPlaybackId = $this->audio->playOnChannel(
                    'wind_howl', AudioChannel::SFX, $vol, true,
                );
                $this->stormWindPlaying = true;
            } elseif ($weather->stormIntensity <= 0.2 && $this->stormWindPlaying) {
                $this->audio->stop($this->stormWindPlaybackId);
                $this->stormWindPlaying = false;
            } elseif ($this->stormWindPlaying) {
                $vol = ($weather->stormIntensity - 0.2) * 0.6;
                $this->audio->setPlaybackVolume($this->stormWindPlaybackId, $vol);
            }
            break;
        }
    }

    // =========================================================================
    // Footsteps
    // =========================================================================

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

    // =========================================================================
    // Seagulls — suppressed during storms
    // =========================================================================

    private function updateSeagulls(World $world, float $dt): void
    {
        // Seagulls go quiet during storms
        $stormIntensity = 0.0;
        foreach ($world->query(Weather::class) as $entity) {
            $stormIntensity = $entity->get(Weather::class)->stormIntensity;
            break;
        }

        $this->seagullTimer += $dt;

        if ($this->seagullTimer >= $this->seagullInterval && $stormIntensity < 0.3) {
            $elapsed = $this->seagullTimer; // capture before reset for variation seed
            $this->seagullTimer = 0.0;
            $this->seagullInterval = 6.0 + sin($elapsed * 0.3) * 4.0;
            $volume = 0.2 * (1.0 - $stormIntensity);
            $this->audio->playSfx('seagulls', $volume);
        }
    }
}
