<?php

declare(strict_types=1);

require_once __DIR__ . '/ReleaseInfo.php';
require_once __DIR__ . '/RemoteReleaseManifestClient.php';
require_once __DIR__ . '/MigrationConsole.php';

final class UpdatesStatusService
{
    private const AUTO_REFRESH_AFTER_SECONDS = 86400;

    private string $rootDir;
    private string $cacheDir;
    private string $migrationsDir;
    private string $migrationLogPath;
    private ReleaseInfo $releaseInfo;
    private RemoteReleaseManifestClient $manifestClient;

    public function __construct(
        ?string $rootDir = null,
        ?ReleaseInfo $releaseInfo = null,
        ?RemoteReleaseManifestClient $manifestClient = null,
        ?string $migrationsDir = null,
        ?string $migrationLogPath = null
    ) {
        $this->rootDir = $rootDir !== null ? rtrim($rootDir, '/') : dirname(__DIR__);
        $this->cacheDir = $this->rootDir . '/storage/cache';
        $this->migrationsDir = $migrationsDir ?? ($this->rootDir . '/sql/migrations');
        $this->migrationLogPath = $migrationLogPath ?? ($this->rootDir . '/storage/logs/updates-migrations.log');
        $this->releaseInfo = $releaseInfo ?? new ReleaseInfo($this->rootDir);
        $this->manifestClient = $manifestClient ?? new RemoteReleaseManifestClient();
    }

    /**
     * @param array{force_refresh?:bool,allow_auto_refresh?:bool} $options
     * @return array<string,mixed>
     */
    public function getStatus(PDO $pdo, array $options = []): array
    {
        $forceRefresh = !empty($options['force_refresh']);
        $allowAutoRefresh = !array_key_exists('allow_auto_refresh', $options) || (bool)$options['allow_auto_refresh'];

        $release = $this->releaseInfo->load();
        $appMeta = $this->readAppMeta($pdo);
        $cachedManifest = $this->readManifestCache($release);
        $manualResult = null;
        $autoRefreshTriggered = false;

        if ($forceRefresh) {
            $manualResult = $this->refreshManifest($pdo, $release, $appMeta, 'manual');
            $appMeta = $manualResult['app_meta_after'];
            $cachedManifest = $manualResult['cached_manifest'];
        } elseif ($allowAutoRefresh && $this->shouldAutoRefresh($release, $appMeta)) {
            $manualResult = $this->refreshManifest($pdo, $release, $appMeta, 'auto');
            $autoRefreshTriggered = true;
            $appMeta = $manualResult['app_meta_after'];
            $cachedManifest = $manualResult['cached_manifest'];
        }

        $manifest = $this->buildManifestView($release, $appMeta, $cachedManifest, $manualResult, $autoRefreshTriggered);
        $migrations = $this->collectPendingMigrations($pdo);
        $statusFlags = $this->buildStatusFlags($release, $appMeta, $manifest, $migrations);
        $versions = $this->buildVersionMatrix($release, $appMeta, $migrations, $statusFlags);
        $overallStatus = $this->resolveOverallStatus($statusFlags, $manifest, $migrations);

        return [
            'release' => $release,
            'app_meta' => $appMeta,
            'manifest' => $manifest,
            'migrations' => $migrations,
            'versions' => $versions,
            'status_flags' => $statusFlags,
            'overall_status' => $overallStatus,
            'local_status' => $overallStatus,
            'manifest_status' => (string)($manifest['status'] ?? 'unknown'),
            'manifest_configured' => !empty($manifest['configured']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function refreshManifest(PDO $pdo, array $release, array $appMeta, string $source): array
    {
        $result = [
            'source' => $source,
            'performed' => false,
            'persisted' => false,
            'check' => null,
            'app_meta_after' => $appMeta,
            'cached_manifest' => $this->readManifestCache($release),
        ];

        $manifestUrl = trim((string)($release['manifest_url'] ?? ''));
        if (!$release['ok']) {
            return $result;
        }

        $check = $this->manifestClient->fetch($manifestUrl, [
            'product' => (string)($release['product'] ?? 'crm'),
            'channel' => (string)($release['channel'] ?? ReleaseInfo::DEFAULT_CHANNEL),
        ]);

        $result['performed'] = true;
        $result['check'] = $check;

        if ($check['ok']) {
            $this->writeManifestCache($release, (array)$check['manifest'], (string)$check['fetched_at']);
        }

        if ($appMeta['supports_update_summary']) {
            $this->persistCheckSummary($pdo, $release, $check);
            $result['persisted'] = true;
            $result['app_meta_after'] = $this->readAppMeta($pdo);
        }

        $result['cached_manifest'] = $this->readManifestCache($release);
        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildManifestView(
        array $release,
        array $appMeta,
        ?array $cachedManifest,
        ?array $refreshResult,
        bool $autoRefreshTriggered
    ): array {
        $configured = !empty($release['ok']) && trim((string)($release['manifest_url'] ?? '')) !== '';
        $validUrl = $configured && ReleaseInfo::isValidHttpsUrl((string)$release['manifest_url']);

        $storedStatus = trim((string)($appMeta['last_update_check_status'] ?? ''));
        $storedError = trim((string)($appMeta['last_update_check_error'] ?? ''));
        $checkedAt = trim((string)($appMeta['last_update_check_at'] ?? ''));
        $latestVersion = trim((string)($appMeta['last_available_version'] ?? ''));
        $latestPublishedAt = trim((string)($appMeta['last_available_published_at'] ?? ''));
        $latestNotesUrl = trim((string)($appMeta['last_available_notes_url'] ?? ''));

        if (is_array($refreshResult) && !empty($refreshResult['performed'])) {
            $check = $refreshResult['check'] ?? [];
            if (is_array($check)) {
                $storedStatus = trim((string)($check['status'] ?? $storedStatus));
                $storedError = trim((string)($check['error'] ?? ''));
                $checkedAt = gmdate('c');
                if (!empty($check['ok']) && isset($check['manifest']) && is_array($check['manifest'])) {
                    $latestVersion = trim((string)($check['manifest']['latest_version'] ?? $latestVersion));
                    $latestRelease = (array)($check['manifest']['latest_release'] ?? []);
                    $latestPublishedAt = trim((string)($latestRelease['published_at'] ?? $latestPublishedAt));
                    $latestNotesUrl = trim((string)($latestRelease['notes_url'] ?? $latestNotesUrl));
                }
            }
        }

        $latestRelease = null;
        if (is_array($cachedManifest)) {
            $cachedLatest = (array)($cachedManifest['latest_release'] ?? []);
            if (($cachedLatest['version'] ?? '') === $latestVersion || $latestVersion === '') {
                $latestRelease = $cachedLatest;
            }
        }

        if (!$release['ok']) {
            $displayStatus = 'local_release_invalid';
        } elseif (!$configured) {
            $displayStatus = 'manifest_not_configured';
        } elseif (!$validUrl) {
            $displayStatus = 'invalid_manifest_url';
        } else {
            $displayStatus = $storedStatus !== '' ? $storedStatus : 'not_checked';
        }

        return [
            'configured' => $configured,
            'valid_url' => $validUrl,
            'url' => trim((string)($release['manifest_url'] ?? '')),
            'status' => $displayStatus,
            'checked_at' => $checkedAt,
            'error' => $storedError,
            'latest_version' => $latestVersion,
            'latest_published_at' => $latestPublishedAt,
            'latest_notes_url' => $latestNotesUrl,
            'latest_release' => $latestRelease,
            'cache_available' => is_array($cachedManifest),
            'cache_fetched_at' => is_array($cachedManifest) ? trim((string)($cachedManifest['fetched_at'] ?? '')) : '',
            'auto_refresh_triggered' => $autoRefreshTriggered,
            'last_check_was_manual' => is_array($refreshResult) && ($refreshResult['source'] ?? '') === 'manual',
            'last_check_persisted' => is_array($refreshResult) && !empty($refreshResult['persisted']),
        ];
    }

    /**
     * @return array<string,bool>
     */
    private function buildStatusFlags(array $release, array $appMeta, array $manifest, array $migrations): array
    {
        $localVersion = $this->resolveCodeVersion($release);
        $installedVersion = trim((string)($appMeta['installed_version'] ?? ''));
        $dbVersion = trim((string)($appMeta['db_version'] ?? ''));
        $pendingMigrations = !empty($migrations['has_pending']);

        $flags = [
            'local_version_unknown' => $localVersion === '' || $localVersion === '0.0.0.0',
            'installed_version_missing' => $installedVersion === '',
            'db_version_missing' => $dbVersion === '',
            'local_code_ahead_of_recorded_install' => false,
            'local_code_ahead_of_db_version' => false,
            'app_db_version_mismatch' => false,
            'migrations_directory_unavailable' => empty($migrations['migrations_dir_ok']),
            'update_available' => false,
            'manifest_unreachable' => false,
            'pending_migrations_detected' => $pendingMigrations,
            'update_required' => false,
        ];

        $installComparison = $this->compareVersions($localVersion, $installedVersion);
        if ($installComparison !== null) {
            $flags['local_code_ahead_of_recorded_install'] = $installComparison > 0;
        }

        $dbComparison = $this->compareVersions($localVersion, $dbVersion);
        if ($dbComparison !== null) {
            $flags['local_code_ahead_of_db_version'] = $dbComparison > 0;
            $flags['app_db_version_mismatch'] = $dbComparison !== 0;
        } elseif ($localVersion !== '' && $dbVersion !== '') {
            $flags['app_db_version_mismatch'] = strcasecmp($localVersion, $dbVersion) !== 0;
        }

        $latestVersion = trim((string)($manifest['latest_version'] ?? ''));
        $latestComparison = $this->compareVersions($latestVersion, $localVersion);
        if ($latestComparison !== null) {
            $flags['update_available'] = $latestComparison > 0;
        }

        $flags['manifest_unreachable'] = in_array(
            (string)($manifest['status'] ?? ''),
            ['network_error', 'http_error', 'curl_unavailable', 'curl_init_failed', 'manifest_too_large'],
            true
        );
        $flags['update_required'] = $pendingMigrations
            || $flags['app_db_version_mismatch']
            || $flags['db_version_missing']
            || $flags['local_code_ahead_of_db_version'];

        return $flags;
    }

    private function resolveOverallStatus(array $flags, array $manifest, array $migrations): string
    {
        if ($flags['local_version_unknown']) {
            return 'local_version_unknown';
        }
        if ($flags['migrations_directory_unavailable']) {
            return 'migrations_directory_unavailable';
        }
        if ($flags['update_required']) {
            return 'update_required';
        }
        return 'up_to_date';
    }

    private function shouldAutoRefresh(array $release, array $appMeta): bool
    {
        if (empty($release['ok']) || !$appMeta['supports_update_summary']) {
            return false;
        }

        $manifestUrl = trim((string)($release['manifest_url'] ?? ''));
        if ($manifestUrl === '' || !ReleaseInfo::isValidHttpsUrl($manifestUrl)) {
            return false;
        }

        $checkedAt = trim((string)($appMeta['last_update_check_at'] ?? ''));
        if ($checkedAt === '') {
            return true;
        }

        try {
            $checked = new DateTimeImmutable($checkedAt);
        } catch (Throwable $e) {
            return true;
        }

        return (time() - $checked->getTimestamp()) >= self::AUTO_REFRESH_AFTER_SECONDS;
    }

    /**
     * @return array<string,mixed>
     */
    private function readAppMeta(PDO $pdo): array
    {
        $columns = $this->getTableColumns($pdo, 'app_meta');
        $tableExists = $columns !== [];
        $selectable = array_values(array_intersect([
            'install_state',
            'installed_version',
            'db_version',
            'installed_at',
            'baseline_id',
            'release_channel',
            'last_update_check_at',
            'last_update_check_status',
            'last_update_check_error',
            'last_available_version',
            'last_available_published_at',
            'last_available_notes_url',
            'last_manifest_url',
            'created_at',
            'updated_at',
        ], $columns));

        $row = [];
        if ($tableExists && $selectable !== []) {
            $sql = 'SELECT ' . implode(', ', $selectable) . ' FROM app_meta WHERE id = 1 LIMIT 1';
            $stmt = $pdo->query($sql);
            $row = $stmt ? (array)($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
            if ($stmt) {
                $stmt->closeCursor();
            }
        }

        $supportsUpdateSummary = $tableExists && count(array_intersect([
            'last_update_check_at',
            'last_update_check_status',
            'last_update_check_error',
            'last_available_version',
            'last_available_published_at',
            'last_available_notes_url',
            'last_manifest_url',
        ], $columns)) === 7;
        $supportsDbVersion = $tableExists && in_array('db_version', $columns, true);

        return [
            'table_exists' => $tableExists,
            'record_exists' => $row !== [],
            'columns' => $columns,
            'supports_update_summary' => $supportsUpdateSummary,
            'supports_db_version' => $supportsDbVersion,
            'install_state' => trim((string)($row['install_state'] ?? '')),
            'installed_version' => trim((string)($row['installed_version'] ?? '')),
            'db_version' => trim((string)($row['db_version'] ?? '')),
            'installed_at' => trim((string)($row['installed_at'] ?? '')),
            'baseline_id' => trim((string)($row['baseline_id'] ?? '')),
            'release_channel' => trim((string)($row['release_channel'] ?? '')),
            'last_update_check_at' => trim((string)($row['last_update_check_at'] ?? '')),
            'last_update_check_status' => trim((string)($row['last_update_check_status'] ?? '')),
            'last_update_check_error' => trim((string)($row['last_update_check_error'] ?? '')),
            'last_available_version' => trim((string)($row['last_available_version'] ?? '')),
            'last_available_published_at' => trim((string)($row['last_available_published_at'] ?? '')),
            'last_available_notes_url' => trim((string)($row['last_available_notes_url'] ?? '')),
            'last_manifest_url' => trim((string)($row['last_manifest_url'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed> $release
     * @param array<string,mixed> $check
     */
    private function persistCheckSummary(PDO $pdo, array $release, array $check): void
    {
        $this->ensureAppMetaRecord($pdo, $release);

        $params = [
            ':id' => 1,
            ':release_channel' => trim((string)($release['channel'] ?? '')) ?: null,
            ':baseline_id' => trim((string)($release['baseline_id'] ?? '')) ?: null,
            ':checked_at' => date('Y-m-d H:i:s'),
            ':status' => trim((string)($check['status'] ?? 'unknown')) ?: 'unknown',
            ':error' => trim((string)($check['error'] ?? '')) ?: null,
            ':manifest_url' => trim((string)($release['manifest_url'] ?? '')) ?: null,
            ':latest_version' => null,
            ':latest_published_at' => null,
            ':latest_notes_url' => null,
        ];

        if (!empty($check['ok']) && isset($check['manifest']) && is_array($check['manifest'])) {
            $latestRelease = (array)($check['manifest']['latest_release'] ?? []);
            $params[':latest_version'] = trim((string)($check['manifest']['latest_version'] ?? '')) ?: null;
            $params[':latest_published_at'] = $this->normalizeSqlDatetime((string)($latestRelease['published_at'] ?? ''));
            $params[':latest_notes_url'] = trim((string)($latestRelease['notes_url'] ?? '')) ?: null;
            $params[':error'] = null;
        }

        $stmt = $pdo->prepare(
            'UPDATE app_meta
             SET baseline_id = COALESCE(:baseline_id, baseline_id),
                 release_channel = COALESCE(:release_channel, release_channel),
                 last_update_check_at = :checked_at,
                 last_update_check_status = :status,
                 last_update_check_error = :error,
                 last_manifest_url = :manifest_url,
                 last_available_version = COALESCE(:latest_version, last_available_version),
                 last_available_published_at = COALESCE(:latest_published_at, last_available_published_at),
                 last_available_notes_url = COALESCE(:latest_notes_url, last_available_notes_url),
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute($params);
    }

    private function ensureAppMetaRecord(PDO $pdo, array $release): void
    {
        $localVersion = $this->resolveCodeVersion($release);
        $stmt = $pdo->prepare(
            "INSERT INTO app_meta (id, install_state, installed_version, db_version, installed_at, baseline_id, release_channel, created_at, updated_at)
             VALUES (1, 'installed', :installed_version, :db_version, NULL, :baseline_id, :release_channel, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                installed_version = COALESCE(VALUES(installed_version), installed_version),
                db_version = COALESCE(db_version, VALUES(db_version)),
                baseline_id = COALESCE(VALUES(baseline_id), baseline_id),
                release_channel = COALESCE(VALUES(release_channel), release_channel),
                updated_at = NOW()"
        );
        $stmt->execute([
            ':installed_version' => $localVersion !== '' ? $localVersion : null,
            ':db_version' => $localVersion !== '' ? $localVersion : null,
            ':baseline_id' => trim((string)($release['baseline_id'] ?? '')) ?: null,
            ':release_channel' => trim((string)($release['channel'] ?? '')) ?: null,
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function getTableColumns(PDO $pdo, string $table): array
    {
        try {
            $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
            $exists = (bool)($stmt && $stmt->fetchColumn());
            if ($stmt) {
                $stmt->closeCursor();
            }
            if (!$exists) {
                return [];
            }

            $columnsStmt = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            if ($columnsStmt === false) {
                return [];
            }

            $columns = [];
            while (($row = $columnsStmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $field = trim((string)($row['Field'] ?? ''));
                if ($field !== '') {
                    $columns[] = $field;
                }
            }
            $columnsStmt->closeCursor();
            return $columns;
        } catch (Throwable $e) {
            return [];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function collectPendingMigrations(PDO $pdo): array
    {
        $migrationsDirExists = is_dir($this->migrationsDir);
        $migrationsDirReadable = $migrationsDirExists && is_readable($this->migrationsDir);
        $console = new MigrationConsole(
            $pdo,
            $this->migrationsDir,
            $this->migrationLogPath,
            'updates',
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        );

        $entries = $console->listMigrations();
        $pending = array_values(array_filter(
            $entries,
            static fn (array $entry): bool => !$entry['applied'] && $entry['skip_reason'] === null
        ));

        return [
            'has_pending' => $pending !== [],
            'pending_count' => count($pending),
            'pending' => array_values(array_map(
                static fn (array $entry): string => (string)$entry['filename'],
                array_slice($pending, 0, 10)
            )),
            'migrations_dir' => $this->migrationsDir,
            'migrations_dir_ok' => $migrationsDirReadable,
            'migrations_dir_exists' => $migrationsDirExists,
            'migrations_dir_readable' => $migrationsDirReadable,
            'connection' => $console->getConnectionInfo(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildVersionMatrix(array $release, array $appMeta, array $migrations, array $statusFlags): array
    {
        $appVersion = $this->resolveCodeVersion($release);
        $dbVersion = trim((string)($appMeta['db_version'] ?? ''));
        $targetVersion = $appVersion;
        $comparison = $this->compareVersions($appVersion, $dbVersion);

        return [
            'app_version' => $appVersion,
            'db_version' => $dbVersion,
            'target_version' => $targetVersion,
            'pending_migrations' => (int)($migrations['pending_count'] ?? 0),
            'requires_update' => !empty($statusFlags['update_required']),
            'versions_match' => $comparison === 0 && $appVersion !== '' && $dbVersion !== '',
            'comparison' => $comparison,
        ];
    }

    private function resolveCodeVersion(array $release): string
    {
        $definedVersion = defined('APP_VERSION') ? trim((string)APP_VERSION) : '';
        if ($definedVersion !== '') {
            return $definedVersion;
        }
        return trim((string)($release['version'] ?? ''));
    }

    private function compareVersions(string $left, string $right): ?int
    {
        $left = trim($left);
        $right = trim($right);
        if ($left === '' || $right === '') {
            return null;
        }
        if (ReleaseInfo::isValidCalver($left) && ReleaseInfo::isValidCalver($right)) {
            return ReleaseInfo::compareVersions($left, $right);
        }
        return version_compare($left, $right);
    }

    /**
     * @param array<string,mixed> $release
     */
    private function readManifestCache(array $release): ?array
    {
        $path = $this->manifestCachePath($release);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $release
     * @param array<string,mixed> $manifest
     */
    private function writeManifestCache(array $release, array $manifest, string $fetchedAt): void
    {
        $path = $this->manifestCachePath($release);
        if ($path === '') {
            return;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }

        $payload = [
            'fetched_at' => $fetchedAt,
            'manifest_url' => (string)($release['manifest_url'] ?? ''),
            'channel' => (string)($release['channel'] ?? ''),
            'latest_version' => (string)($manifest['latest_version'] ?? ''),
            'latest_release' => (array)($manifest['latest_release'] ?? []),
            'releases' => (array)($manifest['releases'] ?? []),
        ];

        @file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n", LOCK_EX);
    }

    /**
     * @param array<string,mixed> $release
     */
    private function manifestCachePath(array $release): string
    {
        $url = trim((string)($release['manifest_url'] ?? ''));
        $channel = trim((string)($release['channel'] ?? ReleaseInfo::DEFAULT_CHANNEL)) ?: ReleaseInfo::DEFAULT_CHANNEL;
        if ($url === '') {
            return '';
        }

        return sprintf(
            '%s/update-manifest-%s-%s.json',
            $this->cacheDir,
            preg_replace('/[^a-z0-9._-]+/i', '-', strtolower($channel)) ?: 'stable',
            substr(hash('sha256', $url), 0, 12)
        );
    }

    public static function autoRefreshAfterSeconds(): int
    {
        return self::AUTO_REFRESH_AFTER_SECONDS;
    }

    private function normalizeSqlDatetime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}
