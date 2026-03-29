<?php

declare(strict_types=1);

namespace App\Audio;

class WavSynthesizer
{
    private const SAMPLE_RATE = 44100;
    private const BITS_PER_SAMPLE = 16;

    /**
     * Ocean waves — layered brown noise with periodic swell envelopes and surf crashes.
     */
    public static function generateOceanWaves(float $duration = 10.0): string
    {
        $sr = self::SAMPLE_RATE;
        $samples = (int) ($sr * $duration);
        $data = '';

        // State for brown noise (integrated white noise)
        $brown = 0.0;
        // State for second brown noise layer
        $brown2 = 0.0;
        // Simple one-pole low-pass states
        $lp1 = 0.0;
        $lp2 = 0.0;
        $lp3 = 0.0;

        // PRNG state (xorshift32)
        $rng = 0x12345678;

        for ($i = 0; $i < $samples; $i++) {
            $t = $i / $sr;

            // --- Swell envelope: slow periodic rise and fall ---
            $swell = 0.5
                + 0.25 * sin(2.0 * M_PI * 0.07 * $t)
                + 0.15 * sin(2.0 * M_PI * 0.12 * $t + 0.9)
                + 0.1 * sin(2.0 * M_PI * 0.19 * $t + 2.4);

            // --- Brown noise: integrate white noise, then low-pass ---
            $rng ^= ($rng << 13) & 0xFFFFFFFF;
            $rng ^= ($rng >> 17) & 0xFFFFFFFF;
            $rng ^= ($rng << 5) & 0xFFFFFFFF;
            $white = (($rng & 0xFFFFFFFF) / 2147483648.0) - 1.0;

            // Brown noise with leak (prevents DC drift)
            $brown = $brown * 0.998 + $white * 0.04;
            $brown = max(-1.0, min(1.0, $brown));

            // Low-pass cascade for deep ocean rumble (around 150-400 Hz)
            $cutoff1 = 250.0 + 150.0 * $swell;
            $a1 = self::lpCoeff($cutoff1);
            $lp1 += $a1 * ($brown - $lp1);

            // --- Second noise layer: higher frequency wash ---
            $rng ^= ($rng << 13) & 0xFFFFFFFF;
            $rng ^= ($rng >> 17) & 0xFFFFFFFF;
            $rng ^= ($rng << 5) & 0xFFFFFFFF;
            $white2 = (($rng & 0xFFFFFFFF) / 2147483648.0) - 1.0;

            $brown2 = $brown2 * 0.995 + $white2 * 0.06;
            $brown2 = max(-1.0, min(1.0, $brown2));

            $cutoff2 = 600.0 + 400.0 * $swell;
            $a2 = self::lpCoeff($cutoff2);
            $lp2 += $a2 * ($brown2 - $lp2);

            // --- Surf crash: periodic white noise bursts ---
            $crashPhase = fmod($t * 0.08, 1.0); // ~12.5 second cycle
            $crashEnv = 0.0;
            if ($crashPhase < 0.15) {
                // Sharp attack, longer decay
                $crashT = $crashPhase / 0.15;
                $crashEnv = $crashT < 0.2
                    ? $crashT * 5.0
                    : exp(-($crashT - 0.2) * 4.0);
            }

            $rng ^= ($rng << 13) & 0xFFFFFFFF;
            $rng ^= ($rng >> 17) & 0xFFFFFFFF;
            $rng ^= ($rng << 5) & 0xFFFFFFFF;
            $crashNoise = (($rng & 0xFFFFFFFF) / 2147483648.0) - 1.0;

            // Band-pass the crash noise (sounds like fizzing foam)
            $a3 = self::lpCoeff(2000.0);
            $lp3 += $a3 * ($crashNoise - $lp3);
            $crashBP = $crashNoise - $lp3; // high-pass residual
            $crash = $crashBP * $crashEnv * 0.35;

            // --- Deep sub-bass rumble ---
            $rumble = sin(2.0 * M_PI * 35.0 * $t + sin(2.0 * M_PI * 0.08 * $t) * 4.0)
                    * $swell * 0.06;

            // --- Mix ---
            $sample = $lp1 * $swell * 0.45
                    + $lp2 * $swell * 0.25
                    + $crash
                    + $rumble;

            $data .= self::packSample($sample * 0.65);
        }

        return self::wrapWav($data, 1);
    }

    /**
     * Wind — shaped brown/pink noise with gusting modulation and subtle whistle.
     */
    public static function generateWind(float $duration = 10.0): string
    {
        $sr = self::SAMPLE_RATE;
        $samples = (int) ($sr * $duration);
        $data = '';

        $brown = 0.0;
        $lp1 = 0.0;
        $lp2 = 0.0;
        $hp = 0.0;
        $prevInput = 0.0;
        $rng = 0x87654321;

        for ($i = 0; $i < $samples; $i++) {
            $t = $i / $sr;

            // --- Gust envelope: layered slow oscillations ---
            $gust = 0.35
                + 0.25 * sin(2.0 * M_PI * 0.12 * $t)
                + 0.18 * sin(2.0 * M_PI * 0.07 * $t + 1.2)
                + 0.12 * sin(2.0 * M_PI * 0.23 * $t + 3.5)
                + 0.08 * sin(2.0 * M_PI * 0.41 * $t + 0.7);
            $gust = max(0.05, min(1.0, $gust));

            // --- Brown noise base ---
            $rng ^= ($rng << 13) & 0xFFFFFFFF;
            $rng ^= ($rng >> 17) & 0xFFFFFFFF;
            $rng ^= ($rng << 5) & 0xFFFFFFFF;
            $white = (($rng & 0xFFFFFFFF) / 2147483648.0) - 1.0;

            $brown = $brown * 0.997 + $white * 0.05;
            $brown = max(-1.0, min(1.0, $brown));

            // Variable low-pass: gust intensity shifts cutoff
            $cutoff = 200.0 + $gust * 500.0;
            $a1 = self::lpCoeff($cutoff);
            $lp1 += $a1 * ($brown - $lp1);

            // --- High-pass layer for "airy" whoosh quality ---
            $rng ^= ($rng << 13) & 0xFFFFFFFF;
            $rng ^= ($rng >> 17) & 0xFFFFFFFF;
            $rng ^= ($rng << 5) & 0xFFFFFFFF;
            $white2 = (($rng & 0xFFFFFFFF) / 2147483648.0) - 1.0;

            $a2 = self::lpCoeff(400.0);
            $lp2 += $a2 * ($white2 - $lp2);
            $airy = ($white2 - $lp2) * 0.3; // high-pass residual

            // --- Whistle on strong gusts ---
            $whistle = 0.0;
            if ($gust > 0.65) {
                $whistleStr = ($gust - 0.65) / 0.35;
                $freq = 1100.0 + sin($t * 2.5) * 250.0 + sin($t * 5.7) * 80.0;
                $whistle = sin(2.0 * M_PI * $freq * $t) * $whistleStr * 0.04;
            }

            // --- Mix ---
            $sample = $lp1 * $gust * 0.55
                    + $airy * $gust * 0.3
                    + $whistle;

            $data .= self::packSample($sample * 0.55);
        }

        return self::wrapWav($data, 1);
    }

    /**
     * Footstep on sand — short crunchy burst with thud.
     */
    public static function generateFootstepSand(): string
    {
        $sr = self::SAMPLE_RATE;
        $duration = 0.3;
        $samples = (int) ($sr * $duration);
        $data = '';

        $lp = 0.0;
        $rng = 0xABCDEF01;

        for ($i = 0; $i < $samples; $i++) {
            $t = $i / $sr;

            // Envelope: very fast attack, exponential decay
            $env = min(1.0, $t * 500.0) * exp(-$t * 15.0);

            // Sand crunch: band-limited noise
            $rng ^= ($rng << 13) & 0xFFFFFFFF;
            $rng ^= ($rng >> 17) & 0xFFFFFFFF;
            $rng ^= ($rng << 5) & 0xFFFFFFFF;
            $white = (($rng & 0xFFFFFFFF) / 2147483648.0) - 1.0;

            // Low-pass at ~3kHz for sandy texture (not sharp/clicky)
            $a = self::lpCoeff(3000.0);
            $lp += $a * ($white - $lp);

            // Thud: low sine burst
            $thud = sin(2.0 * M_PI * 55.0 * $t) * exp(-$t * 25.0) * 0.4;

            // Granular crunch: modulated noise
            $crunchMod = 0.5 + 0.5 * sin(2.0 * M_PI * 120.0 * $t);
            $crunch = $lp * $crunchMod;

            $sample = ($crunch * 0.6 + $thud) * $env;
            $data .= self::packSample($sample * 0.7);
        }

        return self::wrapWav($data, 1);
    }

    /**
     * Seagull calls — frequency-swept tones with harmonics.
     */
    public static function generateSeagulls(float $duration = 8.0): string
    {
        $sr = self::SAMPLE_RATE;
        $samples = (int) ($sr * $duration);
        $data = '';

        // Pre-compute call timings
        $calls = [
            ['start' => 0.3, 'dur' => 0.45, 'pitch' => 2400.0, 'sweep' => -600.0],
            ['start' => 1.0, 'dur' => 0.3, 'pitch' => 2800.0, 'sweep' => -900.0],
            ['start' => 2.8, 'dur' => 0.5, 'pitch' => 2200.0, 'sweep' => -500.0],
            ['start' => 3.5, 'dur' => 0.25, 'pitch' => 3000.0, 'sweep' => -1100.0],
            ['start' => 5.2, 'dur' => 0.4, 'pitch' => 2500.0, 'sweep' => -700.0],
            ['start' => 6.0, 'dur' => 0.35, 'pitch' => 2700.0, 'sweep' => -800.0],
        ];

        // Phase accumulators per call
        $phases = array_fill(0, count($calls), 0.0);

        for ($i = 0; $i < $samples; $i++) {
            $t = $i / $sr;
            $sample = 0.0;

            foreach ($calls as $ci => $call) {
                $ct = $t - $call['start'];
                if ($ct < 0.0 || $ct > $call['dur']) {
                    continue;
                }

                $progress = $ct / $call['dur'];

                // Envelope: sine-squared with sharper attack
                $env = sin(M_PI * $progress);
                $env = $env * $env * (1.0 - $progress * 0.3);

                // Frequency: base + sweep + vibrato
                $freq = $call['pitch']
                    + $call['sweep'] * $progress
                    + sin($ct * 25.0) * 150.0 * (1.0 - $progress);

                // Phase accumulator for clean frequency sweeps
                $phases[$ci] += $freq / $sr;

                // Fundamental + harmonics (seagulls have nasal harmonic-rich tone)
                $phase = $phases[$ci];
                $tone = sin(2.0 * M_PI * $phase) * 0.45
                      + sin(2.0 * M_PI * $phase * 2.0) * 0.25
                      + sin(2.0 * M_PI * $phase * 3.0) * 0.15
                      + sin(2.0 * M_PI * $phase * 4.0) * 0.08;

                $sample += $tone * $env * 0.25;
            }

            $data .= self::packSample($sample);
        }

        return self::wrapWav($data, 1);
    }

    /**
     * Rain — pink noise with sparse droplet impulses and intensity modulation.
     */
    public static function generateRain(float $duration = 10.0): string
    {
        $sr = self::SAMPLE_RATE;
        $samples = (int) ($sr * $duration);
        $data = '';

        $brown = 0.0;
        $lp1 = 0.0;
        $lp2 = 0.0;
        $dropLp = 0.0;
        $rng = 0x3456ABCD;

        for ($i = 0; $i < $samples; $i++) {
            $t = $i / $sr;

            // Intensity modulation — slow wax/wane for natural feel
            $intensity = 0.6
                + 0.2 * sin(2.0 * M_PI * 0.08 * $t)
                + 0.12 * sin(2.0 * M_PI * 0.13 * $t + 1.7)
                + 0.08 * sin(2.0 * M_PI * 0.05 * $t + 3.2);

            // Pink noise base: brown with less integration (brighter than ocean)
            $rng ^= ($rng << 13) & 0xFFFFFFFF;
            $rng ^= ($rng >> 17) & 0xFFFFFFFF;
            $rng ^= ($rng << 5) & 0xFFFFFFFF;
            $white = (($rng & 0xFFFFFFFF) / 2147483648.0) - 1.0;

            $brown = $brown * 0.993 + $white * 0.07;
            $brown = max(-1.0, min(1.0, $brown));

            // Band-pass 2000-6000 Hz for "shhh" of many droplets
            $a1 = self::lpCoeff(5000.0);
            $lp1 += $a1 * ($brown - $lp1);
            $a2 = self::lpCoeff(1500.0);
            $lp2 += $a2 * ($brown - $lp2);
            $bandpass = $lp1 - $lp2;

            // Sparse droplet impulses — individual drop impacts
            $rng ^= ($rng << 13) & 0xFFFFFFFF;
            $rng ^= ($rng >> 17) & 0xFFFFFFFF;
            $rng ^= ($rng << 5) & 0xFFFFFFFF;
            $dropRand = ($rng & 0xFFFF) / 65535.0;
            $drop = 0.0;
            if ($dropRand < 0.003) {
                $drop = $white * 0.8; // sharp impulse
            }
            // Resonant filter for drop "plop"
            $aD = self::lpCoeff(6000.0);
            $dropLp += $aD * ($drop - $dropLp);
            $dropFiltered = $drop - $dropLp * 0.5;

            $sample = $bandpass * $intensity * 0.5
                    + $dropFiltered * 0.25;

            $data .= self::packSample($sample * 0.6);
        }

        return self::wrapWav($data, 1);
    }

    /**
     * Thunder — initial crack, deep rumble with rolling echoes and sub-bass.
     */
    public static function generateThunder(float $duration = 4.0): string
    {
        $sr = self::SAMPLE_RATE;
        $samples = (int) ($sr * $duration);
        $data = '';

        $brown = 0.0;
        $lp1 = 0.0;
        $lp2 = 0.0;
        $lp3 = 0.0;
        $rng = 0xDEADBEEF;

        for ($i = 0; $i < $samples; $i++) {
            $t = $i / $sr;

            // Noise source
            $rng ^= ($rng << 13) & 0xFFFFFFFF;
            $rng ^= ($rng >> 17) & 0xFFFFFFFF;
            $rng ^= ($rng << 5) & 0xFFFFFFFF;
            $white = (($rng & 0xFFFFFFFF) / 2147483648.0) - 1.0;

            $brown = $brown * 0.998 + $white * 0.04;
            $brown = max(-1.0, min(1.0, $brown));

            // Initial crack: sharp white noise burst (first 25ms)
            $crack = 0.0;
            if ($t < 0.025) {
                $crackEnv = (1.0 - $t / 0.025);
                $a1 = self::lpCoeff(3000.0);
                $lp1 += $a1 * ($white - $lp1);
                $crack = ($white * 0.6 + $lp1 * 0.4) * $crackEnv * $crackEnv;
            }

            // Deep rumble body: brown noise low-passed at 80-200 Hz, long decay
            $rumbleCutoff = 80.0 + 120.0 * exp(-$t * 0.5);
            $a2 = self::lpCoeff($rumbleCutoff);
            $lp2 += $a2 * ($brown - $lp2);
            $rumbleEnv = exp(-$t * 0.8);
            $rumble = $lp2 * $rumbleEnv;

            // Rolling echoes: delayed filtered copies simulating cloud reflections
            $echo1 = ($t > 0.3) ? exp(-($t - 0.3) * 1.5) * 0.5 : 0.0;
            $echo2 = ($t > 0.8) ? exp(-($t - 0.8) * 2.0) * 0.3 : 0.0;
            $echo3 = ($t > 1.4) ? exp(-($t - 1.4) * 2.5) * 0.2 : 0.0;
            $echoMix = ($echo1 + $echo2 + $echo3) * $brown;

            $a3 = self::lpCoeff(150.0);
            $lp3 += $a3 * ($echoMix - $lp3);

            // Sub-bass: chest-thumping low frequency
            $subBass = sin(2.0 * M_PI * 30.0 * $t) * exp(-$t * 1.2) * 0.15;

            $sample = $crack * 0.8
                    + $rumble * 0.6
                    + $lp3 * 0.4
                    + $subBass;

            $data .= self::packSample($sample * 0.7);
        }

        return self::wrapWav($data, 1);
    }

    /**
     * Storm wind howl — aggressive wind with prominent whistling and deep gusts.
     */
    public static function generateWindHowl(float $duration = 10.0): string
    {
        $sr = self::SAMPLE_RATE;
        $samples = (int) ($sr * $duration);
        $data = '';

        $brown = 0.0;
        $lp1 = 0.0;
        $lp2 = 0.0;
        $rng = 0xFEDCBA98;

        for ($i = 0; $i < $samples; $i++) {
            $t = $i / $sr;

            // Aggressive gust envelope: deeper dips, sharper peaks
            $gust = 0.5
                + 0.3 * sin(2.0 * M_PI * 0.15 * $t)
                + 0.2 * sin(2.0 * M_PI * 0.09 * $t + 1.5)
                + 0.15 * sin(2.0 * M_PI * 0.31 * $t + 4.0)
                + 0.1 * sin(2.0 * M_PI * 0.53 * $t + 0.3);
            $gust = max(0.15, min(1.0, $gust));

            // Brown noise base — higher cutoff for more presence
            $rng ^= ($rng << 13) & 0xFFFFFFFF;
            $rng ^= ($rng >> 17) & 0xFFFFFFFF;
            $rng ^= ($rng << 5) & 0xFFFFFFFF;
            $white = (($rng & 0xFFFFFFFF) / 2147483648.0) - 1.0;

            $brown = $brown * 0.996 + $white * 0.06;
            $brown = max(-1.0, min(1.0, $brown));

            $cutoff = 300.0 + $gust * 900.0;
            $a1 = self::lpCoeff($cutoff);
            $lp1 += $a1 * ($brown - $lp1);

            // High-frequency howl layer
            $rng ^= ($rng << 13) & 0xFFFFFFFF;
            $rng ^= ($rng >> 17) & 0xFFFFFFFF;
            $rng ^= ($rng << 5) & 0xFFFFFFFF;
            $white2 = (($rng & 0xFFFFFFFF) / 2147483648.0) - 1.0;
            $a2 = self::lpCoeff(500.0);
            $lp2 += $a2 * ($white2 - $lp2);
            $howl = ($white2 - $lp2) * 0.4;

            // Multiple whistle frequencies — active from lower gust threshold
            $whistle = 0.0;
            if ($gust > 0.3) {
                $wStr = ($gust - 0.3) / 0.7;
                $f1 = 900.0 + sin($t * 1.8) * 200.0 + sin($t * 4.3) * 80.0;
                $f2 = 1400.0 + sin($t * 2.7) * 300.0 + sin($t * 7.1) * 50.0;
                $whistle = (sin(2.0 * M_PI * $f1 * $t) * 0.03
                          + sin(2.0 * M_PI * $f2 * $t) * 0.02) * $wStr;
            }

            $sample = $lp1 * $gust * 0.5
                    + $howl * $gust * 0.3
                    + $whistle;

            $data .= self::packSample($sample * 0.6);
        }

        return self::wrapWav($data, 1);
    }

    private static function lpCoeff(float $cutoff): float
    {
        $rc = 1.0 / (2.0 * M_PI * $cutoff);
        $dt = 1.0 / self::SAMPLE_RATE;
        return $dt / ($rc + $dt);
    }

    private static function packSample(float $sample): string
    {
        $clamped = max(-1.0, min(1.0, $sample));
        $int16 = (int) ($clamped * 32767);
        return pack('v', $int16 & 0xFFFF);
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
        $header .= pack('V', 16);
        $header .= pack('v', 1);                     // PCM
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
