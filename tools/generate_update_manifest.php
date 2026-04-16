#!/usr/bin/env php
<?php

declare(strict_types=1);

$rootDir = dirname(__DIR__);
$releasePath = $rootDir . '/release.json';

if (!is_file($releasePath) || !is_readable($releasePath)) {
    fwrite(STDERR, "Missing release.json\n");
    exit(1);
}

$releaseRaw = file_get_contents($releasePath);
if ($releaseRaw === false) {
    fwrite(STDERR, "Cannot read release.json\n");
    exit(1);
}

$release = json_decode($releaseRaw, true);
if (!is_array($release)) {
    fwrite(STDERR, "Invalid release.json\n");
    exit(1);
}

$options = getopt('', [
    'output::',
    'download-url::',
    'notes-url::',
    'published-at::',
    'version::',
    'changelog::',
    'repo::',
    'ref::',
]);
if ($options === false) {
    $options = [];
}

$output = trim((string)($options['output'] ?? ($rootDir . '/release-manifest/stable/manifest.json')));
$version = trim((string)($options['version'] ?? ($release['version'] ?? '')));
$notesUrl = trim((string)($options['notes-url'] ?? ($release['notes_url'] ?? '')));
$publishedAt = trim((string)($options['published-at'] ?? ($release['published_at'] ?? '')));
$rawChangelog = (string)($options['changelog'] ?? '');
$repo = trim((string)($options['repo'] ?? detectRepoFromGitRemote($rootDir)));
$ref = trim((string)($options['ref'] ?? 'main'));

$downloadUrl = trim((string)($options['download-url'] ?? ''));
if ($downloadUrl === '' && $repo !== '') {
    $downloadUrl = sprintf('https://github.com/%s/archive/refs/heads/%s.zip', $repo, rawurlencode($ref));
}

if ($version === '') {
    fwrite(STDERR, "Missing version (release.json.version or --version).\n");
    exit(1);
}
if ($downloadUrl === '') {
    fwrite(STDERR, "Missing --download-url (or provide --repo so script can build GitHub archive URL).\n");
    exit(1);
}
if (!isValidHttpsUrl($downloadUrl)) {
    fwrite(STDERR, "download-url must be HTTPS.\n");
    exit(1);
}
if ($notesUrl !== '' && !isValidHttpsUrl($notesUrl)) {
    fwrite(STDERR, "notes-url must be HTTPS.\n");
    exit(1);
}
if ($publishedAt === '') {
    $publishedAt = gmdate('c');
}

$changelog = [];
if ($rawChangelog !== '') {
    foreach (preg_split('/\r\n|\r|\n/', $rawChangelog) ?: [] as $line) {
        $line = trim((string)$line);
        if ($line !== '') {
            $changelog[] = $line;
        }
    }
}

$manifest = [
    'schema_version' => 1,
    'product' => 'crm',
    'channel' => (string)($release['channel'] ?? 'stable'),
    'version' => $version,
    'published_at' => $publishedAt,
    'download_url' => $downloadUrl,
    'notes_url' => $notesUrl,
    'changelog' => $changelog,
    'migration_hints' => [
        'has_migrations' => true,
        'filenames' => listMigrationFilenames($rootDir . '/sql/migrations'),
    ],
    'generated_at' => gmdate('c'),
];

$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    fwrite(STDERR, "Cannot create output directory: {$dir}\n");
    exit(1);
}

$encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($encoded === false) {
    fwrite(STDERR, "Cannot encode manifest JSON\n");
    exit(1);
}

if (file_put_contents($output, $encoded . "\n") === false) {
    fwrite(STDERR, "Cannot write manifest file: {$output}\n");
    exit(1);
}

echo "Manifest written to {$output}\n";
echo "download_url={$downloadUrl}\n";

function isValidHttpsUrl(string $url): bool
{
    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    return strtolower((string)($parts['scheme'] ?? '')) === 'https' && trim((string)($parts['host'] ?? '')) !== '';
}

/**
 * @return list<string>
 */
function listMigrationFilenames(string $migrationsDir): array
{
    if (!is_dir($migrationsDir)) {
        return [];
    }

    $items = scandir($migrationsDir);
    if (!is_array($items)) {
        return [];
    }

    $names = [];
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (!str_ends_with($item, '.sql')) {
            continue;
        }
        $names[] = $item;
    }

    sort($names, SORT_NATURAL);
    return $names;
}

function detectRepoFromGitRemote(string $rootDir): string
{
    $cmd = 'git -C ' . escapeshellarg($rootDir) . ' config --get remote.origin.url';
    $output = [];
    $exit = 0;
    @exec($cmd, $output, $exit);
    if ($exit !== 0 || $output === []) {
        return '';
    }

    $remote = trim((string)implode("\n", $output));
    if ($remote === '') {
        return '';
    }

    if (preg_match('~github\.com[:/]([^/\s]+/[^/\s]+?)(?:\.git)?$~i', $remote, $m) === 1) {
        return trim((string)$m[1]);
    }

    return '';
}
