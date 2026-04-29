<?php

declare(strict_types=1);

require_once __DIR__ . '/ReleaseInfo.php';
require_once __DIR__ . '/UpdatesStatusService.php';
require_once __DIR__ . '/MigrationConsole.php';
require_once __DIR__ . '/AppUpdatePackageInstaller.php';
require_once __DIR__ . '/worker_lock.php';

final class AppUpdateOrchestrator
{
    public const LOCK_NAME = 'app_update';
    public const LOCK_TTL_SECONDS = 180;
    public const HEARTBEAT_INTERVAL_SECONDS = 30;
    public const ABANDONED_AFTER_SECONDS = 180;
    public const MIGRATION_BATCH_FILES = 3;
    public const MIGRATION_BATCH_SECONDS = 8;
    public const STATE_DOWNLOAD = 'download_package';
    public const STATE_BACKUP_DB = 'backup_database';
    public const STATE_BACKUP_FILES = 'backup_files';
    public const STATE_EXTRACT = 'extract_package';
    public const STATE_APPLY = 'apply_files';
    public const STATE_MIGRATIONS = 'migrations';

    private const RUNTIME_COLUMNS = [
        'update_state',
        'update_run_id',
        'update_target_version',
        'update_started_at',
        'update_completed_at',
        'update_last_heartbeat_at',
        'update_lock_expires_at',
        'update_lock_owner',
        'update_maintenance_mode',
        'update_backup_confirmed_at',
        'update_backup_confirmed_by',
        'update_total_migrations',
        'update_completed_migrations',
        'update_last_error',
    ];

    private string $rootDir;
    private string $migrationsDir;
    private string $migrationLogPath;
    private UpdatesStatusService $statusService;
    private ReleaseInfo $releaseInfo;
    private AppUpdatePackageInstaller $packageInstaller;

    public function __construct(
        ?string $rootDir = null,
        ?string $migrationsDir = null,
        ?string $migrationLogPath = null,
        ?UpdatesStatusService $statusService = null,
        ?ReleaseInfo $releaseInfo = null,
        ?AppUpdatePackageInstaller $packageInstaller = null
    ) {
        $this->rootDir = $rootDir !== null ? rtrim($rootDir, '/') : dirname(__DIR__);
        $this->migrationsDir = $migrationsDir ?? ($this->rootDir . '/sql/migrations');
        $this->migrationLogPath = $migrationLogPath ?? ($this->rootDir . '/storage/logs/app-update-migrations.log');
        $this->statusService = $statusService ?? new UpdatesStatusService(
            $this->rootDir,
            null,
            null,
            $this->migrationsDir,
            $this->migrationLogPath
        );
        $this->releaseInfo = $releaseInfo ?? new ReleaseInfo($this->rootDir);
        $this->packageInstaller = $packageInstaller ?? new AppUpdatePackageInstaller($this->rootDir);
    }

    /**
     * @param array<string,mixed>|null $user
     * @param array{force_manifest_refresh?:bool,allow_auto_refresh?:bool,log_limit?:int} $options
     * @return array<string,mixed>
     */
    public function getDashboard(PDO $pdo, ?array $user = null, array $options = []): array
    {
        $status = $this->statusService->getStatus($pdo, [
            'force_refresh' => !empty($options['force_manifest_refresh']),
            'allow_auto_refresh' => !array_key_exists('allow_auto_refresh', $options) || (bool)$options['allow_auto_refresh'],
        ]);

        $runtime = $this->readRuntime($pdo, $status);
        $logLimit = max(5, (int)($options['log_limit'] ?? 20));
        $logs = $this->readLogEntries($pdo, (string)($runtime['run_id'] ?? ''), $logLimit);

        return [
            'status' => $status,
            'runtime' => $runtime,
            'logs' => $logs,
            'policy' => $this->lockPolicy(),
            'actor' => $this->actorSummary($user),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function refreshRemoteStatus(PDO $pdo, ?array $user = null): array
    {
        return $this->getDashboard($pdo, $user, [
            'force_manifest_refresh' => true,
            'allow_auto_refresh' => false,
        ]);
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function start(PDO $pdo, array $user, bool $backupConfirmed): array
    {
        $dashboard = $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]);
        $runtime = (array)$dashboard['runtime'];
        $preflight = $this->validateStart($dashboard, $backupConfirmed);
        if (!$preflight['ok']) {
            return [
                'ok' => false,
                'message' => (string)$preflight['message'],
                'errors' => $preflight['errors'],
                'dashboard' => $dashboard,
            ];
        }

        $release = (array)$dashboard['status']['release'];
        $versions = (array)($dashboard['status']['versions'] ?? []);
        $flags = (array)($dashboard['status']['status_flags'] ?? []);
        $manifest = (array)($dashboard['status']['manifest'] ?? []);
        $targetVersion = trim((string)($versions['target_version'] ?? ''));
        if ($targetVersion === '') {
            $targetVersion = trim((string)($release['version'] ?? ''));
        }
        $pendingCount = (int)($dashboard['status']['migrations']['pending_count'] ?? 0);
        $runId = $this->newRunId();

        $localVersion = trim((string)($versions['app_version'] ?? (defined('APP_VERSION') ? APP_VERSION : '')));
        $remotePlan = $this->packageInstaller->resolveRemotePackage($manifest, $localVersion);
        $shouldUseRemotePackage = !empty($flags['update_available']);

        if ($shouldUseRemotePackage && !empty($remotePlan['error'])) {
            return [
                'ok' => false,
                'message' => (string)$remotePlan['error'],
                'errors' => ['manifest_download_url_missing_or_invalid'],
                'dashboard' => $dashboard,
            ];
        }

        $runMode = 'local_finalize';
        $runStage = self::STATE_MIGRATIONS;
        if ($shouldUseRemotePackage) {
            $runMode = 'remote_package';
            $runStage = self::STATE_DOWNLOAD;
            $targetVersion = trim((string)($remotePlan['latest_version'] ?? ''));
        }

        $lock = new WorkerLock($pdo);
        $lockAttempt = $lock->acquire(self::LOCK_NAME, $runId, self::LOCK_TTL_SECONDS);
        if (empty($lockAttempt['ok'])) {
            return [
                'ok' => false,
                'message' => 'Inna sesja aktualizacji jest już aktywna. Odśwież ekran i spróbuj ponownie po wygaśnięciu locka.',
                'errors' => ['already_locked'],
                'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
            ];
        }

        $this->ensureAppMetaRecord($pdo, $release);
        $completedMigrations = 0;
        $workspace = $this->packageInstaller->runWorkspace($runId);
        $this->writeRuntime($pdo, [
            'update_state' => 'running',
            'update_run_id' => $runId,
            'update_target_version' => $targetVersion !== '' ? $targetVersion : null,
            'update_started_at' => $this->nowSql(),
            'update_completed_at' => null,
            'update_last_heartbeat_at' => $this->nowSql(),
            'update_lock_expires_at' => $this->futureSql(self::LOCK_TTL_SECONDS),
            'update_lock_owner' => $runId,
            'update_maintenance_mode' => 1,
            'update_backup_confirmed_at' => $this->nowSql(),
            'update_backup_confirmed_by' => $this->actorLabel($user),
            'update_total_migrations' => $pendingCount,
            'update_completed_migrations' => $completedMigrations,
            'update_last_error' => null,
        ]);
        $this->writeRunState($runId, [
            'run_id' => $runId,
            'mode' => $runMode,
            'stage' => $runStage,
            'target_version' => $targetVersion,
            'download_url' => $runMode === 'remote_package' ? (string)($remotePlan['download_url'] ?? '') : '',
            'download_path' => $workspace['download_path'],
            'extract_dir' => $workspace['extract_dir'],
            'source_root' => '',
            'db_backup_path' => $workspace['db_backup_path'],
            'files_backup_path' => $workspace['files_backup_path'],
            'applied_files' => 0,
            'started_at' => gmdate('c'),
        ]);

        $actor = $this->actorSummary($user);
        $this->logEvent($pdo, $runId, 'validation', 'start_requested', 'success', 'Uruchomiono proces aktualizacji.', $actor, [
            'local_version' => $targetVersion,
            'pending_migrations' => $pendingCount,
            'installed_version' => (string)($dashboard['status']['app_meta']['installed_version'] ?? ''),
            'db_version' => (string)($dashboard['status']['app_meta']['db_version'] ?? ''),
        ], [
            'mode' => $runMode,
            'stage' => $runStage,
            'maintenance_mode' => true,
        ]);
        $this->logEvent($pdo, $runId, 'backup', 'backup_confirmed', 'success', 'Admin potwierdził wykonanie backupu przed aktualizacją.', $actor, [
            'backup_confirmation_required' => true,
        ], [
            'confirmed_by' => $this->actorLabel($user),
        ]);
        $this->logEvent($pdo, $runId, 'lock', 'lock_acquired', 'success', 'Przyznano lock aktualizacji.', $actor, [
            'lock_name' => self::LOCK_NAME,
            'ttl_seconds' => self::LOCK_TTL_SECONDS,
        ], [
            'locked_by' => $runId,
            'expires_at' => $this->futureIso(self::LOCK_TTL_SECONDS),
        ]);
        $this->logEvent($pdo, $runId, 'maintenance', 'maintenance_enabled', 'success', 'Włączono maintenance mode dla update flow.', $actor, [
            'allowed_paths' => $this->allowedPathHints(),
        ], [
            'maintenance_mode' => true,
        ]);

        if ($runMode === 'remote_package') {
            $this->logEvent($pdo, $runId, 'download', 'queued', 'success', 'Wykryto nowszą wersję i zaplanowano pobranie paczki aktualizacji.', $actor, [
                'target_version' => $targetVersion,
            ], [
                'download_url' => (string)($remotePlan['download_url'] ?? ''),
            ]);
        }

        return [
            'ok' => true,
            'message' => $runMode === 'remote_package'
                ? 'Aktualizacja została rozpoczęta. System pobierze paczkę, wykona backup, podmieni pliki i uruchomi migracje.'
                : 'Aktualizacja została rozpoczęta. Kolejne batch-e migracji będą orkiestrwane automatycznie na ekranie statusu.',
            'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function resume(PDO $pdo, array $user, bool $backupConfirmed = false): array
    {
        $dashboard = $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]);
        $runtime = (array)$dashboard['runtime'];
        if (empty($runtime['can_resume'])) {
            return [
                'ok' => false,
                'message' => 'Brak porzuconej albo nieudanej aktualizacji do wznowienia.',
                'errors' => ['resume_not_available'],
                'dashboard' => $dashboard,
            ];
        }

        $runId = trim((string)($runtime['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'ok' => false,
                'message' => 'Nie udało się ustalić identyfikatora poprzedniego runu aktualizacji.',
                'errors' => ['missing_run_id'],
                'dashboard' => $dashboard,
            ];
        }

        if (empty($runtime['backup_confirmed']) && !$backupConfirmed) {
            return [
                'ok' => false,
                'message' => 'Wznowienie wymaga wcześniejszego potwierdzenia backupu.',
                'errors' => ['backup_confirmation_required'],
                'dashboard' => $dashboard,
            ];
        }

        $lock = new WorkerLock($pdo);
        $lockAttempt = $lock->acquire(self::LOCK_NAME, $runId, self::LOCK_TTL_SECONDS);
        if (empty($lockAttempt['ok'])) {
            return [
                'ok' => false,
                'message' => 'Nie udało się przejąć locka dla wznawianego runu. Spróbuj ponownie po wygaśnięciu aktywnego locka.',
                'errors' => ['already_locked'],
                'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
            ];
        }

        $payload = [
            'update_state' => 'running',
            'update_last_heartbeat_at' => $this->nowSql(),
            'update_lock_expires_at' => $this->futureSql(self::LOCK_TTL_SECONDS),
            'update_lock_owner' => $runId,
            'update_maintenance_mode' => 1,
            'update_last_error' => null,
        ];
        if (empty($runtime['backup_confirmed']) && $backupConfirmed) {
            $payload['update_backup_confirmed_at'] = $this->nowSql();
            $payload['update_backup_confirmed_by'] = $this->actorLabel($user);
        }
        $this->writeRuntime($pdo, $payload);

        $state = $this->readRunState($runId);
        if ($state === []) {
            $workspace = $this->packageInstaller->runWorkspace($runId);
            $state = [
                'run_id' => $runId,
                'mode' => 'local_finalize',
                'stage' => self::STATE_MIGRATIONS,
                'target_version' => trim((string)($runtime['target_version'] ?? '')),
                'download_url' => '',
                'download_path' => $workspace['download_path'],
                'extract_dir' => $workspace['extract_dir'],
                'source_root' => '',
                'db_backup_path' => $workspace['db_backup_path'],
                'files_backup_path' => $workspace['files_backup_path'],
                'applied_files' => 0,
                'started_at' => gmdate('c'),
            ];
        } else {
            if (trim((string)($state['mode'] ?? '')) === '') {
                $state['mode'] = 'local_finalize';
            }
            if (trim((string)($state['stage'] ?? '')) === '') {
                $state['stage'] = self::STATE_MIGRATIONS;
            }
            if (!isset($state['target_version']) || trim((string)$state['target_version']) === '') {
                $state['target_version'] = trim((string)($runtime['target_version'] ?? ''));
            }
        }
        $this->writeRunState($runId, $state);

        $actor = $this->actorSummary($user);
        $this->logEvent($pdo, $runId, 'resume', 'resumed', 'success', 'Wznowiono przerwany run aktualizacji.', $actor, [
            'previous_state' => (string)($runtime['display_state'] ?? ''),
            'pending_migrations' => (int)($dashboard['status']['migrations']['pending_count'] ?? 0),
        ], [
            'maintenance_mode' => true,
            'locked_by' => $runId,
        ]);

        return [
            'ok' => true,
            'message' => 'Aktualizacja została wznowiona.',
            'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
        ];
    }

    /**
     * @param array<string,mixed>|null $user
     * @return array<string,mixed>
     */
    public function tick(PDO $pdo, ?array $user = null): array
    {
        $dashboard = $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]);
        $runtime = (array)$dashboard['runtime'];

        if (empty($runtime['supports_update_flow'])) {
            return [
                'ok' => false,
                'message' => 'Środowisko nie ma jeszcze pełnego schematu dla update flow. Najpierw uruchom migrację app_update_log.',
                'dashboard' => $dashboard,
            ];
        }

        $displayState = (string)($runtime['display_state'] ?? 'idle');
        if ($displayState === 'abandoned') {
            return [
                'ok' => false,
                'message' => 'Run aktualizacji został uznany za porzucony. Użyj akcji wznowienia.',
                'dashboard' => $dashboard,
            ];
        }
        if ($displayState !== 'running') {
            return [
                'ok' => true,
                'message' => 'Brak aktywnego batcha do wykonania.',
                'dashboard' => $dashboard,
            ];
        }

        $runId = trim((string)($runtime['run_id'] ?? ''));
        if ($runId === '') {
            return [
                'ok' => false,
                'message' => 'Aktywny run nie ma identyfikatora. Aktualizacja wymaga ręcznej interwencji.',
                'dashboard' => $dashboard,
            ];
        }

        $lock = new WorkerLock($pdo);
        $lockStatus = $lock->getStatus(self::LOCK_NAME);
        if (empty($lockStatus['locked']) || (string)($lockStatus['locked_by'] ?? '') !== $runId) {
            return [
                'ok' => false,
                'message' => 'Lock aktualizacji nie jest już aktywny. Run został oznaczony jako porzucony.',
                'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
            ];
        }

        $lock->heartbeat(self::LOCK_NAME, $runId, self::LOCK_TTL_SECONDS);
        $this->writeRuntime($pdo, [
            'update_last_heartbeat_at' => $this->nowSql(),
            'update_lock_expires_at' => $this->futureSql(self::LOCK_TTL_SECONDS),
            'update_lock_owner' => $runId,
        ]);

        $actor = $this->actorSummary($user);
        $state = $this->readRunState($runId);
        if ($state === []) {
            $workspace = $this->packageInstaller->runWorkspace($runId);
            $state = [
                'run_id' => $runId,
                'mode' => 'local_finalize',
                'stage' => self::STATE_MIGRATIONS,
                'target_version' => trim((string)($runtime['target_version'] ?? '')),
                'download_url' => '',
                'download_path' => $workspace['download_path'],
                'extract_dir' => $workspace['extract_dir'],
                'source_root' => '',
                'db_backup_path' => $workspace['db_backup_path'],
                'files_backup_path' => $workspace['files_backup_path'],
                'applied_files' => 0,
                'started_at' => gmdate('c'),
            ];
            $this->writeRunState($runId, $state);
        }

        $stageResult = $this->runPreMigrationStage($pdo, $user, $runId, $state, $actor);
        if (is_array($stageResult)) {
            return $stageResult;
        }

        $console = $this->migrationConsole($pdo);
        $batch = $console->runBatchMigrations(self::MIGRATION_BATCH_FILES, self::MIGRATION_BATCH_SECONDS);
        $results = is_array($batch['results'] ?? null) ? $batch['results'] : [];
        $executed = (int)($batch['executed'] ?? 0);
        $failedResult = null;
        $successfulFiles = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }
            if (!empty($result['success'])) {
                $successfulFiles[] = (string)($result['filename'] ?? '');
                continue;
            }
            $failedResult = $result;
            break;
        }

        $refreshedDashboard = $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]);
        $pendingAfter = (int)($refreshedDashboard['status']['migrations']['pending_count'] ?? 0);
        $storedTotal = (int)($runtime['total_migrations'] ?? 0);
        $totalMigrations = max($storedTotal, $pendingAfter + count($successfulFiles));
        $completedMigrations = max(0, $totalMigrations - $pendingAfter);

        if ($executed > 0 || $successfulFiles !== []) {
            $this->writeRuntime($pdo, [
                'update_total_migrations' => $totalMigrations,
                'update_completed_migrations' => $completedMigrations,
                'update_last_heartbeat_at' => $this->nowSql(),
                'update_lock_expires_at' => $this->futureSql(self::LOCK_TTL_SECONDS),
            ]);
            $this->logEvent($pdo, $runId, 'migrations', 'migration_batch', 'success', 'Wykonano kolejny batch migracji.', $actor, [
                'batch_file_limit' => self::MIGRATION_BATCH_FILES,
                'batch_time_limit_seconds' => self::MIGRATION_BATCH_SECONDS,
                'pending_before' => (int)($runtime['pending_migrations'] ?? 0),
            ], [
                'executed' => $executed,
                'successful_files' => array_values(array_filter($successfulFiles, static fn (string $file): bool => $file !== '')),
                'pending_after' => $pendingAfter,
            ]);
        }

        if (is_array($failedResult)) {
            $message = $this->shortMessage((string)($failedResult['message'] ?? 'Batch migracji zakończył się błędem.'));
            $this->writeRuntime($pdo, [
                'update_state' => 'failed',
                'update_total_migrations' => $totalMigrations,
                'update_completed_migrations' => $completedMigrations,
                'update_last_error' => $message,
                'update_last_heartbeat_at' => $this->nowSql(),
                'update_lock_expires_at' => null,
                'update_lock_owner' => null,
                'update_maintenance_mode' => 1,
            ]);
            $lock->release(self::LOCK_NAME, $runId);
            $this->logEvent($pdo, $runId, 'migrations', 'failed', 'failed', 'Batch migracji przerwał update flow.', $actor, [
                'failed_file' => (string)($failedResult['filename'] ?? ''),
            ], [
                'error' => $message,
                'pending_after' => $pendingAfter,
            ]);

            return [
                'ok' => false,
                'message' => $message,
                'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
            ];
        }

        if ($pendingAfter > 0) {
            $this->writeRuntime($pdo, [
                'update_state' => 'running',
                'update_total_migrations' => $totalMigrations,
                'update_completed_migrations' => $completedMigrations,
                'update_last_heartbeat_at' => $this->nowSql(),
                'update_lock_expires_at' => $this->futureSql(self::LOCK_TTL_SECONDS),
                'update_lock_owner' => $runId,
                'update_last_error' => null,
            ]);

            return [
                'ok' => true,
                'message' => 'Batch zakończony. Pozostały jeszcze kolejne migracje.',
                'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
            ];
        }

        $state = $this->readRunState($runId);
        $release = (array)$dashboard['status']['release'];
        $targetVersion = trim((string)($state['target_version'] ?? ''));
        $this->finalizeSuccess($pdo, $runId, $release, $totalMigrations, $actor, $targetVersion);
        $this->deleteRunState($runId);
        if (trim((string)($state['mode'] ?? '')) === 'remote_package') {
            $this->packageInstaller->cleanupWorkspace($runId);
        }
        $lock->release(self::LOCK_NAME, $runId);

        return [
            'ok' => true,
            'message' => 'Aktualizacja została zakończona powodzeniem.',
            'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
        ];
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,string|int|null> $actor
     * @return array<string,mixed>|null
     */
    private function runPreMigrationStage(PDO $pdo, ?array $user, string $runId, array $state, array $actor): ?array
    {
        $stage = trim((string)($state['stage'] ?? self::STATE_MIGRATIONS));
        if ($stage === '' || $stage === self::STATE_MIGRATIONS) {
            return null;
        }

        switch ($stage) {
            case self::STATE_DOWNLOAD:
                $downloadUrl = trim((string)($state['download_url'] ?? ''));
                $downloadPath = trim((string)($state['download_path'] ?? ''));
                if ($downloadUrl === '' || $downloadPath === '') {
                    return $this->failRun(
                        $pdo,
                        $user,
                        $runId,
                        'download',
                        'download_failed',
                        'Brakuje danych o paczce aktualizacji (download_url albo download_path).',
                        $actor
                    );
                }

                $download = $this->packageInstaller->downloadZip($downloadUrl, $downloadPath);
                if (empty($download['ok'])) {
                    return $this->failRun(
                        $pdo,
                        $user,
                        $runId,
                        'download',
                        'download_failed',
                        (string)($download['error'] ?? 'Nie udało się pobrać paczki aktualizacji.'),
                        $actor
                    );
                }

                $state['stage'] = self::STATE_BACKUP_DB;
                $this->writeRunState($runId, $state);
                $this->logEvent($pdo, $runId, 'download', 'downloaded', 'success', 'Pobrano paczkę aktualizacji.', $actor, [
                    'download_url' => $downloadUrl,
                ], [
                    'bytes' => (int)($download['bytes'] ?? 0),
                    'path' => (string)($download['path'] ?? $downloadPath),
                ]);

                return [
                    'ok' => true,
                    'message' => 'Pobrano paczkę aktualizacji. Kolejny krok: backup bazy danych.',
                    'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
                ];

            case self::STATE_BACKUP_DB:
                $dbBackupPath = trim((string)($state['db_backup_path'] ?? ''));
                if ($dbBackupPath === '') {
                    return $this->failRun(
                        $pdo,
                        $user,
                        $runId,
                        'backup',
                        'backup_db_failed',
                        'Nie ustawiono ścieżki backupu bazy danych.',
                        $actor
                    );
                }

                $dbBackup = $this->packageInstaller->backupDatabase($pdo, $dbBackupPath);
                if (empty($dbBackup['ok'])) {
                    return $this->failRun(
                        $pdo,
                        $user,
                        $runId,
                        'backup',
                        'backup_db_failed',
                        (string)($dbBackup['error'] ?? 'Błąd backupu bazy danych.'),
                        $actor
                    );
                }

                $state['stage'] = self::STATE_BACKUP_FILES;
                $this->writeRunState($runId, $state);
                $this->logEvent($pdo, $runId, 'backup', 'backup_db_done', 'success', 'Wykonano backup bazy danych.', $actor, null, [
                    'path' => (string)($dbBackup['path'] ?? $dbBackupPath),
                    'tables' => (int)($dbBackup['tables'] ?? 0),
                    'rows' => (int)($dbBackup['rows'] ?? 0),
                    'bytes' => (int)($dbBackup['bytes'] ?? 0),
                ]);

                return [
                    'ok' => true,
                    'message' => 'Backup bazy danych zakończony. Kolejny krok: backup plików.',
                    'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
                ];

            case self::STATE_BACKUP_FILES:
                $filesBackupPath = trim((string)($state['files_backup_path'] ?? ''));
                if ($filesBackupPath === '') {
                    return $this->failRun(
                        $pdo,
                        $user,
                        $runId,
                        'backup',
                        'backup_files_failed',
                        'Nie ustawiono ścieżki backupu plików.',
                        $actor
                    );
                }

                $filesBackup = $this->packageInstaller->backupFiles($filesBackupPath);
                if (empty($filesBackup['ok'])) {
                    return $this->failRun(
                        $pdo,
                        $user,
                        $runId,
                        'backup',
                        'backup_files_failed',
                        (string)($filesBackup['error'] ?? 'Błąd backupu plików.'),
                        $actor
                    );
                }

                $state['stage'] = self::STATE_EXTRACT;
                $this->writeRunState($runId, $state);
                $this->logEvent($pdo, $runId, 'backup', 'backup_files_done', 'success', 'Wykonano backup plików aplikacji.', $actor, null, [
                    'path' => (string)($filesBackup['path'] ?? $filesBackupPath),
                    'files' => (int)($filesBackup['files'] ?? 0),
                    'bytes' => (int)($filesBackup['bytes'] ?? 0),
                ]);

                return [
                    'ok' => true,
                    'message' => 'Backup plików zakończony. Kolejny krok: rozpakowanie paczki.',
                    'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
                ];

            case self::STATE_EXTRACT:
                $downloadPath = trim((string)($state['download_path'] ?? ''));
                $extractDir = trim((string)($state['extract_dir'] ?? ''));
                if ($downloadPath === '' || $extractDir === '') {
                    return $this->failRun(
                        $pdo,
                        $user,
                        $runId,
                        'extract',
                        'extract_failed',
                        'Brakuje ścieżek potrzebnych do rozpakowania paczki.',
                        $actor
                    );
                }

                $extract = $this->packageInstaller->extractZip($downloadPath, $extractDir);
                if (empty($extract['ok'])) {
                    return $this->failRun(
                        $pdo,
                        $user,
                        $runId,
                        'extract',
                        'extract_failed',
                        (string)($extract['error'] ?? 'Błąd rozpakowania paczki aktualizacji.'),
                        $actor
                    );
                }

                $state['source_root'] = (string)($extract['source_root'] ?? '');
                $state['stage'] = self::STATE_APPLY;
                $this->writeRunState($runId, $state);
                $this->logEvent($pdo, $runId, 'extract', 'extract_done', 'success', 'Paczka aktualizacji została rozpakowana.', $actor, null, [
                    'source_root' => (string)($extract['source_root'] ?? ''),
                    'entry_count' => (int)($extract['entry_count'] ?? 0),
                ]);

                return [
                    'ok' => true,
                    'message' => 'Paczka została rozpakowana. Kolejny krok: podmiana plików aplikacji.',
                    'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
                ];

            case self::STATE_APPLY:
                $sourceRoot = trim((string)($state['source_root'] ?? ''));
                if ($sourceRoot === '') {
                    return $this->failRun(
                        $pdo,
                        $user,
                        $runId,
                        'apply',
                        'apply_failed',
                        'Brakuje katalogu źródłowego po rozpakowaniu paczki.',
                        $actor
                    );
                }

                $apply = $this->packageInstaller->applyPackage($sourceRoot, AppUpdatePackageInstaller::DEFAULT_EXCLUDE_PREFIXES);
                if (empty($apply['ok'])) {
                    return $this->failRun(
                        $pdo,
                        $user,
                        $runId,
                        'apply',
                        'apply_failed',
                        (string)($apply['error'] ?? 'Błąd podmiany plików aplikacji.'),
                        $actor
                    );
                }

                $state['applied_files'] = (int)($apply['copied'] ?? 0);
                $state['stage'] = self::STATE_MIGRATIONS;
                $this->writeRunState($runId, $state);
                $this->logEvent($pdo, $runId, 'apply', 'apply_done', 'success', 'Nadpisano pliki aplikacji (z wykluczeniami).', $actor, [
                    'excluded' => AppUpdatePackageInstaller::DEFAULT_EXCLUDE_PREFIXES,
                ], [
                    'copied' => (int)($apply['copied'] ?? 0),
                    'skipped' => (int)($apply['skipped'] ?? 0),
                ]);

                return [
                    'ok' => true,
                    'message' => 'Pliki aplikacji zaktualizowane. Rozpoczynam etap migracji bazy.',
                    'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
                ];
        }

        $state['stage'] = self::STATE_MIGRATIONS;
        $this->writeRunState($runId, $state);
        return null;
    }

    /**
     * @param array<string,string|int|null> $actor
     * @return array<string,mixed>
     */
    private function failRun(PDO $pdo, ?array $user, string $runId, string $phase, string $eventKey, string $message, array $actor): array
    {
        $message = $this->shortMessage($message);
        $this->writeRuntime($pdo, [
            'update_state' => 'failed',
            'update_last_error' => $message,
            'update_last_heartbeat_at' => $this->nowSql(),
            'update_lock_expires_at' => null,
            'update_lock_owner' => null,
            'update_maintenance_mode' => 1,
        ]);
        $lock = new WorkerLock($pdo);
        $lock->release(self::LOCK_NAME, $runId);
        $this->logEvent($pdo, $runId, $phase, $eventKey, 'failed', $message, $actor, null, [
            'phase' => $phase,
        ]);

        return [
            'ok' => false,
            'message' => $message,
            'dashboard' => $this->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
        ];
    }

    /**
     * @return array<string,int>
     */
    public function lockPolicy(): array
    {
        return [
            'ttl_seconds' => self::LOCK_TTL_SECONDS,
            'heartbeat_interval_seconds' => self::HEARTBEAT_INTERVAL_SECONDS,
            'abandoned_after_seconds' => self::ABANDONED_AFTER_SECONDS,
            'batch_file_limit' => self::MIGRATION_BATCH_FILES,
            'batch_time_limit_seconds' => self::MIGRATION_BATCH_SECONDS,
        ];
    }

    /**
     * @param array<string,mixed> $dashboard
     * @return array{ok:bool,message:string,errors:list<string>}
     */
    private function validateStart(array $dashboard, bool $backupConfirmed): array
    {
        $errors = [];
        $runtime = (array)($dashboard['runtime'] ?? []);
        $status = (array)($dashboard['status'] ?? []);
        $flags = (array)($status['status_flags'] ?? []);
        $release = (array)($status['release'] ?? []);
        $migrations = (array)($status['migrations'] ?? []);

        if (empty($runtime['supports_update_flow'])) {
            $errors[] = 'Brakuje migracji `app_update_log` albo runtime columns w `app_meta`.';
        }
        if (empty($migrations['migrations_dir_ok'])) {
            $errors[] = 'Katalog migracji SQL nie jest dostępny do odczytu. Zweryfikuj ścieżkę i uprawnienia.';
        }
        if (!$backupConfirmed) {
            $errors[] = 'Musisz jawnie potwierdzić wykonanie backupu przed rozpoczęciem update flow.';
        }
        if (empty($release['ok'])) {
            $errors[] = 'release.json nie przechodzi walidacji, więc aplikacja nie zna lokalnej wersji docelowej.';
        }
        if ((string)($status['app_meta']['install_state'] ?? '') !== 'installed') {
            $errors[] = 'Aplikacja nie jest oznaczona jako zainstalowana, więc update flow nie może zostać uruchomiony.';
        }
        if (($runtime['display_state'] ?? '') === 'running') {
            $errors[] = 'Aktualizacja jest już uruchomiona.';
        }
        if (!empty($runtime['can_resume']) && empty($flags['update_available'])) {
            $errors[] = 'Najpierw wznowij istniejący run zamiast uruchamiać nowy.';
        }

        $needsFinalize = !empty($flags['update_required'])
            || !empty($flags['update_available'])
            || !empty($flags['local_code_ahead_of_recorded_install'])
            || !empty($flags['local_code_ahead_of_db_version'])
            || !empty($flags['installed_version_missing'])
            || !empty($flags['db_version_missing'])
            || (int)($migrations['pending_count'] ?? 0) > 0;

        if (!$needsFinalize) {
            $errors[] = 'Nie wykryto pracy do wykonania. Lokalna wersja i baza wyglądają na zsynchronizowane.';
        }

        return [
            'ok' => $errors === [],
            'message' => $errors !== [] ? $errors[0] : '',
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    private function readRuntime(PDO $pdo, array $status): array
    {
        $columns = $this->getTableColumns($pdo, 'app_meta');
        $supportsRuntime = count(array_intersect(self::RUNTIME_COLUMNS, $columns)) === count(self::RUNTIME_COLUMNS);
        $supportsLog = $this->tableExists($pdo, 'app_update_log');

        $row = [];
        if ($supportsRuntime) {
            $select = implode(', ', self::RUNTIME_COLUMNS);
            $stmt = $pdo->query('SELECT ' . $select . ' FROM app_meta WHERE id = 1 LIMIT 1');
            $row = $stmt ? (array)($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
            if ($stmt) {
                $stmt->closeCursor();
            }
        }

        $runId = trim((string)($row['update_run_id'] ?? ''));
        $lock = new WorkerLock($pdo);
        $lockStatus = $lock->getStatus(self::LOCK_NAME);
        $storedState = trim((string)($row['update_state'] ?? ''));
        $maintenanceMode = !empty($row['update_maintenance_mode']);
        $abandoned = $supportsRuntime
            && $storedState === 'running'
            && $maintenanceMode
            && (!$lockStatus['locked'] || (string)($lockStatus['locked_by'] ?? '') !== $runId)
            && $runId !== '';

        $displayState = $storedState !== '' ? $storedState : 'idle';
        if ($abandoned) {
            $displayState = 'abandoned';
        } elseif ($storedState === 'completed' && !$maintenanceMode) {
            $displayState = 'completed';
        } elseif ($storedState === 'failed') {
            $displayState = 'failed';
        } elseif ($storedState === 'running') {
            $displayState = 'running';
        }

        $pendingCount = (int)($status['migrations']['pending_count'] ?? 0);
        $totalMigrations = max((int)($row['update_total_migrations'] ?? 0), $pendingCount + (int)($row['update_completed_migrations'] ?? 0));
        $completedMigrations = max(0, min($totalMigrations > 0 ? $totalMigrations : (int)($row['update_completed_migrations'] ?? 0), (int)($row['update_completed_migrations'] ?? 0)));
        if ($displayState === 'completed' && $totalMigrations > 0) {
            $completedMigrations = $totalMigrations;
        } elseif ($totalMigrations > 0 && $pendingCount >= 0) {
            $completedMigrations = max($completedMigrations, $totalMigrations - $pendingCount);
        }

        $progressPercent = 0;
        if ($totalMigrations <= 0) {
            $progressPercent = $displayState === 'running' || $displayState === 'completed' ? 100 : ($pendingCount === 0 ? 100 : 0);
        } else {
            $progressPercent = (int)floor(($completedMigrations / max(1, $totalMigrations)) * 100);
        }

        $canResume = in_array($displayState, ['failed', 'abandoned'], true);
        $flags = (array)($status['status_flags'] ?? []);
        $canStart = $supportsRuntime
            && $supportsLog
            && $displayState !== 'running'
                && (!empty($flags['update_required'])
                    || !empty($flags['update_available'])
                    || !empty($flags['local_code_ahead_of_recorded_install'])
                    || !empty($flags['local_code_ahead_of_db_version'])
                    || !empty($flags['installed_version_missing'])
                    || !empty($flags['db_version_missing'])
                    || $pendingCount > 0);

        return [
            'supports_update_flow' => $supportsRuntime && $supportsLog,
            'supports_runtime_columns' => $supportsRuntime,
            'supports_log_table' => $supportsLog,
            'stored_state' => $storedState,
            'display_state' => $displayState,
            'display_label' => $this->stateLabel($displayState),
            'display_message' => $this->stateMessage($displayState, $row, $lockStatus),
            'run_id' => $runId,
            'target_version' => trim((string)($row['update_target_version'] ?? '')),
            'started_at' => trim((string)($row['update_started_at'] ?? '')),
            'completed_at' => trim((string)($row['update_completed_at'] ?? '')),
            'last_heartbeat_at' => trim((string)($row['update_last_heartbeat_at'] ?? '')),
            'lock_expires_at' => trim((string)($row['update_lock_expires_at'] ?? '')),
            'lock_owner' => trim((string)($row['update_lock_owner'] ?? '')),
            'maintenance_mode' => $maintenanceMode,
            'backup_confirmed' => trim((string)($row['update_backup_confirmed_at'] ?? '')) !== '',
            'backup_confirmed_at' => trim((string)($row['update_backup_confirmed_at'] ?? '')),
            'backup_confirmed_by' => trim((string)($row['update_backup_confirmed_by'] ?? '')),
            'total_migrations' => $totalMigrations,
            'completed_migrations' => $completedMigrations,
            'pending_migrations' => $pendingCount,
            'progress_percent' => max(0, min(100, $progressPercent)),
            'last_error' => trim((string)($row['update_last_error'] ?? '')),
            'lock' => [
                'locked' => !empty($lockStatus['locked']),
                'locked_by' => (string)($lockStatus['locked_by'] ?? ''),
                'locked_at' => (string)($lockStatus['locked_at'] ?? ''),
                'heartbeat_at' => (string)($lockStatus['heartbeat_at'] ?? ''),
                'expires_at' => (string)($lockStatus['expires_at'] ?? ''),
                'is_abandoned' => $abandoned,
            ],
            'can_start' => $canStart,
            'can_resume' => $canResume,
        ];
    }

    /**
     * @param array<string,mixed> $release
     * @param array<string,string|int|null> $actor
     */
    private function finalizeSuccess(
        PDO $pdo,
        string $runId,
        array $release,
        int $totalMigrations,
        array $actor,
        string $targetVersion = ''
    ): void
    {
        $installedVersion = trim($targetVersion);
        if ($installedVersion === '') {
            $installedVersion = defined('APP_VERSION') ? trim((string)APP_VERSION) : trim((string)($release['version'] ?? ''));
        }
        if ($installedVersion === '') {
            $installedVersion = trim((string)($release['version'] ?? ''));
        }
        $baselineId = trim((string)($release['baseline_id'] ?? ''));
        $releaseChannel = trim((string)($release['channel'] ?? ''));
        $columns = $this->getTableColumns($pdo, 'app_meta');
        $supportsDbVersion = in_array('db_version', $columns, true);

        $setParts = [
            'install_state = COALESCE(install_state, :install_state)',
            'installed_version = COALESCE(:installed_version, installed_version)',
            'installed_at = NOW()',
            'baseline_id = COALESCE(:baseline_id, baseline_id)',
            'release_channel = COALESCE(:release_channel, release_channel)',
            'update_state = :update_state',
            'update_completed_at = NOW()',
            'update_last_heartbeat_at = NOW()',
            'update_lock_expires_at = NULL',
            'update_lock_owner = NULL',
            'update_maintenance_mode = 0',
            'update_total_migrations = :update_total_migrations',
            'update_completed_migrations = :update_completed_migrations',
            'update_last_error = NULL',
            'updated_at = NOW()',
        ];
        if ($supportsDbVersion) {
            $setParts[] = 'db_version = COALESCE(:db_version, db_version)';
        }

        $params = [
            ':install_state' => 'installed',
            ':installed_version' => $installedVersion !== '' ? $installedVersion : null,
            ':baseline_id' => $baselineId !== '' ? $baselineId : null,
            ':release_channel' => $releaseChannel !== '' ? $releaseChannel : null,
            ':update_state' => 'completed',
            ':update_total_migrations' => max(0, $totalMigrations),
            ':update_completed_migrations' => max(0, $totalMigrations),
        ];
        if ($supportsDbVersion) {
            $params[':db_version'] = $installedVersion !== '' ? $installedVersion : null;
        }

        $stmt = $pdo->prepare(
            'UPDATE app_meta SET ' . implode(', ', $setParts) . ' WHERE id = 1'
        );
        $stmt->execute($params);

        $this->logEvent($pdo, $runId, 'finalize', 'completed', 'success', 'Finalizacja wdrożenia zakończyła się powodzeniem.', $actor, [
            'target_version' => $installedVersion,
            'total_migrations' => max(0, $totalMigrations),
        ], [
            'installed_version' => $installedVersion,
            'db_version' => $installedVersion,
            'maintenance_mode' => false,
        ]);
    }

    /**
     * @param array<string,mixed> $release
     */
    private function ensureAppMetaRecord(PDO $pdo, array $release): void
    {
        $columns = $this->getTableColumns($pdo, 'app_meta');
        $supportsDbVersion = in_array('db_version', $columns, true);
        $localVersion = defined('APP_VERSION') ? trim((string)APP_VERSION) : trim((string)($release['version'] ?? ''));

        if ($supportsDbVersion) {
            $stmt = $pdo->prepare(
                "INSERT INTO app_meta (id, install_state, installed_version, db_version, installed_at, baseline_id, release_channel, created_at, updated_at)
                 VALUES (1, 'installed', :installed_version, :db_version, NULL, :baseline_id, :release_channel, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    updated_at = NOW(),
                    installed_version = COALESCE(VALUES(installed_version), installed_version),
                    db_version = COALESCE(db_version, VALUES(db_version)),
                    baseline_id = COALESCE(VALUES(baseline_id), baseline_id),
                    release_channel = COALESCE(VALUES(release_channel), release_channel)"
            );
            $stmt->execute([
                ':installed_version' => $localVersion !== '' ? $localVersion : null,
                ':db_version' => $localVersion !== '' ? $localVersion : null,
                ':baseline_id' => trim((string)($release['baseline_id'] ?? '')) ?: null,
                ':release_channel' => trim((string)($release['channel'] ?? '')) ?: null,
            ]);
            return;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO app_meta (id, install_state, installed_version, installed_at, baseline_id, release_channel, created_at, updated_at)
             VALUES (1, 'installed', :installed_version, NULL, :baseline_id, :release_channel, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                updated_at = NOW(),
                installed_version = COALESCE(VALUES(installed_version), installed_version),
                baseline_id = COALESCE(VALUES(baseline_id), baseline_id),
                release_channel = COALESCE(VALUES(release_channel), release_channel)"
        );
        $stmt->execute([
            ':installed_version' => $localVersion !== '' ? $localVersion : null,
            ':baseline_id' => trim((string)($release['baseline_id'] ?? '')) ?: null,
            ':release_channel' => trim((string)($release['channel'] ?? '')) ?: null,
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function writeRuntime(PDO $pdo, array $payload): void
    {
        $allowed = array_values(array_intersect(array_keys($payload), self::RUNTIME_COLUMNS));
        if ($allowed === []) {
            return;
        }

        $parts = [];
        $params = [':id' => 1];
        foreach ($allowed as $column) {
            $parts[] = $column . ' = :' . $column;
            $params[':' . $column] = $payload[$column];
        }
        $parts[] = 'updated_at = NOW()';

        $stmt = $pdo->prepare('UPDATE app_meta SET ' . implode(', ', $parts) . ' WHERE id = :id');
        $stmt->execute($params);
    }

    /**
     * @param array<string,string|int|null> $actor
     * @param array<string,mixed>|null $validation
     * @param array<string,mixed>|null $result
     */
    private function logEvent(
        PDO $pdo,
        string $runId,
        string $phase,
        string $eventKey,
        string $status,
        string $message,
        array $actor,
        ?array $validation = null,
        ?array $result = null
    ): void {
        if (!$this->tableExists($pdo, 'app_update_log')) {
            return;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO app_update_log (
                update_run_id,
                phase,
                event_key,
                status,
                message,
                actor_user_id,
                actor_login,
                validation_json,
                result_json
            ) VALUES (
                :update_run_id,
                :phase,
                :event_key,
                :status,
                :message,
                :actor_user_id,
                :actor_login,
                :validation_json,
                :result_json
            )'
        );
        $stmt->execute([
            ':update_run_id' => $runId,
            ':phase' => $phase,
            ':event_key' => $eventKey,
            ':status' => $status,
            ':message' => $this->shortMessage($message),
            ':actor_user_id' => $actor['user_id'] ?: null,
            ':actor_login' => $actor['login'] !== '' ? $actor['login'] : null,
            ':validation_json' => $this->encodeSmallJson($validation),
            ':result_json' => $this->encodeSmallJson($result),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function readLogEntries(PDO $pdo, string $runId, int $limit): array
    {
        if (!$this->tableExists($pdo, 'app_update_log')) {
            return [];
        }

        if ($runId !== '') {
            $stmt = $pdo->prepare(
                'SELECT id, update_run_id, phase, event_key, status, message, actor_user_id, actor_login, validation_json, result_json, created_at
                 FROM app_update_log
                 WHERE update_run_id = :update_run_id
                 ORDER BY id DESC
                 LIMIT ' . max(1, $limit)
            );
            $stmt->execute([':update_run_id' => $runId]);
        } else {
            $stmt = $pdo->query(
                'SELECT id, update_run_id, phase, event_key, status, message, actor_user_id, actor_login, validation_json, result_json, created_at
                 FROM app_update_log
                 ORDER BY id DESC
                 LIMIT ' . max(1, $limit)
            );
        }

        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if ($stmt) {
            $stmt->closeCursor();
        }

        $entries = [];
        foreach (array_reverse($rows ?: []) as $row) {
            $entries[] = [
                'id' => (int)($row['id'] ?? 0),
                'run_id' => (string)($row['update_run_id'] ?? ''),
                'phase' => (string)($row['phase'] ?? ''),
                'event_key' => (string)($row['event_key'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'message' => (string)($row['message'] ?? ''),
                'actor_user_id' => (int)($row['actor_user_id'] ?? 0),
                'actor_login' => (string)($row['actor_login'] ?? ''),
                'validation' => $this->decodeJsonText((string)($row['validation_json'] ?? '')),
                'result' => $this->decodeJsonText((string)($row['result_json'] ?? '')),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }

        return $entries;
    }

    private function migrationConsole(PDO $pdo): MigrationConsole
    {
        return new MigrationConsole(
            $pdo,
            $this->migrationsDir,
            $this->migrationLogPath,
            'updates',
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        );
    }

    private function stateLabel(string $state): string
    {
        return match ($state) {
            'running' => 'W toku',
            'failed' => 'Nieudana',
            'abandoned' => 'Porzucona',
            'completed' => 'Zakończona',
            default => 'Bezczynna',
        };
    }

    /**
     * @return list<string>
     */
    private function allowedPathHints(): array
    {
        return [
            '/admin/updates.php',
            '/admin/index.php',
            '/index.php',
            '/login.php',
            '/assets/*',
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $lockStatus
     */
    private function stateMessage(string $state, array $row, array $lockStatus): string
    {
        return match ($state) {
            'running' => 'Ekran statusu orkiestruje kolejne batch-e migracji automatycznie.',
            'failed' => trim((string)($row['update_last_error'] ?? '')) !== ''
                ? trim((string)$row['update_last_error'])
                : 'Poprzedni batch zakończył się błędem. Maintenance mode pozostał aktywny do czasu wznowienia.',
            'abandoned' => 'Lock wygasł albo został utracony. Run uznajemy za porzucony, dopóki admin nie użyje wznowienia.',
            'completed' => 'Finalizacja wdrożonej wersji zakończyła się powodzeniem, a maintenance mode został wyłączony.',
            default => !empty($lockStatus['locked'])
                ? 'Lock istnieje, ale runtime nie zgłasza aktywnego runu.'
                : 'Nie ma aktywnego update flow.',
        };
    }

    /**
     * @param array<string,mixed>|null $user
     * @return array{user_id:int,login:string}
     */
    private function actorSummary(?array $user): array
    {
        return [
            'user_id' => (int)($user['user_id'] ?? $user['id'] ?? 0),
            'login' => trim((string)($user['login'] ?? '')),
        ];
    }

    /**
     * @param array<string,mixed>|null $user
     */
    private function actorLabel(?array $user): string
    {
        $summary = $this->actorSummary($user);
        if ($summary['login'] !== '') {
            return $summary['login'];
        }
        if ($summary['user_id'] > 0) {
            return 'uid:' . $summary['user_id'];
        }
        return 'system';
    }

    /**
     * @param array<string,mixed>|null $payload
     */
    private function encodeSmallJson(?array $payload): ?string
    {
        if ($payload === null || $payload === []) {
            return null;
        }
        $normalized = [];
        foreach ($payload as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            $normalized[(string)$key] = $value;
        }
        if ($normalized === []) {
            return null;
        }
        $encoded = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $encoded !== false ? $encoded : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeJsonText(string $payload): ?array
    {
        $payload = trim($payload);
        if ($payload === '') {
            return null;
        }
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function runStatePath(string $runId): string
    {
        $safeRunId = preg_replace('/[^A-Za-z0-9._-]/', '-', $runId) ?: 'update-run';
        return $this->rootDir . '/storage/cache/app-update-runs/' . $safeRunId . '.json';
    }

    /**
     * @return array<string,mixed>
     */
    private function readRunState(string $runId): array
    {
        $path = $this->runStatePath($runId);
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }
        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $state
     */
    private function writeRunState(string $runId, array $state): void
    {
        $path = $this->runStatePath($runId);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Nie udało się utworzyć katalogu stanu aktualizacji.');
        }

        $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($encoded === false) {
            throw new RuntimeException('Nie udało się zapisać stanu aktualizacji.');
        }
        file_put_contents($path, $encoded . "\n", LOCK_EX);
    }

    private function deleteRunState(string $runId): void
    {
        $path = $this->runStatePath($runId);
        if (is_file($path)) {
            @unlink($path);
        }
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

    private function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->query('SHOW TABLES LIKE ' . $pdo->quote($table));
            $exists = (bool)($stmt && $stmt->fetchColumn());
            if ($stmt) {
                $stmt->closeCursor();
            }
            return $exists;
        } catch (Throwable $e) {
            return false;
        }
    }

    private function nowSql(): string
    {
        return date('Y-m-d H:i:s');
    }

    private function futureSql(int $seconds): string
    {
        return date('Y-m-d H:i:s', time() + $seconds);
    }

    private function futureIso(int $seconds): string
    {
        return gmdate('c', time() + $seconds);
    }

    private function shortMessage(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return '';
        }
        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, 255);
        }
        return substr($message, 0, 255);
    }

    private function newRunId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
