<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/UpdatesStatusService.php';

const TEST_CHARSET = 'utf8mb4';

/**
 * @return array<string,mixed>
 */
function testDbConfig(): array
{
    $config = include __DIR__ . '/../config/db.local.php';
    if (!is_array($config)) {
        throw new RuntimeException('db.local.php did not return an array.');
    }

    return [
        'host' => (string)($config['host'] ?? 'mysql8'),
        'port' => (int)($config['port'] ?? 3306),
        'user' => (string)($config['user'] ?? ''),
        'pass' => (string)($config['pass'] ?? ''),
        'charset' => (string)($config['charset'] ?? TEST_CHARSET),
    ];
}

/**
 * @param array<string,mixed> $config
 */
function serverPdo(array $config): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%d;charset=%s', $config['host'], $config['port'], $config['charset']),
        (string)$config['user'],
        (string)$config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

/**
 * @param array<string,mixed> $config
 */
function databasePdo(array $config, string $database): PDO
{
    return new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $config['host'], $config['port'], $database, $config['charset']),
        (string)$config['user'],
        (string)$config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
}

function uniqueName(string $prefix): string
{
    return sprintf('%s_%s', $prefix, strtolower(bin2hex(random_bytes(4))));
}

function createDatabase(PDO $serverPdo, string $database, string $charset): void
{
    $serverPdo->exec(
        sprintf(
            'CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s_general_ci',
            str_replace('`', '``', $database),
            $charset,
            $charset
        )
    );
}

function dropDatabase(PDO $serverPdo, string $database): void
{
    $serverPdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', str_replace('`', '``', $database)));
}

function createTempDir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '_' . strtolower(bin2hex(random_bytes(4)));
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create temporary directory.');
    }

    return $dir;
}

function removeDir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $items = scandir($dir);
    if (!is_array($items)) {
        return;
    }
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            removeDir($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($dir);
}

function bootstrapAppMeta(PDO $pdo, string $installedVersion, string $dbVersion): void
{
    $pdo->exec(
        "CREATE TABLE app_meta (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            install_state VARCHAR(32) NOT NULL,
            installed_version VARCHAR(64) NULL,
            db_version VARCHAR(64) NULL,
            installed_at DATETIME NULL,
            baseline_id VARCHAR(128) NULL,
            release_channel VARCHAR(32) NULL,
            last_update_check_at DATETIME NULL,
            last_update_check_status VARCHAR(32) NULL,
            last_update_check_error TEXT NULL,
            last_available_version VARCHAR(64) NULL,
            last_available_published_at DATETIME NULL,
            last_available_notes_url VARCHAR(255) NULL,
            last_manifest_url VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    $stmt = $pdo->prepare(
        "INSERT INTO app_meta (id, install_state, installed_version, db_version, installed_at, release_channel, created_at, updated_at)
         VALUES (1, 'installed', :installed_version, :db_version, NOW(), 'stable', NOW(), NOW())"
    );
    $stmt->execute([
        ':installed_version' => $installedVersion,
        ':db_version' => $dbVersion,
    ]);
}

function updateAppMetaVersions(PDO $pdo, string $installedVersion, string $dbVersion): void
{
    $stmt = $pdo->prepare('UPDATE app_meta SET installed_version = :installed_version, db_version = :db_version WHERE id = 1');
    $stmt->execute([
        ':installed_version' => $installedVersion,
        ':db_version' => $dbVersion,
    ]);
}

/**
 * @return array<string,mixed>
 */
function manifestPayload(string $version): array
{
    return [
        'schema_version' => 1,
        'product' => 'crm',
        'channel' => 'stable',
        'generated_at' => '2026-05-12T19:35:00Z',
        'latest_version' => $version,
        'releases' => [
            [
                'version' => $version,
                'published_at' => '2026-05-12T19:35:00Z',
                'download_url' => 'https://github.com/OHMG-code/adsmanager/archive/refs/heads/main.zip',
                'changelog' => ['External lead forms in system settings'],
                'migration_hints' => [
                    'has_migrations' => true,
                    'filenames' => ['2026_05_12_01_lead_forms.sql'],
                ],
            ],
        ],
    ];
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

try {
    $localVersion = '2026.05.12.1';
    $oldVersion = '2026.05.11.2';
    $config = testDbConfig();
    $serverPdo = serverPdo($config);
    $database = uniqueName('crm_update_status');
    $rootDir = createTempDir('crm_update_status_root');
    $migrationsDir = createTempDir('crm_update_status_migrations');

    createDatabase($serverPdo, $database, (string)$config['charset']);

    try {
        $releaseJson = [
            'schema_version' => 1,
            'product' => 'crm',
            'version' => $localVersion,
            'channel' => 'stable',
            'published_at' => '2026-05-12T19:35:00Z',
            'baseline_id' => 'test',
            'manifest_url' => 'https://example.test/manifest.json',
            'notes_url' => '',
        ];
        file_put_contents(
            $rootDir . '/release.json',
            json_encode($releaseJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL
        );

        $pdo = databasePdo($config, $database);
        bootstrapAppMeta($pdo, $oldVersion, $oldVersion);

        $manifestClient = new RemoteReleaseManifestClient([
            'transport' => static function () use ($localVersion): array {
                return [
                    'ok' => true,
                    'status' => 'success',
                    'body' => json_encode(manifestPayload($localVersion), JSON_UNESCAPED_SLASHES),
                ];
            },
        ]);
        $service = new UpdatesStatusService(
            $rootDir,
            new ReleaseInfo($rootDir),
            $manifestClient,
            $migrationsDir,
            $rootDir . '/updates-migrations.log'
        );

        $status = $service->getStatus($pdo, ['force_refresh' => true]);
        assertTrue((string)$status['manifest_status'] === 'success', 'manifest should validate successfully');
        assertTrue(!empty($status['status_flags']['update_available']), 'remote latest must be compared against installed/db versions');
        assertTrue((string)$status['versions']['remote_comparison_version'] === $oldVersion, 'remote comparison should use recorded install baseline');

        updateAppMetaVersions($pdo, $localVersion, $localVersion);
        $status = $service->getStatus($pdo, ['force_refresh' => true]);
        assertTrue(empty($status['status_flags']['update_available']), 'matching installed/db version should not show a remote update');

        echo json_encode([
            'ok' => true,
            'assertions' => [
                'manifest_success',
                'remote_update_available_for_old_install',
                'remote_comparison_uses_recorded_baseline',
                'remote_update_hidden_for_current_install',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    } finally {
        dropDatabase($serverPdo, $database);
        removeDir($migrationsDir);
        removeDir($rootDir);
    }
} catch (Throwable $e) {
    $message = (string)$e->getMessage();
    $normalized = strtolower($message);
    $isDbUnavailable = str_contains($message, 'SQLSTATE[HY000] [2002]')
        || str_contains($normalized, 'connection refused')
        || str_contains($normalized, 'actively refused');
    if ($isDbUnavailable) {
        fwrite(STDOUT, "SKIP: MySQL unavailable for update_status_remote_manifest ({$message})" . PHP_EOL);
        exit(0);
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
