<?php

declare(strict_types=1);

namespace PHPolygon\Rendering;

use PHPolygon\Math\Vec3;

/**
 * OpenGL post-processing pipeline.
 * Renders the 3D scene to an offscreen FBO (HDR color + depth),
 * then applies fullscreen post-processing effects (SSAO, Bloom, God Rays, DOF, Tone Mapping).
 */
class PostProcessPipeline
{
    private int $fbo = 0;
    private int $colorTexture = 0;
    private int $depthTexture = 0;
    private int $shaderProgram = 0;
    private int $vao = 0;
    private bool $initialized = false;

    // Effect toggles
    private bool $ssaoEnabled = true;
    private bool $bloomEnabled = true;
    private bool $godRaysEnabled = true;
    private bool $dofEnabled = false;
    private float $dofFocusDistance = 15.0;
    private float $dofRange = 10.0;

    // Sun data for god rays
    private Vec3 $sunDirection;
    private float $sunIntensity = 1.0;

    private const VERT_PATH = __DIR__ . '/../../resources/shaders/source/postprocess.vert.glsl';
    private const FRAG_PATH = __DIR__ . '/../../resources/shaders/source/postprocess.frag.glsl';

    public function __construct(
        private int $width,
        private int $height,
    ) {
        $this->sunDirection = new Vec3(0.0, -1.0, 0.0);
    }

    public function isInitialized(): bool { return $this->initialized; }

    public function setSSAO(bool $enabled): void { $this->ssaoEnabled = $enabled; }
    public function setBloom(bool $enabled): void { $this->bloomEnabled = $enabled; }
    public function setGodRays(bool $enabled): void { $this->godRaysEnabled = $enabled; }
    public function setDOF(bool $enabled, float $focusDistance = 15.0, float $range = 10.0): void
    {
        $this->dofEnabled = $enabled;
        $this->dofFocusDistance = $focusDistance;
        $this->dofRange = $range;
    }

    public function setSunData(Vec3 $direction, float $intensity): void
    {
        $this->sunDirection = $direction;
        $this->sunIntensity = $intensity;
    }

    public function initialize(): void
    {
        if ($this->initialized) return;

        // HDR color texture (RGBA16F for HDR range)
        glGenTextures(1, $colorTex);
        $this->colorTexture = $colorTex;
        glBindTexture(GL_TEXTURE_2D, $this->colorTexture);
        glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA16F, $this->width, $this->height, 0, GL_RGBA, GL_FLOAT, null);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);

        // Depth texture
        glGenTextures(1, $depthTex);
        $this->depthTexture = $depthTex;
        glBindTexture(GL_TEXTURE_2D, $this->depthTexture);
        glTexImage2D(GL_TEXTURE_2D, 0, GL_DEPTH_COMPONENT32F, $this->width, $this->height, 0, GL_DEPTH_COMPONENT, GL_FLOAT, null);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MIN_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_MAG_FILTER, GL_LINEAR);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_S, GL_CLAMP_TO_EDGE);
        glTexParameteri(GL_TEXTURE_2D, GL_TEXTURE_WRAP_T, GL_CLAMP_TO_EDGE);

        // FBO
        glGenFramebuffers(1, $fboId);
        $this->fbo = $fboId;
        glBindFramebuffer(GL_FRAMEBUFFER, $this->fbo);
        glFramebufferTexture2D(GL_FRAMEBUFFER, GL_COLOR_ATTACHMENT0, GL_TEXTURE_2D, $this->colorTexture, 0);
        glFramebufferTexture2D(GL_FRAMEBUFFER, GL_DEPTH_ATTACHMENT, GL_TEXTURE_2D, $this->depthTexture, 0);
        glBindFramebuffer(GL_FRAMEBUFFER, 0);

        // Empty VAO for fullscreen triangle (vertex shader generates positions from gl_VertexID)
        glGenVertexArrays(1, $vaoId);
        $this->vao = $vaoId;

        // Compile post-process shader
        $this->shaderProgram = $this->compileShader();

        $this->initialized = true;
    }

    /**
     * Begin offscreen pass — redirect all rendering to the HDR FBO.
     */
    public function beginSceneCapture(): void
    {
        if (!$this->initialized) return;
        glBindFramebuffer(GL_FRAMEBUFFER, $this->fbo);
        glViewport(0, 0, $this->width, $this->height);
        glClear(GL_COLOR_BUFFER_BIT | GL_DEPTH_BUFFER_BIT);
    }

    /**
     * End scene capture and apply post-processing to screen.
     */
    public function applyAndPresent(): void
    {
        if (!$this->initialized) return;

        // Bind default framebuffer
        glBindFramebuffer(GL_FRAMEBUFFER, 0);
        glViewport(0, 0, $this->width, $this->height);

        glDisable(GL_DEPTH_TEST);
        glUseProgram($this->shaderProgram);

        // Bind scene textures
        glActiveTexture(GL_TEXTURE0);
        glBindTexture(GL_TEXTURE_2D, $this->colorTexture);
        glUniform1i(glGetUniformLocation($this->shaderProgram, 'u_scene_color'), 0);

        glActiveTexture(GL_TEXTURE1);
        glBindTexture(GL_TEXTURE_2D, $this->depthTexture);
        glUniform1i(glGetUniformLocation($this->shaderProgram, 'u_scene_depth'), 1);

        // Uniforms
        glUniform1i(glGetUniformLocation($this->shaderProgram, 'u_width'), $this->width);
        glUniform1i(glGetUniformLocation($this->shaderProgram, 'u_height'), $this->height);
        glUniform1i(glGetUniformLocation($this->shaderProgram, 'u_enable_ssao'), $this->ssaoEnabled ? 1 : 0);
        glUniform1i(glGetUniformLocation($this->shaderProgram, 'u_enable_bloom'), $this->bloomEnabled ? 1 : 0);
        glUniform1i(glGetUniformLocation($this->shaderProgram, 'u_enable_godrays'), $this->godRaysEnabled ? 1 : 0);
        glUniform1i(glGetUniformLocation($this->shaderProgram, 'u_enable_dof'), $this->dofEnabled ? 1 : 0);
        glUniform1f(glGetUniformLocation($this->shaderProgram, 'u_dof_focus_distance'), $this->dofFocusDistance);
        glUniform1f(glGetUniformLocation($this->shaderProgram, 'u_dof_range'), $this->dofRange);
        glUniform3f(glGetUniformLocation($this->shaderProgram, 'u_sun_direction'),
            $this->sunDirection->x, $this->sunDirection->y, $this->sunDirection->z);
        glUniform1f(glGetUniformLocation($this->shaderProgram, 'u_sun_intensity'), $this->sunIntensity);

        // Draw fullscreen triangle
        glBindVertexArray($this->vao);
        glDrawArrays(GL_TRIANGLES, 0, 3);
        glBindVertexArray(0);

        glEnable(GL_DEPTH_TEST);
    }

    public function resize(int $width, int $height): void
    {
        $this->width = $width;
        $this->height = $height;

        if (!$this->initialized) return;

        // Resize color texture
        glBindTexture(GL_TEXTURE_2D, $this->colorTexture);
        glTexImage2D(GL_TEXTURE_2D, 0, GL_RGBA16F, $width, $height, 0, GL_RGBA, GL_FLOAT, null);

        // Resize depth texture
        glBindTexture(GL_TEXTURE_2D, $this->depthTexture);
        glTexImage2D(GL_TEXTURE_2D, 0, GL_DEPTH_COMPONENT32F, $width, $height, 0, GL_DEPTH_COMPONENT, GL_FLOAT, null);
    }

    private function compileShader(): int
    {
        $vertSrc = file_get_contents(self::VERT_PATH);
        $fragSrc = file_get_contents(self::FRAG_PATH);

        $vert = glCreateShader(GL_VERTEX_SHADER);
        glShaderSource($vert, $vertSrc);
        glCompileShader($vert);

        $frag = glCreateShader(GL_FRAGMENT_SHADER);
        glShaderSource($frag, $fragSrc);
        glCompileShader($frag);

        $program = glCreateProgram();
        glAttachShader($program, $vert);
        glAttachShader($program, $frag);
        glLinkProgram($program);

        glDeleteShader($vert);
        glDeleteShader($frag);

        return $program;
    }
}
