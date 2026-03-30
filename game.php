<?php

declare(strict_types=1);

// Ensure Vulkan/MoltenVK is discoverable on macOS.
// DYLD_LIBRARY_PATH must be set BEFORE process start (putenv doesn't work for dyld).
if (PHP_OS_FAMILY === 'Darwin' && !getenv('DYLD_LIBRARY_PATH')) {
    foreach (['/opt/homebrew/lib', '/usr/local/lib'] as $dir) {
        if (file_exists("{$dir}/libvulkan.dylib")) {
            $cmd = sprintf('DYLD_LIBRARY_PATH=%s exec %s %s', escapeshellarg($dir), escapeshellarg(PHP_BINARY), escapeshellarg(__FILE__));
            passthru($cmd, $exitCode);
            exit($exitCode);
        }
    }
}

require_once __DIR__ . '/vendor/autoload.php';

App\Game::run();
