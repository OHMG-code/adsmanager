#!/usr/bin/env php
<?php

declare(strict_types=1);

if (!class_exists('ZipArchive')) {
    fwrite(STDERR, "ZipArchive extension is required.\n");
    exit(1);
}

$rootDir = dirname(__DIR__);
$options = getopt('', ['output::']);
if ($options === false) {
    $options = [];
}

$output = (string)($options['output'] ?? ($rootDir . '/dist/crm-update.zip'));
$output = trim($output);
if ($output === '') {
    fwrite(STDERR, "Output path cannot be empty.\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z]:\\\\|^\//', $output)) {
    $output = $rootDir . '/' . ltrim(str_replace('\\', '/', $output), '/');
}

$excludePrefixes = [
    '.git/',
    '.codex/',
    '.vscode/',
    'node_modules/',
    'storage/',
    'public/uploads/',
    'dist/',
];
$excludeFiles = [
    '.env',
    'config/db.local.php',
];

$outputDir = dirname($output);
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Cannot create output directory: {$outputDir}\n");
    exit(1);
}

if (is_file($output)) {
    @unlink($output);
}

$zip = new ZipArchive();
$opened = $zip->open($output, ZipArchive::CREATE | ZipArchive::OVERWRITE);
if ($opened !== true) {
    fwrite(STDERR, "Cannot create archive: {$output}\n");
    exit(1);
}

$rootLen = strlen($rootDir) + 1;
$count = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($rootDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    if (!$item instanceof SplFileInfo || !$item->isFile() || $item->isLink()) {
        continue;
    }

    $fullPath = $item->getPathname();
    $relative = str_replace('\\', '/', substr($fullPath, $rootLen));
    if ($relative === '') {
        continue;
    }

    $skip = false;
    foreach ($excludePrefixes as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            $skip = true;
            break;
        }
    }
    if ($skip || in_array($relative, $excludeFiles, true)) {
        continue;
    }

    if ($zip->addFile($fullPath, $relative)) {
        $count++;
    }
}

$zip->close();
$size = (int)@filesize($output);

echo "Package written: {$output}\n";
echo "Files: {$count}\n";
echo "Bytes: {$size}\n";
