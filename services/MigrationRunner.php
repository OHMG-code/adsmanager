<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationPlan.php';
require_once __DIR__ . '/MigrationConstraints.php';

final class MigrationRunner
{
    private const SCHEMA_TABLE = 'schema_migrations';
    private const COMPANIES_TABLE = 'companies';
    private const BOOTSTRAP_MIGRATION = '2026_01_27_00_create_companies.sql';
    private const LEGACY_COMPANIES_SOURCE_PATH = __DIR__ . '/../sql/install/legacy_companies_source.sql';
    private const FORCE_SOURCE_LIMIT = 20971520; // 20 MB
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
    private bool $emitOutput;
    /** @var array<int,string>|null */
    private ?array $schemaMigrationColumns = null;
    /** @var callable|null */
    private $duplicateChecker;

    /**
     * @param array{logPaths?: string[], dryRun?: bool, force?: bool, host?: string, dbname?: string, duplicateChecker?: callable|null, emitOutput?: bool} $options
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
        $this->emitOutput = !array_key_exists('emitOutput', $options) || (bool)$options['emitOutput'];
        $this->duplicateChecker = $options['duplicateChecker'] ?? null;
        $this->prepareLogPaths();
        try {
            if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        } catch (PDOException $e) {
            $this->log('Failed to enable buffered queries: ' . $e->getMessage(), true);
        }
    }

    public function run(): int
    {
        $this->log(sprintf('Starting migration run (dry-run=%s, force=%s).', $this->dryRun ? '1' : '0', $this->force ? '1' : '0'));
        $this->logConnectionInfo();

        try {
            if (!$this->dryRun) {
                $this->ensureSchemaMigrationsTable();
                $this->ensureSchemaMigrationColumns();
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
        $created = false;
        if (!$this->tableExists(self::SCHEMA_TABLE)) {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS ' . self::SCHEMA_TABLE . ' (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    filename VARCHAR(255) NOT NULL UNIQUE,
                    migration_name VARCHAR(255) NULL,
                    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    executed_at DATETIME NULL,
                    success TINYINT(1) NOT NULL DEFAULT 1,
                    execution_time_ms INT UNSIGNED NULL,
                    notes VARCHAR(255) NULL,
                    checksum CHAR(64) NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
            );
            $created = true;
        }

        $this->ensureSchemaMigrationColumns();
        if ($created) {
            $this->log('Created schema_migrations table.');
        }
    }

    private function ensureSchemaMigrationColumns(): void
    {
        $columns = [
            'checksum' => 'ADD COLUMN checksum CHAR(64) NULL',
            'migration_name' => 'ADD COLUMN migration_name VARCHAR(255) NULL AFTER filename',
            'executed_at' => 'ADD COLUMN executed_at DATETIME NULL AFTER applied_at',
            'success' => 'ADD COLUMN success TINYINT(1) NOT NULL DEFAULT 1 AFTER executed_at',
            'execution_time_ms' => 'ADD COLUMN execution_time_ms INT UNSIGNED NULL AFTER success',
            'notes' => 'ADD COLUMN notes VARCHAR(255) NULL AFTER execution_time_ms',
        ];
        foreach ($columns as $column => $ddl) {
            try {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM " . self::SCHEMA_TABLE . " LIKE " . $this->pdo->quote($column));
                $exists = (bool)($stmt && $stmt->fetch());
                if ($stmt) {
                    $stmt->closeCursor();
                }
                if ($exists) {
                    continue;
                }
                $this->pdo->exec("ALTER TABLE " . self::SCHEMA_TABLE . " " . $ddl);
                $this->log('Added ' . $column . ' column to schema_migrations.');
            } catch (PDOException $e) {
                $this->log('Unable to ensure column ' . $column . ': ' . $e->getMessage(), true);
            }
        }

        try {
            if ($this->hasSchemaMigrationColumn('migration_name')) {
                $this->pdo->exec('UPDATE ' . self::SCHEMA_TABLE . ' SET migration_name = filename WHERE migration_name IS NULL OR migration_name = ""');
            }
            if ($this->hasSchemaMigrationColumn('executed_at')) {
                $this->pdo->exec('UPDATE ' . self::SCHEMA_TABLE . ' SET executed_at = COALESCE(executed_at, applied_at, CURRENT_TIMESTAMP) WHERE executed_at IS NULL');
            }
        } catch (PDOException $e) {
            $this->log('Unable to backfill schema_migrations metadata: ' . $e->getMessage(), true);
        }

        $this->refreshSchemaMigrationColumns();
    }

    private function loadAppliedMigrations(): void
    {
        if (!$this->tableExists(self::SCHEMA_TABLE)) {
            $this->appliedMigrations = [];
            $this->log('schema_migrations table is not present; assuming no migrations applied.', false);
            return;
        }

        try {
            $this->refreshSchemaMigrationColumns();
            $nameColumn = $this->hasSchemaMigrationColumn('filename') ? 'filename' : 'migration_name';
            $stmt = $this->pdo->query(sprintf('SELECT %s FROM %s ORDER BY %s ASC', $nameColumn, self::SCHEMA_TABLE, $nameColumn));
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

        $definition = $this->extractCreateTableDefinitionFromLegacySource();
        if ($definition !== null && file_exists(self::LEGACY_COMPANIES_SOURCE_PATH)) {
        $command = sprintf('mysql -h %s -u %s -p %s < %s',
            $this->host,
            $this->user !== '' ? $this->user : '[user]',
            $this->dbname,
            self::LEGACY_COMPANIES_SOURCE_PATH
        );
            if (!$this->force) {
                $this->log('companies table definition found in optional legacy SQL source. Import the SQL file manually with the following command:', true);
                $this->log('    ' . $command, true);
                $this->log('Alternatively rerun with --force=1 to import automatically.', true);
                return false;
            }

            if (!$this->isLegacySourceSizeReasonable()) {
                $this->log('The legacy SQL source file is too large to import automatically; please run the mysql CLI instead.', true);
                $this->log('    ' . $command, true);
                return false;
            }

            try {
                $this->log('Importing optional legacy SQL source to restore the companies table (force).');
                $this->executeSqlFile(self::LEGACY_COMPANIES_SOURCE_PATH, false);
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

        $this->log('Unable to locate companies definition in optional legacy SQL source; add the bootstrap migration manually.', true);
        return false;
    }

    private function extractCreateTableDefinitionFromLegacySource(): ?string
    {
        if (!file_exists(self::LEGACY_COMPANIES_SOURCE_PATH)) {
            return null;
        }
        $content = file_get_contents(self::LEGACY_COMPANIES_SOURCE_PATH);
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
            if ($stmt instanceof PDOStatement) {
                $stmt->closeCursor();
            }
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
        $current = '';
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
                $current = $trimmed;
                $stmt = null;
                try {
                    $stmt = $this->pdo->prepare($trimmed);
                    if ($stmt === false) {
                        throw new RuntimeException('Failed to prepare statement.');
                    }
                    $stmt->execute();
                    if ($stmt->columnCount() > 0) {
                        $stmt->fetchAll();
                    }
                } finally {
                    if ($stmt instanceof PDOStatement) {
                        $stmt->closeCursor();
                    }
                }
            }
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new RuntimeException(
                sprintf(
                    'Error in %s: %s%s',
                    $filename,
                    $e->getMessage(),
                    $current !== '' ? ' [statement=' . $current . ']' : ''
                ),
                0,
                $e
            );
        }
        $duration = microtime(true) - $startTime;
        $durationMs = (int)round($duration * 1000);
        $this->log(sprintf('Executed %s in %.2fs.', $filename, $duration));

        if ($recordMigration) {
            $this->recordSchemaMigration($filename, $checksum, $durationMs, null);
        }
    }

    private function recordSchemaMigration(string $filename, string $checksum, ?int $executionTimeMs = null, ?string $notes = null): void
    {
        if ($this->dryRun) {
            return;
        }
        $this->refreshSchemaMigrationColumns();
        $executedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $fields = ['filename'];
        $params = [':filename' => $filename];
        if ($this->hasSchemaMigrationColumn('migration_name')) {
            $fields[] = 'migration_name';
            $params[':migration_name'] = $filename;
        }
        if ($this->hasSchemaMigrationColumn('applied_at')) {
            $fields[] = 'applied_at';
            $params[':applied_at'] = $executedAt;
        }
        if ($this->hasSchemaMigrationColumn('executed_at')) {
            $fields[] = 'executed_at';
            $params[':executed_at'] = $executedAt;
        }
        if ($this->hasSchemaMigrationColumn('success')) {
            $fields[] = 'success';
            $params[':success'] = 1;
        }
        if ($this->hasSchemaMigrationColumn('checksum')) {
            $fields[] = 'checksum';
            $params[':checksum'] = $checksum;
        }
        if ($this->hasSchemaMigrationColumn('execution_time_ms')) {
            $fields[] = 'execution_time_ms';
            $params[':execution_time_ms'] = $executionTimeMs;
        }
        if ($this->hasSchemaMigrationColumn('notes')) {
            $fields[] = 'notes';
            $params[':notes'] = $notes;
        }

        $placeholders = [];
        foreach ($fields as $field) {
            $placeholders[] = ':' . $field;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . self::SCHEMA_TABLE . ' (' . implode(', ', $fields) . ')
             VALUES (' . implode(', ', $placeholders) . ')'
        );
        $stmt->execute($params);
        $stmt->closeCursor();
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

    private function refreshSchemaMigrationColumns(): void
    {
        $this->schemaMigrationColumns = [];
        try {
            $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . self::SCHEMA_TABLE);
            if ($stmt === false) {
                return;
            }
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $field = trim((string)($row['Field'] ?? ''));
                if ($field !== '') {
                    $this->schemaMigrationColumns[] = $field;
                }
            }
            $stmt->closeCursor();
        } catch (PDOException $e) {
            $this->schemaMigrationColumns = [];
        }
    }

    private function hasSchemaMigrationColumn(string $column): bool
    {
        if ($this->schemaMigrationColumns === null) {
            $this->refreshSchemaMigrationColumns();
        }
        return in_array($column, $this->schemaMigrationColumns ?? [], true);
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
            $exists = (bool)$stmt->fetchColumn();
            $stmt->closeCursor();
            return $exists;
        } catch (PDOException $e) {
            $this->log('Table existence check failed for ' . $table . ': ' . $e->getMessage(), true);
            return false;
        }
    }

    private function isLegacySourceSizeReasonable(): bool
    {
        if (!file_exists(self::LEGACY_COMPANIES_SOURCE_PATH)) {
            return false;
        }
        $size = filesize(self::LEGACY_COMPANIES_SOURCE_PATH);
        return $size !== false && $size <= self::FORCE_SOURCE_LIMIT;
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

        if (!$this->emitOutput) {
            return;
        }

        if ($error) {
            fwrite(STDERR, $message . PHP_EOL);
        } else {
            echo $message . PHP_EOL;
        }
    }
}
