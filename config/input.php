<?php

declare(strict_types=1);

return [
    'axes' => [
        'move_x' => [
            'positive' => [GLFW_KEY_D],
            'negative' => [GLFW_KEY_A],
        ],
        'move_z' => [
            'positive' => [GLFW_KEY_W],
            'negative' => [GLFW_KEY_S],
        ],
    ],
    'actions' => [
        'toggle_mouse' => [GLFW_MOUSE_BUTTON_RIGHT],
        'quit'         => [GLFW_KEY_ESCAPE],
    ],
];
