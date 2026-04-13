<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/ReleaseInfo.php';

$tests = 0;
$failures = 0;

function assertSameValue(mixed $expected, mixed $actual, string $label): void
{
    global $tests, $failures;
    $tests++;
    if ($expected !== $actual) {
        $failures++;
        echo 'FAIL: ' . $label . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true) . "\n";
    }
}

function removeTree(string $path): void
{
    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    $entries = scandir($path);
    if (!is_array($entries)) {
        @rmdir($path);
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        removeTree($path . '/' . $entry);
    }
    @rmdir($path);
}

$tmpRoot = sys_get_temp_dir() . '/crm_release_info_' . bin2hex(random_bytes(4));
$previousEnv = getenv('UPDATE_MANIFEST_URL');
putenv('UPDATE_MANIFEST_URL');

try {
    if (!mkdir($tmpRoot . '/config', 0775, true) && !is_dir($tmpRoot . '/config')) {
        throw new RuntimeException('Cannot create temporary config directory.');
    }

    $releasePayload = [
        'schema_version' => 1,
        'product' => 'crm',
        'version' => '2026.04.13.1',
        'channel' => 'stable',
        'published_at' => '2026-04-13T10:00:00Z',
        'baseline_id' => 'test_baseline',
        'manifest_url' => 'https://release.example/manifest.json',
        'notes_url' => 'https://release.example/notes',
    ];
    file_put_contents(
        $tmpRoot . '/release.json',
        json_encode($releasePayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
    );

    $release = (new ReleaseInfo($tmpRoot))->load();
    assertSameValue(true, (bool)($release['ok'] ?? false), 'release:ok');
    assertSameValue('https://release.example/manifest.json', (string)($release['manifest_url'] ?? ''), 'release:manifest_url');
    assertSameValue('release_json', (string)($release['manifest_url_source'] ?? ''), 'release:manifest_source');

    file_put_contents(
        $tmpRoot . '/config/db.local.php',
        "<?php\nreturn ['update_manifest_url' => 'https://config.example/manifest.json'];\n"
    );
    $release = (new ReleaseInfo($tmpRoot))->load();
    assertSameValue(true, (bool)($release['ok'] ?? false), 'config:ok');
    assertSameValue('https://config.example/manifest.json', (string)($release['manifest_url'] ?? ''), 'config:manifest_url');
    assertSameValue('config', (string)($release['manifest_url_source'] ?? ''), 'config:manifest_source');

    @unlink($tmpRoot . '/config/db.local.php');
    putenv('UPDATE_MANIFEST_URL=https://env.example/manifest.json');
    $release = (new ReleaseInfo($tmpRoot))->load();
    assertSameValue(true, (bool)($release['ok'] ?? false), 'env:ok');
    assertSameValue('https://env.example/manifest.json', (string)($release['manifest_url'] ?? ''), 'env:manifest_url');
    assertSameValue('config', (string)($release['manifest_url_source'] ?? ''), 'env:manifest_source');
} finally {
    if ($previousEnv === false) {
        putenv('UPDATE_MANIFEST_URL');
    } else {
        putenv('UPDATE_MANIFEST_URL=' . $previousEnv);
    }
    removeTree($tmpRoot);
}

echo 'Tests:' . $tests . ' Failures:' . $failures . "\n";
if ($failures > 0) {
    exit(1);
}

