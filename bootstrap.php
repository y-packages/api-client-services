<?php

declare(strict_types=1);

/*
 * YakNet API Client Services — Universal Dynamic Linker & Bootstrap
 *
 * When installed via Composer (default distribution archive), all 611+ service classes
 * are bundled inside `dist/api-client-services.ydl` (301 KB) and linked directly in-memory
 * via YakNet DynLink with zero disk thrashing.
 *
 * If installed with `--prefer-source` or during development, fallback PSR-4 autoloader
 * loads files directly from `src/`.
 */

// If Client class is already declared, skip initialization
if (class_exists(\YakNet\Api\Client::class, false)) {
    return;
}

$ydlBinary = __DIR__ . '/dist/api-client-services.ydl';

// 1. Force source mode if explicitly requested by environment (e.g. YAKNET_SDK_SOURCE=1)
if (getenv('YAKNET_SDK_SOURCE') === '1' && is_dir(__DIR__ . '/src')) {
    return;
}

// 2. High-Performance DynLink In-Memory Runtime
if (file_exists($ydlBinary) && class_exists(\YakNet\DynLink\DynLink::class)) {
    \YakNet\DynLink\DynLink::load($ydlBinary, [
        'auto_link' => true,
        'verify_checksum' => true,
    ]);
    return;
}

// 3. Fallback: If .ydl binary is absent, ensure PSR-4 autoloader loads from src/
if (is_dir(__DIR__ . '/src')) {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'YakNet\\Api\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }

        $rel = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix))) . '.php';
        $file = __DIR__ . '/src/' . $rel;
        if (file_exists($file)) {
            require_once $file;
        }
    });
}
