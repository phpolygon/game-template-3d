# PHPolygon 3D Game Template

A procedural playground starter project built with [PHPolygon](https://github.com/hmennen90/phpolygon).

All geometry is generated from PHP code — no external 3D model files.

## Getting Started

```bash
composer create-project phpolygon/game-template-3d my-game
cd my-game
php game.php
```

## Controls

| Key | Action |
|-----|--------|
| WASD | Move |
| Right Click | Toggle mouse look |
| Mouse | Look around (when captured) |
| Escape | Quit |

## What's Included

- First-person camera with mouse look
- Procedural ground plane (50x50)
- 5 scattered boxes with different materials (brick, metal, wood)
- 3 emissive spheres with colored point lights
- Directional sunlight
- Gravity via CharacterController3D

## Project Structure

| Directory | Purpose |
|-----------|---------|
| `src/Scene/` | Game scenes with procedural world building |
| `src/Component/` | Custom ECS components |
| `src/System/` | Game logic systems (camera, movement) |
| `assets/` | Audio, fonts (no texture files — everything is code) |
| `resources/shaders/` | Custom GLSL shaders (optional) |
| `config/` | Input mappings |
| `tests/` | PHPUnit tests (headless) |

## Building

```bash
php -d phar.readonly=0 vendor/bin/phpolygon build
```

## AI Authoring

This project is designed for AI-first authoring with Claude Code. See `CLAUDE.md` for conventions and patterns.
