# PHPolygon 3D Game Template

A procedural beach world built with [PHPolygon](https://github.com/phpolygon/phpolygon).

Walk around a sunny beach with palm trees, rocks, and ocean — all generated from PHP code.

## Getting Started

```bash
composer create-project phpolygon/game-template-3d my-game
cd my-game
php game.php
```

## Controls

| Key | Action |
|-----|--------|
| WASD | Walk around |
| Right Click | Toggle mouse look |
| Mouse | Look around (when captured) |
| Escape | Quit |

## What's Included

- Sandy beach with wet shoreline and ocean
- 6 palm trees (cylinder trunks + sphere canopies)
- 7 scattered rock formations
- Warm directional sunlight + sunset glow point light
- First-person camera with mouse look
- CharacterController3D with gravity

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
