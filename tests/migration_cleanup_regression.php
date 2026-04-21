<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MigrationRunner.php';

const MIGRATION_TEST_CHARSET = 'utf8mb4';

/**
 * @return array<string,mixed>
 */
function migrationTestConfig(): array
{
    $config = include __DIR__ . '/../config/db.local.php';
    if (!is_array($config)) {
        throw new RuntimeException('db.local.php did not return an array.');
    }

    $host = getenv('MIGRATION_TEST_DB_HOST');
    if ($host === false || $host === '') {
        $host = getenv('UPDATE_TEST_DB_HOST');
    }
    $port = getenv('MIGRATION_TEST_DB_PORT');
    if ($port === false || $port === '') {
        $port = getenv('UPDATE_TEST_DB_PORT');
    }
    $user = getenv('MIGRATION_TEST_DB_USER');
    if ($user === false || $user === '') {
        $user = getenv('UPDATE_TEST_DB_USER');
    }
    $pass = getenv('MIGRATION_TEST_DB_PASS');
    if ($pass === false) {
        $pass = getenv('UPDATE_TEST_DB_PASS');
    }

    return [
        'host' => $host !== false && $host !== '' ? (string)$host : (string)($config['host'] ?? 'db'),
        'port' => $port !== false && $port !== '' ? (int)$port : (int)($config['port'] ?? 3306),
        'user' => $user !== false && $user !== '' ? (string)$user : (string)($config['user'] ?? ''),
        'pass' => $pass !== false ? (string)$pass : (string)($config['pass'] ?? ''),
        'charset' => (string)($config['charset'] ?? MIGRATION_TEST_CHARSET),
    ];
}

/**
 * @param array<string,mixed> $config
 */
function migrationServerPdo(array $config): PDO
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
function migrationDatabasePdo(array $config, string $database): PDO
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

function migrationUniqueDatabaseName(string $prefix): string
{
    return sprintf('%s_%s', $prefix, strtolower(bin2hex(random_bytes(4))));
}

function migrationCreateDatabase(PDO $serverPdo, string $database, string $charset): void
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

function migrationDropDatabase(PDO $serverPdo, string $database): void
{
    $serverPdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', str_replace('`', '``', $database)));
}

function migrationCreateTempDir(string $prefix): string
{
    $dir = sys_get_temp_dir() . '/' . $prefix . '_' . strtolower(bin2hex(random_bytes(4)));
    if (!mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Failed to create temporary directory.');
    }

    return $dir;
}

function migrationRemoveDir(string $dir): void
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
            migrationRemoveDir($path);
            continue;
        }
        @unlink($path);
    }

    @rmdir($dir);
}

function migrationAssertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function bootstrapLegacySchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE schema_migrations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL UNIQUE,
            applied_at DATETIME NOT NULL,
            checksum CHAR(64) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->exec(
        "CREATE TABLE companies (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nip VARCHAR(20) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

function appliedAtDefault(PDO $pdo): ?string
{
    $stmt = $pdo->query("SHOW COLUMNS FROM schema_migrations LIKE 'applied_at'");
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
    if ($stmt instanceof PDOStatement) {
        $stmt->closeCursor();
    }

    if (!is_array($row)) {
        return null;
    }

    $default = $row['Default'] ?? null;
    return $default !== null ? strtolower((string)$default) : null;
}

function seedRegressionMigrations(string $dir): void
{
    $repairSource = __DIR__ . '/../sql/migrations/2026_04_07_03_schema_migrations_applied_at_default.sql';
    $repairTarget = $dir . '/2026_04_07_03_schema_migrations_applied_at_default.sql';
    if (!copy($repairSource, $repairTarget)) {
        throw new RuntimeException('Failed to copy repair migration.');
    }

    $mixedTarget = $dir . '/2026_04_07_90_mixed_result_sequence.sql';
    $mixedSql = <<<'SQL'
SET @cleanup_before := 41;
SELECT @cleanup_before AS cleanup_before_value;
CREATE TABLE cleanup_mixed_results (
    id INT NOT NULL PRIMARY KEY
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;

    if (file_put_contents($mixedTarget, $mixedSql . "\n") === false) {
        throw new RuntimeException('Failed to write mixed-result migration.');
    }
}

try {
    $config = migrationTestConfig();
    $serverPdo = migrationServerPdo($config);
    $database = migrationUniqueDatabaseName('crm_cleanup_runner');
    $migrationsDir = migrationCreateTempDir('crm_cleanup_runner');
    $logPath = sys_get_temp_dir() . '/crm_cleanup_runner.log';

    migrationCreateDatabase($serverPdo, $database, (string)$config['charset']);
    try {
        $pdo = migrationDatabasePdo($config, $database);
        bootstrapLegacySchema($pdo);
        seedRegressionMigrations($migrationsDir);

        migrationAssertTrue(appliedAtDefault($pdo) === null, 'expected legacy schema_migrations without applied_at default');

        $runner = new MigrationRunner($pdo, $migrationsDir, [
            'host' => (string)$config['host'],
            'dbname' => $database,
            'logPaths' => [$logPath],
            'emitOutput' => false,
        ]);

        $firstRun = $runner->run();
        migrationAssertTrue($firstRun === 0, 'expected first MigrationRunner pass to succeed');

        $defaultAfterFirstRun = appliedAtDefault($pdo);
        migrationAssertTrue(
            is_string($defaultAfterFirstRun) && str_starts_with($defaultAfterFirstRun, 'current_timestamp'),
            'expected repair migration to normalize applied_at default'
        );

        $mixedTableExists = (bool)$pdo->query("SHOW TABLES LIKE 'cleanup_mixed_results'")->fetchColumn();
        migrationAssertTrue($mixedTableExists, 'expected mixed-result migration to create cleanup_mixed_results table');

        $appliedCount = (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        migrationAssertTrue($appliedCount === 2, 'expected two migrations recorded after first run');

        $recordedCount = (int)$pdo->query(
            "SELECT COUNT(*) FROM schema_migrations
             WHERE filename IN (
                 '2026_04_07_03_schema_migrations_applied_at_default.sql',
                 '2026_04_07_90_mixed_result_sequence.sql'
             )"
        )->fetchColumn();
        migrationAssertTrue($recordedCount === 2, 'expected repair and mixed-result migrations recorded');

        $nullAppliedAtCount = (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations WHERE applied_at IS NULL')->fetchColumn();
        migrationAssertTrue($nullAppliedAtCount === 0, 'expected applied_at to be populated for recorded migrations');

        $secondRun = $runner->run();
        migrationAssertTrue($secondRun === 0, 'expected second MigrationRunner pass to succeed');

        $appliedCountAfterSecondRun = (int)$pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        migrationAssertTrue($appliedCountAfterSecondRun === 2, 'expected second run to stay idempotent');

        echo json_encode([
            'database' => $database,
            'first_run_exit_code' => $firstRun,
            'second_run_exit_code' => $secondRun,
            'applied_at_default_after_first_run' => $defaultAfterFirstRun,
            'schema_migrations_count' => $appliedCountAfterSecondRun,
            'mixed_result_table_exists' => $mixedTableExists,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    } finally {
        migrationRemoveDir($migrationsDir);
        migrationDropDatabase($serverPdo, $database);
    }
} catch (Throwable $e) {
    $message = (string)$e->getMessage();
    $normalized = strtolower($message);
    $isDbUnavailable = str_contains($message, 'SQLSTATE[HY000] [2002]')
        || str_contains($normalized, 'connection refused')
        || str_contains($normalized, 'nie można nawiązać połączenia')
        || str_contains($normalized, 'actively refused');
    if ($isDbUnavailable) {
        fwrite(STDOUT, "SKIP: MySQL unavailable for migration_cleanup_regression ({$message})" . PHP_EOL);
        exit(0);
    }
    fwrite(STDERR, $message . PHP_EOL);
    exit(1);
}
