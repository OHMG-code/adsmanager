#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'This script must be executed from the command line.' . PHP_EOL);
    exit(1);
}

$options = getopt('', ['dry-run::', 'force::', 'help']);
if ($options === false) {
    $options = [];
}

if (isset($options['help'])) {
    printUsage();
    exit(0);
}

$dryRun = isFlagEnabled($options, 'dry-run');
$force = isFlagEnabled($options, 'force');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/MigrationRunner.php';

$logPaths = [
    __DIR__ . '/migrate_all.log',
    __DIR__ . '/../storage/logs/migrate_all.log',
];

$runner = new MigrationRunner(
    $pdo,
    __DIR__ . '/../sql/migrations',
    [
        'dryRun' => $dryRun,
        'force' => $force,
        'host' => $host ?? '',
        'user' => $user ?? '',
        'dbname' => $db ?? '',
        'logPaths' => $logPaths,
    ]
);

exit($runner->run());

function isFlagEnabled(array $options, string $key): bool
{
    if (!array_key_exists($key, $options)) {
        return false;
    }

    $value = $options[$key];
    if ($value === false || $value === null) {
        return true;
    }

    return $value !== '0';
}

function printUsage(): void
{
    $script = basename(__FILE__);
    echo "Usage: php {$script} [--dry-run=1] [--force=1]" . PHP_EOL;
    echo "       --dry-run=1  show plan without executing SQL" . PHP_EOL;
    echo "       --force=1    force-import dump when companies table is missing" . PHP_EOL;
}
