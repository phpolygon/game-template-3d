#version 410 core

// Fullscreen triangle — no vertex buffer needed
// Draws a single triangle that covers the entire screen
// Vertex IDs 0, 1, 2 → positions (-1,-1), (3,-1), (-1,3)

out vec2 v_uv;

void main() {
    // Generate fullscreen triangle from vertex ID
    float x = float((gl_VertexID & 1) << 2) - 1.0;
    float y = float((gl_VertexID & 2) << 1) - 1.0;
    v_uv = vec2((x + 1.0) * 0.5, (y + 1.0) * 0.5);
    gl_Position = vec4(x, y, 0.0, 1.0);
}
