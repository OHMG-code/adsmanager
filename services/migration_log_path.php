<?php

declare(strict_types=1);

function resolveMigrationLogPath(): array
{
    $primaryDir = __DIR__ . '/../storage/logs';
    $primaryPath = $primaryDir . '/migrator.log';
    if (!is_dir($primaryDir)) {
        @mkdir($primaryDir, 0777, true);
    }
    if (is_dir($primaryDir) && is_writable($primaryDir)) {
        return [
            'path' => $primaryPath,
            'is_fallback' => false,
            'reason' => 'storage',
        ];
    }

    $fallback = sys_get_temp_dir() . '/migrator.log';
    return [
        'path' => $fallback,
        'is_fallback' => true,
        'reason' => 'tmp',
    ];
}
