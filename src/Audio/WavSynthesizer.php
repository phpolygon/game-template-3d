<?php

declare(strict_types=1);

namespace App\Audio;

class WavSynthesizer
{
    private const SAMPLE_RATE = 44100;
    private const BITS_PER_SAMPLE = 16;

    public static function generateOceanWaves(float $duration = 10.0): string
    {
        $samples = self::sampleCount($duration);
        $data = '';

        for ($i = 0; $i < $samples; $i++) {
            $t = $i / self::SAMPLE_RATE;

            // Multiple layered wave sounds
            $wave1 = sin(2.0 * M_PI * 0.08 * $t); // slow swell
            $wave2 = sin(2.0 * M_PI * 0.13 * $t + 0.7);
            $wave3 = sin(2.0 * M_PI * 0.21 * $t + 2.1);
            $envelope = 0.3 + 0.25 * $wave1 + 0.2 * $wave2 + 0.1 * $wave3;

            // Filtered noise for water texture
            $noise = self::noise($i);
            $filtered = self::lowPassSample($noise, $i, 800.0 + 400.0 * $wave1);

            // Surf crash layer
            $crashEnv = max(0.0, sin(2.0 * M_PI * 0.06 * $t));
            $crashEnv = $crashEnv ** 3;
            $crashNoise = self::noise($i + 99999) * $crashEnv * 0.4;

            // Deep rumble
            $rumble = sin(2.0 * M_PI * 45.0 * $t + sin(2.0 * M_PI * 0.1 * $t) * 3.0) * 0.08;

            $sample = ($filtered * $envelope * 0.5 + $crashNoise + $rumble) * 0.6;
            $data .= self::packSample($sample);
        }

        return self::wrapWav($data, 1);
    }

    public static function generateWind(float $duration = 10.0): string
    {
        $samples = self::sampleCount($duration);
        $data = '';
        $prevFiltered = 0.0;

        for ($i = 0; $i < $samples; $i++) {
            $t = $i / self::SAMPLE_RATE;

            // Gusting envelope
            $gust1 = sin(2.0 * M_PI * 0.15 * $t) * 0.4;
            $gust2 = sin(2.0 * M_PI * 0.07 * $t + 1.3) * 0.3;
            $gust3 = sin(2.0 * M_PI * 0.31 * $t + 0.5) * 0.15;
            $envelope = 0.3 + $gust1 + $gust2 + $gust3;
            $envelope = max(0.05, min(1.0, $envelope));

            // Wind is shaped noise
            $noise = self::noise($i + 50000);

            // Variable cutoff based on gust strength
            $cutoff = 300.0 + $envelope * 600.0;
            $rc = 1.0 / (2.0 * M_PI * $cutoff);
            $dtSample = 1.0 / self::SAMPLE_RATE;
            $alpha = $dtSample / ($rc + $dtSample);
            $prevFiltered = $prevFiltered + $alpha * ($noise - $prevFiltered);

            // Whistling overtone during strong gusts
            $whistle = 0.0;
            if ($envelope > 0.6) {
                $whistleStr = ($envelope - 0.6) * 2.5;
                $whistle = sin(2.0 * M_PI * (1200.0 + sin($t * 3.0) * 200.0) * $t) * $whistleStr * 0.06;
            }

            $sample = ($prevFiltered * $envelope + $whistle) * 0.5;
            $data .= self::packSample($sample);
        }

        return self::wrapWav($data, 1);
    }

    public static function generateFootstepSand(): string
    {
        // Short footstep sound (~0.25s)
        $duration = 0.25;
        $samples = self::sampleCount($duration);
        $data = '';

        for ($i = 0; $i < $samples; $i++) {
            $t = $i / self::SAMPLE_RATE;

            // Quick attack, medium decay envelope
            $env = exp(-$t * 12.0) * min(1.0, $t * 200.0);

            // Sand is crunchy filtered noise
            $noise = self::noise($i + 77777);
            $crunch = self::noise($i * 3 + 33333) * 0.5;

            // Thud component
            $thud = sin(2.0 * M_PI * 60.0 * $t) * exp(-$t * 20.0) * 0.3;

            $sample = ($noise * 0.6 + $crunch * 0.3 + $thud) * $env * 0.7;
            $data .= self::packSample($sample);
        }

        return self::wrapWav($data, 1);
    }

    public static function generateSeagulls(float $duration = 8.0): string
    {
        $samples = self::sampleCount($duration);
        $data = '';

        // Pre-compute call timings (3-5 calls within the duration)
        $callCount = 4;
        $calls = [];
        for ($c = 0; $c < $callCount; $c++) {
            $calls[] = [
                'start' => 0.5 + $c * ($duration / $callCount) + sin($c * 2.7) * 0.3,
                'duration' => 0.4 + sin($c * 1.3) * 0.15,
                'pitch' => 2200.0 + sin($c * 3.1) * 400.0,
            ];
        }

        for ($i = 0; $i < $samples; $i++) {
            $t = $i / self::SAMPLE_RATE;
            $sample = 0.0;

            foreach ($calls as $call) {
                $ct = $t - $call['start'];
                if ($ct < 0.0 || $ct > $call['duration']) {
                    continue;
                }

                $env = sin(M_PI * $ct / $call['duration']);
                $env *= $env;

                // Seagull call: frequency sweep with harmonics
                $freq = $call['pitch'] + sin($ct * 15.0) * 300.0 - $ct * 800.0;
                $tone = sin(2.0 * M_PI * $freq * $ct) * 0.5;
                $tone += sin(2.0 * M_PI * $freq * 1.5 * $ct) * 0.2;
                $tone += sin(2.0 * M_PI * $freq * 2.0 * $ct) * 0.1;

                $sample += $tone * $env * 0.35;
            }

            $data .= self::packSample($sample);
        }

        return self::wrapWav($data, 1);
    }

    private static function sampleCount(float $duration): int
    {
        return (int) (self::SAMPLE_RATE * $duration);
    }

    private static function noise(int $index): float
    {
        // Simple deterministic pseudo-random noise
        $x = ($index * 1103515245 + 12345) & 0x7fffffff;
        return ($x / 0x7fffffff) * 2.0 - 1.0;
    }

    private static function lowPassSample(float $input, int $index, float $cutoff): float
    {
        static $prev = 0.0;
        $rc = 1.0 / (2.0 * M_PI * $cutoff);
        $dt = 1.0 / self::SAMPLE_RATE;
        $alpha = $dt / ($rc + $dt);
        $prev = $prev + $alpha * ($input - $prev);
        return $prev;
    }

    private static function packSample(float $sample): string
    {
        $clamped = max(-1.0, min(1.0, $sample));
        $int16 = (int) ($clamped * 32767);
        return pack('v', $int16 & 0xffff);
    }

    private static function wrapWav(string $pcmData, int $channels): string
    {
        $dataSize = strlen($pcmData);
        $byteRate = self::SAMPLE_RATE * $channels * (self::BITS_PER_SAMPLE / 8);
        $blockAlign = $channels * (self::BITS_PER_SAMPLE / 8);

        $header = 'RIFF';
        $header .= pack('V', 36 + $dataSize);
        $header .= 'WAVE';
        $header .= 'fmt ';
        $header .= pack('V', 16);                    // chunk size
        $header .= pack('v', 1);                     // PCM format
        $header .= pack('v', $channels);
        $header .= pack('V', self::SAMPLE_RATE);
        $header .= pack('V', (int) $byteRate);
        $header .= pack('v', (int) $blockAlign);
        $header .= pack('v', self::BITS_PER_SAMPLE);
        $header .= 'data';
        $header .= pack('V', $dataSize);

        return $header . $pcmData;
    }
}
