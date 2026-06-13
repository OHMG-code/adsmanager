<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/RemoteReleaseManifestClient.php';
require_once __DIR__ . '/../services/AppUpdatePackageInstaller.php';

$assertions = [];

$assert = static function (bool $condition, string $label) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    $assertions[] = $label;
};

$manifest = [
    'latest_version' => '2026.06.13.1',
    'version_code' => 2026061301,
    'release_date' => '2026-06-13 21:00:00',
    'required_php' => '8.1',
    'min_app_version' => '2026.05.25.1',
    'package_url' => 'https://hosting2588063.online.pro/updates/crm/packages/crm_2026.06.13.1.zip',
    'checksum_sha256' => str_repeat('a', 64),
    'package_size' => '12.5 MB',
    'estimated_time' => '2-5 min',
    'changelog' => ['Zmiana źródła aktualizacji na serwer OHMG'],
];

$client = new RemoteReleaseManifestClient([
    'transport' => static fn (): array => [
        'ok' => true,
        'status' => 'success',
        'body' => json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ],
]);
$result = $client->fetch(CRM_DEFAULT_UPDATE_MANIFEST_URL, ['product' => 'crm', 'channel' => 'stable']);
$assert(!empty($result['ok']), 'ohmg manifest validates');
$latest = (array)($result['manifest']['latest_release'] ?? []);
$assert(($latest['download_url'] ?? '') === $manifest['package_url'], 'package_url normalized to download_url');
$assert(($latest['checksum_sha256'] ?? '') === $manifest['checksum_sha256'], 'checksum preserved');
$assert(($latest['source'] ?? '') === 'ohmg', 'manifest source marked as ohmg');
$installer = new AppUpdatePackageInstaller(dirname(__DIR__));
$plan = $installer->resolveRemotePackage((array)$result['manifest'], '2026.05.25.1');
$assert(!empty($plan['available']) && ($plan['error'] ?? '') === '', 'compatible installation accepts OHMG package plan');

$badManifest = $manifest;
$badManifest['package_url'] = 'https://example.com/crm.zip';
$badClient = new RemoteReleaseManifestClient([
    'transport' => static fn (): array => [
        'ok' => true,
        'status' => 'success',
        'body' => json_encode($badManifest, JSON_UNESCAPED_SLASHES),
    ],
]);
$badResult = $badClient->fetch(CRM_DEFAULT_UPDATE_MANIFEST_URL, ['product' => 'crm', 'channel' => 'stable']);
$assert(empty($badResult['ok']) && ($badResult['status'] ?? '') === 'invalid_manifest_package_url', 'foreign package host rejected');

$legacyManifest = [
    'schema_version' => 1,
    'product' => 'crm',
    'channel' => 'stable',
    'version' => '2026.06.13.1',
    'published_at' => '2026-06-13T19:00:00Z',
    'download_url' => 'https://github.com/OHMG-code/adsmanager/archive/refs/heads/main.zip',
    'changelog' => ['Bridge release'],
];
$legacyClient = new RemoteReleaseManifestClient([
    'transport' => static fn (): array => [
        'ok' => true,
        'status' => 'success',
        'body' => json_encode($legacyManifest, JSON_UNESCAPED_SLASHES),
    ],
]);
$legacyResult = $legacyClient->fetch(
    'https://raw.githubusercontent.com/OHMG-code/adsmanager/main/release-manifest/stable/manifest.json',
    ['product' => 'crm', 'channel' => 'stable']
);
$assert(!empty($legacyResult['ok']), 'legacy GitHub bridge manifest remains supported');
$assert(
    (($legacyResult['manifest']['latest_release']['download_url'] ?? '') === $legacyManifest['download_url']),
    'legacy GitHub download URL preserved'
);

$tmp = tempnam(sys_get_temp_dir(), 'crm-ohmg-sha-');
if ($tmp === false) {
    throw new RuntimeException('cannot create checksum fixture');
}
try {
    file_put_contents($tmp, 'trusted update package');
    $checksum = hash_file('sha256', $tmp);
    $assert(is_string($checksum) && $checksum !== '', 'checksum fixture created');
    $assert(!empty($installer->verifySha256($tmp, (string)$checksum)['ok']), 'matching checksum accepted');
    $assert(empty($installer->verifySha256($tmp, str_repeat('b', 64))['ok']), 'mismatching checksum rejected');
} finally {
    @unlink($tmp);
}

echo json_encode(['ok' => true, 'assertions' => $assertions], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
