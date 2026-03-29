#version 450

layout(location = 0) in vec3 a_position;
layout(location = 1) in vec3 a_normal;
layout(location = 2) in vec2 a_uv;

// Per-instance model matrix (4 vec4 columns, locations 3-6)
// When instancing is active, these replace the push constant model.
layout(location = 3) in vec4 a_instance_model_col0;
layout(location = 4) in vec4 a_instance_model_col1;
layout(location = 5) in vec4 a_instance_model_col2;
layout(location = 6) in vec4 a_instance_model_col3;

// Per-frame: view + projection + misc
layout(binding = 0) uniform FrameUBO {
    mat4 u_view;
    mat4 u_projection;
    float u_time;
    float u_temperature;
    int   u_use_instancing; // 0 = push constant, 1 = per-instance attributes
    int   u_vertex_anim;
    float u_wave_amplitude;
    float u_wave_frequency;
    float u_wave_phase;
    float _pad0;
    vec3  u_camera_pos;
    float _pad1;
};

// Per-draw: model matrix via push constant (64 bytes)
layout(push_constant) uniform PushConstants {
    mat4 u_model;
};

layout(location = 0) out vec3 v_normal;
layout(location = 1) out vec3 v_worldPos;
layout(location = 2) out vec2 v_uv;

void main() {
    // Select model matrix: per-instance attribute or push constant
    mat4 model;
    if (u_use_instancing == 1) {
        model = mat4(a_instance_model_col0, a_instance_model_col1,
                     a_instance_model_col2, a_instance_model_col3);
    } else {
        model = u_model;
    }

    vec3 pos = a_position;

    // Optional GPU wave animation
    if (u_vertex_anim == 1) {
        vec4 worldPosRaw = model * vec4(pos, 1.0);
        float wave = sin(worldPosRaw.x * u_wave_frequency + u_time + u_wave_phase)
                   * cos(worldPosRaw.z * u_wave_frequency * 0.7 + u_time * 0.8)
                   * u_wave_amplitude;
        pos.y += wave;
    }

    vec4 worldPos = model * vec4(pos, 1.0);

    // Heat shimmer: subtle vertex displacement at high temperatures
    if (u_temperature > 30.0) {
        float heatStr = clamp((u_temperature - 30.0) / 20.0, 0.0, 1.0);
        float dist = length(worldPos.xyz - u_camera_pos);
        float distFade = smoothstep(8.0, 40.0, dist);
        float shimmer = sin(worldPos.x * 3.0 + u_time * 4.0)
                      * cos(worldPos.z * 2.5 + u_time * 3.0);
        worldPos.y += shimmer * heatStr * distFade * 0.03;
    }

    v_worldPos = worldPos.xyz;

    // Normal matrix from model matrix
    v_normal = mat3(transpose(inverse(model))) * a_normal;

    v_uv = a_uv;
    gl_Position = u_projection * u_view * worldPos;
}
