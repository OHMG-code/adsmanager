<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationPlan.php';
require_once __DIR__ . '/MigrationConstraints.php';

final class MigrationRunner
{
    private const SCHEMA_TABLE = 'schema_migrations';
    private const COMPANIES_TABLE = 'companies';
    private const BOOTSTRAP_MIGRATION = '2026_01_27_00_create_companies.sql';
    private const DUMP_PATH = __DIR__ . '/../sql/01214144_crm.sql';
    private const FORCE_DUMP_LIMIT = 20971520; // 20 MB
    private const PRIORITY_ORDER = [
        '11B_klienci_legacy_nullable',
        '10B_canonical_schema',
        '11D_companies_provenance',
        '12A_gus_refresh_queue',
        '12F_queue_error_fields',
        '12H_company_gus_hold',
        '12I_integration_circuit_breaker',
        '12J_admin_actions_audit',
        '12D_integration_alerts',
        '12L_worker_locks',
        '11C_unique_companies_nip',
    ];

    private PDO $pdo;
    private string $migrationsDir;
    private array $logPaths;
    private bool $dryRun;
    private bool $force;
    private string $host;
    private string $user;
    private string $dbname;
    private array $appliedMigrations = [];
    private bool $bootstrapRequired = false;
    private ?bool $duplicateCompanies = null;
    private ?callable $duplicateChecker;

    /**
     * @param array{logPaths?: string[], dryRun?: bool, force?: bool, host?: string, dbname?: string, duplicateChecker?: callable|null} $options
     */
    public function __construct(PDO $pdo, string $migrationsDir, array $options = [])
    {
        $this->pdo = $pdo;
        $this->migrationsDir = $migrationsDir;
        $this->logPaths = array_values(array_filter((array)($options['logPaths'] ?? []), static fn ($item): bool => is_string($item) && $item !== ''));
        $this->dryRun = !empty($options['dryRun']);
        $this->force = !empty($options['force']);
        $this->host = (string)($options['host'] ?? '');
        $this->user = (string)($options['user'] ?? '');
        $this->dbname = (string)($options['dbname'] ?? '');
        $this->duplicateChecker = $options['duplicateChecker'] ?? null;
        $this->prepareLogPaths();
    }

    public function run(): int
    {
        $this->log(sprintf('Starting migration run (dry-run=%s, force=%s).', $this->dryRun ? '1' : '0', $this->force ? '1' : '0'));
        $this->logConnectionInfo();

        try {
            if (!$this->dryRun) {
                $this->ensureSchemaMigrationsTable();
            }

            $this->loadAppliedMigrations();

            if (!$this->tableExists(self::COMPANIES_TABLE)) {
                $this->bootstrapRequired = true;
                if (!$this->handleMissingCompanies()) {
                    $this->log('Cannot continue without companies table.', true);
                    return 1;
                }
            }

            $migrationFiles = $this->collectMigrationFiles();
            if ($migrationFiles === []) {
                $this->log('No SQL migration files were discovered.');
                $this->log('Migration run completed.');
                return 0;
            }

            $ordered = MigrationPlan::build(
                $migrationFiles,
                [
                    'bootstrap' => $this->bootstrapRequired ? [self::BOOTSTRAP_MIGRATION] : [],
                    'priority' => self::PRIORITY_ORDER,
                ]
            );

            $containsUniqueMigration = $this->containsUniqueConstraintMigration($ordered);
            $duplicatesFound = false;
            if ($containsUniqueMigration) {
                $duplicatesFound = $this->detectDuplicateCompanies();
                if ($duplicatesFound) {
                    $this->log('Duplicate company NIP detected; the unique-companies migration will be skipped.');
                }
            }

            $ordered = MigrationConstraints::filterUniqueNipMigration($ordered, $duplicatesFound);

            $this->logMigrationPlan($ordered);
            $this->applyMigrations($ordered);

            $this->log('Migration run completed.');
            return 0;
        } catch (\Throwable $e) {
            $this->log('Migration run failed: ' . $e->getMessage(), true);
            return 1;
        }
    }

    private function logConnectionInfo(): void
    {
        $host = $this->host !== '' ? $this->host : 'unknown';
        $db = $this->dbname !== '' ? $this->dbname : 'unknown';
        $this->log(sprintf('DB host: %s; database: %s.', $host, $db));
    }

    private function ensureSchemaMigrationsTable(): void
    {
        if ($this->tableExists(self::SCHEMA_TABLE)) {
            return;
        }

        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::SCHEMA_TABLE . ' (
                id INT AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL UNIQUE,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                checksum CHAR(64) NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $this->log('Created schema_migrations table.');
        $this->ensureSchemaChecksumColumn();
    }

    private function ensureSchemaChecksumColumn(): void
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM " . self::SCHEMA_TABLE . " LIKE 'checksum'");
            if ($stmt) {
                $found = $stmt->fetch();
                $stmt->closeCursor();
                if ($found) {
                    return;
                }
            }
            $this->pdo->exec("ALTER TABLE " . self::SCHEMA_TABLE . " ADD COLUMN checksum CHAR(64) NULL");
            $this->log('Added checksum column to schema_migrations.');
        } catch (PDOException $e) {
            $this->log('Unable to ensure checksum column: ' . $e->getMessage(), true);
        }
    }

    private function loadAppliedMigrations(): void
    {
        if (!$this->tableExists(self::SCHEMA_TABLE)) {
            $this->appliedMigrations = [];
            $this->log('schema_migrations table is not present; assuming no migrations applied.', false);
            return;
        }

        try {
            $stmt = $this->pdo->query(sprintf('SELECT filename FROM %s ORDER BY filename ASC', self::SCHEMA_TABLE));
            $migrated = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];
            if ($stmt) {
                $stmt->closeCursor();
            }
            $this->appliedMigrations = is_array($migrated) ? $migrated : [];
        } catch (PDOException $e) {
            $this->log('Failed to read schema_migrations: ' . $e->getMessage(), true);
            $this->appliedMigrations = [];
        }
    }

    private function handleMissingCompanies(): bool
    {
        if ($this->dryRun) {
            $this->log('Dry-run: companies table missing; bootstrap migration will remain in the plan.', false);
            return true;
        }

        $definition = $this->extractCreateTableDefinitionFromDump();
        if ($definition !== null && file_exists(self::DUMP_PATH)) {
        $command = sprintf('mysql -h %s -u %s -p %s < %s',
            $this->host,
            $this->user !== '' ? $this->user : '[user]',
            $this->dbname,
            self::DUMP_PATH
        );
            if (!$this->force) {
                $this->log('companies table definition found in SQL dump. Import the dump manually with the following command:', true);
                $this->log('    ' . $command, true);
                $this->log('Alternatively rerun with --force=1 to import automatically.', true);
                return false;
            }

            if (!$this->isDumpSizeReasonable()) {
                $this->log('The dump file is too large to import automatically; please run the mysql CLI instead.', true);
                $this->log('    ' . $command, true);
                return false;
            }

            try {
                $this->log('Importing SQL dump to restore the companies table (force).');
                $this->executeSqlFile(self::DUMP_PATH, false);
            } catch (\Throwable $e) {
                $this->log('Automatic import failed: ' . $e->getMessage(), true);
                return false;
            }

            $this->bootstrapRequired = false;

            return $this->tableExists(self::COMPANIES_TABLE);
        }

        if ($this->bootstrapMigrationExists()) {
            $this->bootstrapRequired = true;
            $this->log('Bootstrap migration will create companies table.', false);
            return true;
        }

        $this->log('Unable to locate companies definition in the dump or repository; add the bootstrap migration manually.', true);
        return false;
    }

    private function extractCreateTableDefinitionFromDump(): ?string
    {
        if (!file_exists(self::DUMP_PATH)) {
            return null;
        }
        $content = file_get_contents(self::DUMP_PATH);
        if ($content === false) {
            return null;
        }
        if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?companies`?\s*\([^;]+;/is', $content, $matches)) {
            return trim($matches[0]);
        }
        return null;
    }

    private function bootstrapMigrationExists(): bool
    {
        $path = $this->migrationsDir . '/' . self::BOOTSTRAP_MIGRATION;
        return file_exists($path);
    }

    private function collectMigrationFiles(): array
    {
        if (!is_dir($this->migrationsDir)) {
            throw new RuntimeException('Migrations directory not found: ' . $this->migrationsDir);
        }
        $files = glob($this->migrationsDir . '/*.sql');
        return $files ?: [];
    }

    private function containsUniqueConstraintMigration(array $files): bool
    {
        foreach ($files as $file) {
            if (MigrationConstraints::isUniqueNipMigration($file)) {
                return true;
            }
        }
        return false;
    }

    private function detectDuplicateCompanies(): bool
    {
        if ($this->duplicateCompanies !== null) {
            return $this->duplicateCompanies;
        }

        if (is_callable($this->duplicateChecker)) {
            $this->duplicateCompanies = (bool)call_user_func($this->duplicateChecker);
            return $this->duplicateCompanies;
        }

        try {
            $stmt = $this->pdo->query(
                "SELECT nip FROM `{self::COMPANIES_TABLE}` WHERE nip IS NOT NULL GROUP BY nip HAVING COUNT(*) > 1 LIMIT 1"
            );
            $this->duplicateCompanies = $stmt !== false && $stmt->fetchColumn() !== false;
            return $this->duplicateCompanies;
        } catch (PDOException $e) {
            $this->log('Failed to check duplicate companies: ' . $e->getMessage(), true);
            $this->duplicateCompanies = false;
            return false;
        }
    }

    private function applyMigrations(array $orderedPaths): void
    {
        foreach ($orderedPaths as $path) {
            $filename = basename($path);
            if (in_array($filename, $this->appliedMigrations, true)) {
                $this->log('Skipping already applied migration: ' . $filename);
                continue;
            }
            $this->executeSqlFile($path, true);
        }
    }

    /**
     * @param string[] $paths
     */
    private function logMigrationPlan(array $paths): void
    {
        if ($paths === []) {
            $this->log('No migrations are scheduled for this run.');
            return;
        }
        $this->log('Migration plan:');
        foreach ($paths as $path) {
            $filename = basename($path);
            $status = in_array($filename, $this->appliedMigrations, true) ? 'already applied' : 'pending';
            $this->log(sprintf('  %s (%s)', $filename, $status));
        }
    }

    private function executeSqlFile(string $path, bool $recordMigration): void
    {
        $filename = basename($path);
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Unable to read SQL file: ' . $path);
        }

        if (preg_match('/^\s*DELIMITER\b/im', $content)) {
            throw new RuntimeException(sprintf('File %s uses DELIMITER; run it manually via mysql CLI.', $filename));
        }

        $checksum = $this->computeChecksum($content);
        $statements = $this->splitSqlStatements($content);
        $this->log(sprintf('Preparing to run %s (%d statement%s).', $filename, count($statements), count($statements) === 1 ? '' : 's'));

        if ($this->dryRun) {
            $this->log('Dry-run: skipping execution of ' . $filename);
            return;
        }

        $transactionStarted = false;
        try {
            $transactionStarted = $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            $this->log('Unable to start transaction: ' . $e->getMessage(), true);
            $transactionStarted = false;
        }

        $startTime = microtime(true);
        try {
            foreach ($statements as $statement) {
                $trimmed = trim($statement);
                if ($trimmed === '') {
                    continue;
                }
                $this->pdo->exec($trimmed);
            }
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException(sprintf('Error in %s: %s', $filename, $e->getMessage()), 0, $e);
        }
        $duration = microtime(true) - $startTime;
        $this->log(sprintf('Executed %s in %.2fs.', $filename, $duration));

        if ($recordMigration) {
            $this->recordSchemaMigration($filename, $checksum);
        }
    }

    private function recordSchemaMigration(string $filename, string $checksum): void
    {
        if ($this->dryRun) {
            return;
        }
        $stmt = $this->pdo->prepare('INSERT INTO ' . self::SCHEMA_TABLE . ' (filename, checksum) VALUES (:filename, :checksum)');
        $stmt->execute(['filename' => $filename, 'checksum' => $checksum]);
        $this->appliedMigrations[] = $filename;
    }

    private function computeChecksum(string $content): string
    {
        return hash('sha256', $content);
    }

    private function splitSqlStatements(string $sql): array
    {
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $length = strlen($sql);
        $statements = [];
        $buffer = '';
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $inLineComment = false;
        $inBlockComment = false;
        $escape = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }
                $buffer .= $char;
                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && $next === '/') {
                    $inBlockComment = false;
                    $buffer .= '*/';
                    $i++;
                    continue;
                }
                $buffer .= $char;
                continue;
            }

            if (!$inSingle && !$inDouble && !$inBacktick) {
                if ($char === '-' && $next === '-') {
                    $inLineComment = true;
                    $buffer .= '--';
                    $i++;
                    continue;
                }
                if ($char === '#') {
                    $inLineComment = true;
                    $buffer .= $char;
                    continue;
                }
                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $buffer .= '/*';
                    $i++;
                    continue;
                }
            }

            if ($char === '\\' && !$escape) {
                $escape = true;
                $buffer .= $char;
                continue;
            }

            if ($char === "'" && !$inDouble && !$inBacktick && !$escape) {
                $inSingle = !$inSingle;
            }
            if ($char === '"' && !$inSingle && !$inBacktick && !$escape) {
                $inDouble = !$inDouble;
            }
            if ($char === '`' && !$inSingle && !$inDouble && !$escape) {
                $inBacktick = !$inBacktick;
            }

            $escape = false;

            if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
                $statements[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $statements[] = $buffer;
        }

        return $statements;
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table LIMIT 1'
            );
            $stmt->execute([
                'schema' => $this->dbname,
                'table' => $table,
            ]);
            return (bool)$stmt->fetchColumn();
        } catch (PDOException $e) {
            $this->log('Table existence check failed for ' . $table . ': ' . $e->getMessage(), true);
            return false;
        }
    }

    private function isDumpSizeReasonable(): bool
    {
        if (!file_exists(self::DUMP_PATH)) {
            return false;
        }
        $size = filesize(self::DUMP_PATH);
        return $size !== false && $size <= self::FORCE_DUMP_LIMIT;
    }

    private function prepareLogPaths(): void
    {
        foreach ($this->logPaths as $path) {
            $dir = dirname($path);
            if ($dir !== '' && !is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }
    }

    private function log(string $message, bool $error = false): void
    {
        $line = sprintf('[%s] %s', (new DateTimeImmutable())->format('Y-m-d H:i:s'), $message);
        foreach ($this->logPaths as $path) {
            file_put_contents($path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }

        if ($error) {
            fwrite(STDERR, $message . PHP_EOL);
        } else {
            echo $message . PHP_EOL;
        }
    }
}
