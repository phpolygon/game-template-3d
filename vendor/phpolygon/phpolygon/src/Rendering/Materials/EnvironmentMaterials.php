<?php

declare(strict_types=1);

namespace PHPolygon\Rendering\Materials;

use PHPolygon\Rendering\Color;
use PHPolygon\Rendering\Material;
use PHPolygon\Rendering\MaterialRegistry;

/**
 * Environment material presets for the 15 new procedural shader modes (11-25).
 * Each ID prefix maps to its proc_mode via the renderer's resolveProcMode().
 */
class EnvironmentMaterials
{
    public static function registerAll(): void
    {
        // Glass (proc_mode 11)
        MaterialRegistry::register('glass_clear', new Material(albedo: Color::hex('#E8F0FF'), roughness: 0.02, alpha: 0.3));
        MaterialRegistry::register('glass_green', new Material(albedo: Color::hex('#2D8B57'), roughness: 0.02, alpha: 0.35));
        MaterialRegistry::register('glass_amber', new Material(albedo: Color::hex('#D4870F'), roughness: 0.03, alpha: 0.4));
        MaterialRegistry::register('crystal_blue', new Material(albedo: Color::hex('#4488CC'), roughness: 0.01, alpha: 0.25));

        // Polished Metal (proc_mode 12)
        MaterialRegistry::register('chrome_mirror', new Material(albedo: Color::hex('#CCCCCC'), roughness: 0.05, metallic: 1.0));
        MaterialRegistry::register('gold_polished', new Material(albedo: Color::hex('#FFD700'), roughness: 0.1, metallic: 1.0));
        MaterialRegistry::register('copper_polished', new Material(albedo: Color::hex('#B87333'), roughness: 0.15, metallic: 1.0));
        MaterialRegistry::register('steel_brushed', new Material(albedo: Color::hex('#8899AA'), roughness: 0.25, metallic: 0.9));
        MaterialRegistry::register('iron_dark', new Material(albedo: Color::hex('#444444'), roughness: 0.3, metallic: 0.85));

        // Fabric (proc_mode 13)
        MaterialRegistry::register('fabric_white', new Material(albedo: Color::hex('#F0F0E8'), roughness: 0.8));
        MaterialRegistry::register('fabric_red', new Material(albedo: Color::hex('#AA2222'), roughness: 0.75));
        MaterialRegistry::register('fabric_blue', new Material(albedo: Color::hex('#2244AA'), roughness: 0.78));
        MaterialRegistry::register('silk_gold', new Material(albedo: Color::hex('#DAA520'), roughness: 0.4));
        MaterialRegistry::register('canvas_natural', new Material(albedo: Color::hex('#C4B69C'), roughness: 0.85));

        // Fire (proc_mode 14)
        MaterialRegistry::register('fire_orange', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#FF6600'), alpha: 0.9));
        MaterialRegistry::register('flame_blue', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#3366FF'), alpha: 0.85));
        MaterialRegistry::register('torch_flame', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#FF8800'), alpha: 0.95));

        // Lava (proc_mode 15)
        MaterialRegistry::register('lava_hot', new Material(albedo: Color::hex('#1A0500'), emission: Color::hex('#FF4400')));
        MaterialRegistry::register('magma_cooling', new Material(albedo: Color::hex('#2A1005'), emission: Color::hex('#CC3300')));

        // Ice (proc_mode 16)
        MaterialRegistry::register('ice_clear', new Material(albedo: Color::hex('#B0D4F1'), roughness: 0.05, alpha: 0.6));
        MaterialRegistry::register('frost_white', new Material(albedo: Color::hex('#E8F0FF'), roughness: 0.5, alpha: 0.8));
        MaterialRegistry::register('frozen_blue', new Material(albedo: Color::hex('#6699CC'), roughness: 0.1, alpha: 0.5));

        // Grass (proc_mode 17)
        MaterialRegistry::register('grass_green', new Material(albedo: Color::hex('#3D8B2E'), roughness: 0.7));
        MaterialRegistry::register('grass_dry', new Material(albedo: Color::hex('#9B8B3D'), roughness: 0.8));
        MaterialRegistry::register('lawn_fresh', new Material(albedo: Color::hex('#4CAF50'), roughness: 0.65));

        // Neon (proc_mode 18)
        MaterialRegistry::register('neon_pink', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#FF1493')));
        MaterialRegistry::register('neon_cyan', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#00FFFF')));
        MaterialRegistry::register('neon_green', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#39FF14')));
        MaterialRegistry::register('neon_purple', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#BF00FF')));
        MaterialRegistry::register('neon_yellow', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#FFFF00')));
        MaterialRegistry::register('led_white', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#FFFFFF')));

        // Concrete (proc_mode 19)
        MaterialRegistry::register('concrete_grey', new Material(albedo: Color::hex('#808080'), roughness: 0.9));
        MaterialRegistry::register('concrete_light', new Material(albedo: Color::hex('#A0A0A0'), roughness: 0.88));
        MaterialRegistry::register('asphalt_dark', new Material(albedo: Color::hex('#333333'), roughness: 0.92));

        // Brick (proc_mode 20)
        MaterialRegistry::register('brick_red', new Material(albedo: Color::hex('#8B4513'), roughness: 0.8));
        MaterialRegistry::register('brick_brown', new Material(albedo: Color::hex('#6B3410'), roughness: 0.82));
        MaterialRegistry::register('brick_yellow', new Material(albedo: Color::hex('#D4A84B'), roughness: 0.78));

        // Tile (proc_mode 21)
        MaterialRegistry::register('tile_white', new Material(albedo: Color::hex('#F5F5F0'), roughness: 0.08));
        MaterialRegistry::register('tile_blue', new Material(albedo: Color::hex('#2266AA'), roughness: 0.1));
        MaterialRegistry::register('ceramic_terracotta', new Material(albedo: Color::hex('#CC6633'), roughness: 0.15));

        // Leather (proc_mode 22)
        MaterialRegistry::register('leather_brown', new Material(albedo: Color::hex('#8B4513'), roughness: 0.6));
        MaterialRegistry::register('leather_black', new Material(albedo: Color::hex('#1A1A1A'), roughness: 0.5));
        MaterialRegistry::register('leather_tan', new Material(albedo: Color::hex('#D2B48C'), roughness: 0.55));
        MaterialRegistry::register('hide_raw', new Material(albedo: Color::hex('#A0876C'), roughness: 0.7));

        // Skin (proc_mode 23)
        MaterialRegistry::register('skin_light', new Material(albedo: Color::hex('#FFD5B8'), roughness: 0.5));
        MaterialRegistry::register('skin_medium', new Material(albedo: Color::hex('#C68642'), roughness: 0.48));
        MaterialRegistry::register('skin_dark', new Material(albedo: Color::hex('#8D5524'), roughness: 0.45));

        // Particle/Smoke (proc_mode 24)
        MaterialRegistry::register('smoke_grey', new Material(albedo: Color::hex('#666666'), emission: Color::hex('#333333'), alpha: 0.3));
        MaterialRegistry::register('smoke_black', new Material(albedo: Color::hex('#111111'), emission: Color::hex('#0A0A0A'), alpha: 0.4));
        MaterialRegistry::register('dust_brown', new Material(albedo: Color::hex('#8B7355'), emission: Color::hex('#443322'), alpha: 0.25));
        MaterialRegistry::register('particle_white', new Material(albedo: Color::hex('#FFFFFF'), emission: Color::hex('#888888'), alpha: 0.5));

        // Hologram (proc_mode 25)
        MaterialRegistry::register('hologram_blue', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#00AAFF'), alpha: 0.6));
        MaterialRegistry::register('holo_green', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#00FF88'), alpha: 0.5));
        MaterialRegistry::register('cyber_red', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#FF2244'), alpha: 0.55));
        MaterialRegistry::register('hologram_white', new Material(albedo: Color::hex('#000000'), emission: Color::hex('#CCDDFF'), alpha: 0.4));
    }
}
