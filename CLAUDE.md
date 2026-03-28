# CLAUDE.md — PHPolygon 3D Game Project

This is a **PHPolygon 3D game project**. Claude Code is the primary authoring tool.
All geometry is procedural PHP code — no external 3D model files.

---

## Engine

- **PHPolygon** is a PHP-native game engine. Require via `phpolygon/phpolygon`.
- 3D rendering via OpenGL 4.1 (php-glfw) with RenderCommandList abstraction
- ECS architecture: Entities have Components, Systems process them
- Scenes are PHP classes extending `Scene` with a `build(SceneBuilder)` method
- All geometry is generated procedurally via `MeshRegistry` — no .fbx, .obj, .gltf files

## Project Structure

```
game.php            Entry point — bootstraps Engine, calls App\Game::run()
src/
  Game.php          Main class — EngineConfig (is3D: true), scene registration
  Scene/            Scene classes (extend PHPolygon\Scene\Scene)
  Component/        Game-specific components (extend AbstractComponent)
  System/           Game-specific systems (implement SystemInterface)
  Prefab/           Reusable entity templates (implement PrefabInterface)
assets/             Audio, fonts (NO texture files — everything is code)
resources/
  shaders/source/   Custom GLSL shaders (optional, engine provides defaults)
config/             Input mappings
tests/              PHPUnit tests (run headless without GPU)
build.json          Build configuration for standalone executables
```

## 3D Conventions

### Geometry — always procedural
```php
MeshRegistry::register('building', BoxMesh::generate(4.0, 8.0, 4.0));
MeshRegistry::register('tree_trunk', CylinderMesh::generate(0.3, 3.0, 8));
MeshRegistry::register('canopy', SphereMesh::generate(2.0, 8, 12));
```

### Materials — code-defined, no texture files
```php
MaterialRegistry::register('stone', new Material(
    albedo: Color::hex('#888888'),
    roughness: 0.9,
    metallic: 0.0,
));
MaterialRegistry::register('neon', new Material(
    albedo: Color::hex('#111122'),
    emission: Color::hex('#ff00ff'),
));
```

### Entities via SceneBuilder
```php
$builder->entity('Tower')
    ->with(new Transform3D(position: new Vec3(10, 0, -20), scale: new Vec3(1, 3, 1)))
    ->with(new MeshRenderer(meshId: 'building', materialId: 'stone'));
```

### Instancing for repeated geometry
Use `DrawMeshInstanced` when the same mesh appears many times — it's much cheaper than individual `DrawMesh` calls.

## Component Patterns

### 3D Components
- `Transform3D` — position (Vec3), rotation (Quaternion), scale (Vec3)
- `MeshRenderer` — meshId + materialId (string references to registries)
- `Camera3DComponent` — fov, near, far
- `CharacterController3D` — capsule collision, gravity
- `DirectionalLight` — direction, color, intensity
- `PointLight` — color, intensity, radius

### Creating a custom Component
```php
#[Serializable]
class Spinner extends AbstractComponent {
    #[Property]
    public float $speed = 1.0;
}
```

### Creating a System
```php
class SpinnerSystem extends AbstractSystem {
    public function update(World $world, float $dt): void {
        foreach ($world->query(Transform3D::class, Spinner::class) as $entity) {
            $t = $world->getComponent($entity->id, Transform3D::class);
            $s = $world->getComponent($entity->id, Spinner::class);
            $t->rotation = $t->rotation->mul(Quaternion::fromAxisAngle(Vec3::up(), $s->speed * $dt));
        }
    }
}
```

## Anti-Patterns
- Do NOT import 3D model files (.fbx, .obj, .gltf, .blend) — generate geometry in PHP
- Do NOT put cross-entity logic in Components
- Do NOT call GPU APIs (glDraw*, vkCmd*) from Systems — only backends touch the GPU
- Do NOT mix Transform2D and Transform3D on the same entity
- Do NOT store runtime state in PHP files — use JSON via SaveManager

## Running

```bash
composer install
php game.php                                          # Run the game
vendor/bin/phpunit                                    # Run tests (headless)
php -d phar.readonly=0 vendor/bin/phpolygon build     # Build standalone
```
