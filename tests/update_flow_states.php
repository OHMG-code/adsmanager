<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/AppUpdateOrchestrator.php';

const TEST_CHARSET = 'utf8mb4';
const TEST_APP_ROOT = __DIR__ . '/..';

/**
 * @return array<string,mixed>
 */
function testConfig(): array
{
    $config = include __DIR__ . '/../config/db.local.php';
    if (!is_array($config)) {
        throw new RuntimeException('db.local.php did not return an array.');
    }

    $envUser = getenv('UPDATE_TEST_DB_USER');
    $envPass = getenv('UPDATE_TEST_DB_PASS');
    $envHost = getenv('UPDATE_TEST_DB_HOST');
    $envPort = getenv('UPDATE_TEST_DB_PORT');

    return [
        'host' => $envHost !== false && $envHost !== '' ? (string)$envHost : (string)($config['host'] ?? 'mysql8'),
        'port' => $envPort !== false && $envPort !== '' ? (int)$envPort : (int)($config['port'] ?? 3306),
        'user' => $envUser !== false && $envUser !== '' ? (string)$envUser : (string)($config['user'] ?? ''),
        'pass' => $envPass !== false ? (string)$envPass : (string)($config['pass'] ?? ''),
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

function uniqueDatabaseName(string $prefix): string
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

function bootstrapStage4Schema(PDO $pdo): void
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
            update_state VARCHAR(32) NULL,
            update_run_id CHAR(36) NULL,
            update_target_version VARCHAR(64) NULL,
            update_started_at DATETIME NULL,
            update_completed_at DATETIME NULL,
            update_last_heartbeat_at DATETIME NULL,
            update_lock_expires_at DATETIME NULL,
            update_lock_owner VARCHAR(120) NULL,
            update_maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
            update_backup_confirmed_at DATETIME NULL,
            update_backup_confirmed_by VARCHAR(120) NULL,
            update_total_migrations INT UNSIGNED NOT NULL DEFAULT 0,
            update_completed_migrations INT UNSIGNED NOT NULL DEFAULT 0,
            update_last_error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE app_update_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            update_run_id CHAR(36) NOT NULL,
            phase VARCHAR(32) NOT NULL,
            event_key VARCHAR(64) NOT NULL,
            status VARCHAR(16) NOT NULL,
            message VARCHAR(255) NOT NULL,
            actor_user_id INT NULL,
            actor_login VARCHAR(100) NULL,
            validation_json TEXT NULL,
            result_json TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_app_update_log_run_id (update_run_id, id),
            KEY idx_app_update_log_phase (phase, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "INSERT INTO app_meta (id, install_state, installed_version, db_version, installed_at, created_at, updated_at)
         VALUES (1, 'installed', '2026.04.07.1', '2026.04.07.1', NOW(), NOW(), NOW())"
    );
}

function createMigrationsDir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '_' . strtolower(bin2hex(random_bytes(4)));
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create temporary migrations directory.');
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

/**
 * @param list<string> $assertions
 */
function assertTrue(bool $condition, string $message, array &$assertions): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $assertions[] = $message;
}

/**
 * @return array<string,mixed>
 */
function runUntilTerminal(AppUpdateOrchestrator $orchestrator, PDO $pdo, array $user, int $maxTicks = 10): array
{
    $history = [];
    for ($tick = 1; $tick <= $maxTicks; $tick++) {
        $result = $orchestrator->tick($pdo, $user);
        $runtime = (array)(($result['dashboard'] ?? [])['runtime'] ?? []);
        $history[] = [
            'tick' => $tick,
            'ok' => !empty($result['ok']),
            'message' => (string)($result['message'] ?? ''),
            'state' => (string)($runtime['display_state'] ?? ''),
            'pending' => (int)($runtime['pending_migrations'] ?? 0),
        ];
        if (in_array((string)($runtime['display_state'] ?? ''), ['completed', 'failed', 'abandoned'], true)) {
            return [
                'result' => $result,
                'history' => $history,
            ];
        }
    }

    return [
        'result' => $orchestrator->getDashboard($pdo, $user, ['allow_auto_refresh' => false]),
        'history' => $history,
    ];
}

/**
 * @return array<string,mixed>
 */
function runSuccessScenario(array $config, PDO $serverPdo): array
{
    $database = uniqueDatabaseName('crm_update_success');
    $migrationsDir = createMigrationsDir('crm_update_success');
    $logPath = sys_get_temp_dir() . '/crm_update_success.log';
    $appRoot = realpath(TEST_APP_ROOT) ?: dirname(__DIR__);
    $assertions = [];

    createDatabase($serverPdo, $database, (string)$config['charset']);
    try {
        $pdo = databasePdo($config, $database);
        bootstrapStage4Schema($pdo);

        file_put_contents(
            $migrationsDir . '/2026_04_07_90_test_success_a.sql',
            "CREATE TABLE test_success_a (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
        );
        file_put_contents(
            $migrationsDir . '/2026_04_07_91_test_success_b.sql',
            "CREATE TABLE test_success_b (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
        );

        $orchestrator = new AppUpdateOrchestrator($appRoot, $migrationsDir, $logPath);
        $user = ['id' => 1, 'user_id' => 1, 'login' => 'admin', 'rola' => 'Administrator'];

        $start = $orchestrator->start($pdo, $user, true);
        assertTrue(!empty($start['ok']), 'success:start_ok', $assertions);

        $terminal = runUntilTerminal($orchestrator, $pdo, $user, 4);
        $dashboard = $orchestrator->getDashboard($pdo, $user, ['allow_auto_refresh' => false]);
        $runtime = (array)$dashboard['runtime'];
        $appMeta = (array)$dashboard['status']['app_meta'];

        assertTrue(($runtime['display_state'] ?? '') === 'completed', 'success:state_completed', $assertions);
        assertTrue(empty($runtime['maintenance_mode']), 'success:maintenance_off', $assertions);
        assertTrue((int)($runtime['pending_migrations'] ?? -1) === 0, 'success:pending_zero', $assertions);
        $releaseVersion = (string)($dashboard['status']['release']['version'] ?? '');
        assertTrue((string)($appMeta['installed_version'] ?? '') === $releaseVersion, 'success:installed_version_updated', $assertions);

        return [
            'name' => 'success',
            'database' => $database,
            'assertions' => $assertions,
            'history' => $terminal['history'],
            'final_state' => $runtime['display_state'] ?? '',
        ];
    } finally {
        removeDir($migrationsDir);
        dropDatabase($serverPdo, $database);
    }
}

/**
 * @return array<string,mixed>
 */
function runFailedResumedScenario(array $config, PDO $serverPdo): array
{
    $database = uniqueDatabaseName('crm_update_resume');
    $migrationsDir = createMigrationsDir('crm_update_resume');
    $logPath = sys_get_temp_dir() . '/crm_update_resume.log';
    $appRoot = realpath(TEST_APP_ROOT) ?: dirname(__DIR__);
    $assertions = [];

    createDatabase($serverPdo, $database, (string)$config['charset']);
    try {
        $pdo = databasePdo($config, $database);
        bootstrapStage4Schema($pdo);

        file_put_contents(
            $migrationsDir . '/2026_04_07_92_test_resume_a.sql',
            "CREATE TABLE test_resume_a (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
        );
        file_put_contents(
            $migrationsDir . '/2026_04_07_93_test_resume_b.sql',
            "INSERT INTO missing_resume_target (id) VALUES (1);\n"
        );

        $orchestrator = new AppUpdateOrchestrator($appRoot, $migrationsDir, $logPath);
        $user = ['id' => 1, 'user_id' => 1, 'login' => 'admin', 'rola' => 'Administrator'];

        $start = $orchestrator->start($pdo, $user, true);
        assertTrue(!empty($start['ok']), 'resume:start_ok', $assertions);

        $failedTerminal = runUntilTerminal($orchestrator, $pdo, $user, 3);
        $failedDashboard = $orchestrator->getDashboard($pdo, $user, ['allow_auto_refresh' => false]);
        $failedRuntime = (array)$failedDashboard['runtime'];

        assertTrue(($failedRuntime['display_state'] ?? '') === 'failed', 'resume:state_failed', $assertions);
        assertTrue(!empty($failedRuntime['maintenance_mode']), 'resume:maintenance_still_on_after_failure', $assertions);

        file_put_contents(
            $migrationsDir . '/2026_04_07_93_test_resume_b.sql',
            "CREATE TABLE test_resume_b (id INT NOT NULL PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
        );

        $resume = $orchestrator->resume($pdo, $user, false);
        assertTrue(!empty($resume['ok']), 'resume:resume_ok', $assertions);

        $completedTerminal = runUntilTerminal($orchestrator, $pdo, $user, 4);
        $dashboard = $orchestrator->getDashboard($pdo, $user, ['allow_auto_refresh' => false]);
        $runtime = (array)$dashboard['runtime'];

        assertTrue(($runtime['display_state'] ?? '') === 'completed', 'resume:state_completed_after_resume', $assertions);
        assertTrue(empty($runtime['maintenance_mode']), 'resume:maintenance_off_after_resume', $assertions);

        $resumeCount = (int)$pdo->query("SELECT COUNT(*) FROM app_update_log WHERE event_key = 'resumed'")->fetchColumn();
        assertTrue($resumeCount >= 1, 'resume:log_contains_resumed', $assertions);

        return [
            'name' => 'failed_resumed',
            'database' => $database,
            'assertions' => $assertions,
            'failed_history' => $failedTerminal['history'],
            'resumed_history' => $completedTerminal['history'],
            'final_state' => $runtime['display_state'] ?? '',
        ];
    } finally {
        removeDir($migrationsDir);
        dropDatabase($serverPdo, $database);
    }
}

try {
    $config = testConfig();
    $serverPdo = serverPdo($config);

    $report = [
        'success' => runSuccessScenario($config, $serverPdo),
        'failed_resumed' => runFailedResumedScenario($config, $serverPdo),
    ];

    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
} catch (Throwable $e) {
    $message = (string)$e->getMessage();
    $normalized = strtolower($message);
    $isDbUnavailable = str_contains($message, 'SQLSTATE[HY000] [2002]')
        || str_contains($normalized, 'connection refused')
        || str_contains($normalized, 'nie można nawiązać połączenia')
        || str_contains($normalized, 'actively refused');
    if ($isDbUnavailable) {
        fwrite(STDOUT, "SKIP: MySQL unavailable for update_flow_states ({$message})" . PHP_EOL);
        exit(0);
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
