<?php

declare(strict_types=1);

require_once __DIR__ . '/MigrationPlan.php';
require_once __DIR__ . '/MigrationConstraints.php';
require_once __DIR__ . '/MigrationSqlCompat.php';

final class MigrationConsole
{
    private const SCHEMA_TABLE = 'schema_migrations';
    private const PRIORITY_ORDER = [
        '10B_canonical_schema',
        '11B_klienci_legacy_nullable',
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
    private const DEFAULT_BATCH_LIMIT = 5;
    private const DEFAULT_BATCH_SECONDS = 15;

    private PDO $pdo;
    private string $migrationsDir;
    private string $logPath;
    private string $actor;
    private string $ip;
    private array $appliedMigrations = [];
    private ?bool $duplicateNip = null;
    private ?string $databaseName = null;
    /** @var array<int,string>|null */
    private ?array $schemaMigrationColumns = null;

    public function __construct(PDO $pdo, string $migrationsDir, string $logPath, string $actor = 'web', string $ip = 'unknown')
    {
        $this->pdo = $pdo;
        $this->migrationsDir = rtrim($migrationsDir, DIRECTORY_SEPARATOR);
        $this->logPath = $logPath;
        $this->actor = trim($actor) !== '' ? trim($actor) : 'web';
        $this->ip = $ip !== '' ? $ip : 'unknown';
        try {
            if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                $this->pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        } catch (PDOException $e) {
            $this->log('WARN', 'Failed to enable buffered queries: ' . $e->getMessage());
        }
    }

    public function ensureSchemaMigrations(): void
    {
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
        $this->addSchemaMigrationColumnsIfMissing();
        $this->refreshSchemaMigrationColumns();
    }

    public function listMigrations(): array
    {
        $this->ensureSchemaMigrations();
        $this->refreshAppliedMigrations();

        $ordered = $this->getOrderedMigrationFiles();
        $duplicates = $this->hasDuplicateCompaniesNip();
        $result = [];

        foreach ($ordered as $path) {
            $filename = basename($path);
            $applied = $this->appliedMigrations[$filename] ?? null;
            $skipReason = null;
            if ($duplicates && MigrationConstraints::isUniqueNipMigration($filename)) {
                $skipReason = 'duplicate_nip';
            }
            $result[] = [
                'filename' => $filename,
                'path' => $path,
                'applied' => $applied !== null,
                'applied_at' => $applied['applied_at'] ?? null,
                'checksum' => $applied['checksum'] ?? null,
                'applied_success' => $applied['success'] ?? null,
                'execution_time_ms' => $applied['execution_time_ms'] ?? null,
                'notes' => $applied['notes'] ?? null,
                'skip_reason' => $skipReason,
            ];
        }

        return $result;
    }

    public function hasCompaniesTable(): bool
    {
        return $this->tableExists('companies');
    }

    public function hasDuplicateCompaniesNip(): bool
    {
        if ($this->duplicateNip !== null) {
            return $this->duplicateNip;
        }
        if (!$this->hasCompaniesTable()) {
            return $this->duplicateNip = false;
        }

        try {
            $stmt = $this->pdo->query("SELECT nip FROM `companies` WHERE nip IS NOT NULL GROUP BY nip HAVING COUNT(*) > 1 LIMIT 1");
            if ($stmt !== false) {
                $this->duplicateNip = $stmt->fetchColumn() !== false;
                $stmt->closeCursor();
            } else {
                $this->duplicateNip = false;
            }
        } catch (PDOException $e) {
            $this->duplicateNip = false;
            $this->log('WARN', 'Failed to check duplicate NIP: ' . $e->getMessage());
        }
        return $this->duplicateNip;
    }

    public function runMigration(string $filename): array
    {
        $entry = $this->findMigrationEntry($filename);
        if ($entry === null) {
            return ['success' => false, 'message' => 'Migration file not found: ' . $filename];
        }
        if ($entry['applied']) {
            return ['success' => true, 'message' => 'Already applied: ' . $filename];
        }
        if ($entry['skip_reason']) {
            return ['success' => true, 'message' => 'Skipped (duplicate NIP): ' . $filename];
        }
        return $this->executeMigrationEntry($entry);
    }

    public function runNextMigration(): array
    {
        $pending = $this->getPendingMigrationEntries();
        if ($pending === []) {
            return ['results' => [], 'continue' => false, 'remaining' => 0];
        }
        $entry = $pending[0];
        $start = microtime(true);
        $result = $this->executeMigrationEntry($entry);
        $remaining = count($this->getPendingMigrationEntries());
        return [
            'results' => [$result],
            'continue' => $remaining > 0,
            'remaining' => $remaining,
            'executed' => 1,
            'duration' => microtime(true) - $start,
        ];
    }

    public function runBatchMigrations(int $maxFiles = self::DEFAULT_BATCH_LIMIT, int $maxSeconds = self::DEFAULT_BATCH_SECONDS): array
    {
        $pending = $this->getPendingMigrationEntries();
        if ($pending === []) {
            return ['results' => [], 'executed' => 0, 'continue' => false, 'remaining' => 0];
        }
        $start = microtime(true);
        $results = [];
        $executed = 0;

        foreach ($pending as $entry) {
            if ($executed >= $maxFiles) {
                break;
            }
            if (microtime(true) - $start >= $maxSeconds) {
                break;
            }
            $result = $this->executeMigrationEntry($entry);
            $results[] = $result;
            $executed++;
            if (!$result['success']) {
                break;
            }
        }

        $remaining = count($this->getPendingMigrationEntries());
        return [
            'results' => $results,
            'executed' => $executed,
            'continue' => $remaining > 0,
            'remaining' => $remaining,
            'duration' => microtime(true) - $start,
        ];
    }

    public function getConnectionInfo(): array
    {
        try {
            $stmt = $this->pdo->query('SELECT HOST_NAME(), DATABASE()');
            $row = null;
            if ($stmt) {
                $row = $stmt->fetch(PDO::FETCH_NUM);
                $stmt->closeCursor();
            }
            return [
                'host' => $row[0] ?? 'unknown',
                'database' => $row[1] ?? 'unknown',
            ];
        } catch (PDOException $e) {
            return ['host' => 'unknown', 'database' => 'unknown'];
        }
    }

    private function findMigrationEntry(string $filename): ?array
    {
        foreach ($this->listMigrations() as $entry) {
            if ($entry['filename'] === $filename) {
                return $entry;
            }
        }
        return null;
    }

    private function getPendingMigrationEntries(): array
    {
        return array_values(array_filter($this->listMigrations(), static fn (array $entry): bool => !$entry['applied'] && $entry['skip_reason'] === null));
    }

    private function getOrderedMigrationFiles(): array
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }
        $files = glob($this->migrationsDir . DIRECTORY_SEPARATOR . '*.sql');
        if ($files === false) {
            return [];
        }
        $files = array_values($files);
        sort($files, SORT_STRING);

        if (!$this->hasCompaniesTable()) {
            $bootstrap = null;
            foreach ($files as $idx => $path) {
                if (basename($path) === '2026_01_27_00_create_companies.sql') {
                    $bootstrap = $path;
                    unset($files[$idx]);
                    break;
                }
            }
            if ($bootstrap !== null) {
                $files = array_values($files);
                array_unshift($files, $bootstrap);
            }
        }

        return $files;
    }

    private function executeMigrationEntry(array $entry): array
    {
        try {
            $result = $this->runSqlFile($entry['path']);
            $durationMs = (int)($result['execution_time_ms'] ?? 0);
            return [
                'filename' => $entry['filename'],
                'success' => true,
                'message' => sprintf('Executed %s (%d statements, %d ms).', $entry['filename'], $result['statement_count'], max(0, $durationMs)),
            ];
        } catch (\Throwable $e) {
            return [
                'filename' => $entry['filename'],
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function runSqlFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Migration file is not readable: ' . $path);
        }
        $filename = basename($path);
        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Unable to load migration file: ' . $filename);
        }
        if (preg_match('/^\s*DELIMITER\b/im', $content)) {
            throw new RuntimeException('Migration ' . $filename . ' uses DELIMITER and must be run via mysql CLI.');
        }

        $statements = $this->splitSqlStatements($content);
        $statementCount = count(array_filter($statements, static fn (string $statement): bool => trim($statement) !== ''));
        if ($statementCount === 0) {
            throw new RuntimeException('Migration ' . $filename . ' contains no statements.');
        }

        $this->log('INFO', sprintf('Starting %s (%d statements).', $filename, $statementCount));
        $checksum = hash('sha256', $content);
        $transactionStarted = false;
        $current = '';
        $startTime = microtime(true);

        try {
            $transactionStarted = $this->pdo->beginTransaction();
        } catch (PDOException $e) {
            $transactionStarted = false;
        }

        $executed = 0;
        try {
            foreach ($statements as $statement) {
                $trimmed = trim($statement);
                if ($trimmed === '') {
                    continue;
                }
                $trimmed = $this->rewritePortableDdl($trimmed);
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
                $executed++;
            }
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($transactionStarted && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            $message = sprintf('Error (%s) executing statement: %s', $e->getMessage(), $current !== '' ? $current : '<empty>');
            $this->log('ERROR', sprintf('Failed %s: %s', $filename, $message));
            throw new RuntimeException($message, 0, $e);
        }

        $executionTimeMs = (int)round((microtime(true) - $startTime) * 1000);
        $this->recordSchemaMigration($filename, $checksum, $executionTimeMs, null);
        $this->log('INFO', sprintf('Finished %s in %d statements.', $filename, $executed));
        return [
            'statement_count' => $executed,
            'execution_time_ms' => $executionTimeMs,
        ];
    }

    private function recordSchemaMigration(string $filename, string $checksum, ?int $executionTimeMs = null, ?string $notes = null): void
    {
        $this->ensureSchemaMigrations();
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
        $this->appliedMigrations[$filename] = [
            'applied_at' => $executedAt,
            'checksum' => $checksum,
            'success' => true,
            'execution_time_ms' => $executionTimeMs,
            'notes' => $notes,
        ];
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
        $escaped = false;

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

            if ($char === '\\' && !$escaped) {
                $escaped = true;
                $buffer .= $char;
                continue;
            }

            if ($char === "'" && !$inDouble && !$inBacktick && !$escaped) {
                $inSingle = !$inSingle;
            }
            if ($char === '"' && !$inSingle && !$inBacktick && !$escaped) {
                $inDouble = !$inDouble;
            }
            if ($char === '`' && !$inSingle && !$inDouble && !$escaped) {
                $inBacktick = !$inBacktick;
            }

            $escaped = false;

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

    private function rewritePortableDdl(string $statement): string
    {
        return MigrationSqlCompat::rewritePortableDdl(
            $statement,
            fn (string $table, string $column): bool => $this->columnExists($table, $column),
            fn (string $table, string $index): bool => $this->indexExists($table, $index),
            function (string $message): void {
                $this->log('INFO', $message);
            }
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :table AND column_name = :column LIMIT 1'
            );
            $stmt->execute([
                ':table' => $table,
                ':column' => $column,
            ]);
            $exists = (bool)$stmt->fetchColumn();
            $stmt->closeCursor();
            return $exists;
        } catch (PDOException $e) {
            $this->log('WARN', 'Column existence check failed for ' . $table . '.' . $column . ': ' . $e->getMessage());
            return false;
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        try {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index_name LIMIT 1'
            );
            $stmt->execute([
                ':table' => $table,
                ':index_name' => $index,
            ]);
            $exists = (bool)$stmt->fetchColumn();
            $stmt->closeCursor();
            return $exists;
        } catch (PDOException $e) {
            $this->log('WARN', 'Index existence check failed for ' . $table . '.' . $index . ': ' . $e->getMessage());
            return false;
        }
    }

    private function addSchemaMigrationColumnsIfMissing(): void
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
                $this->pdo->exec('ALTER TABLE ' . self::SCHEMA_TABLE . ' ' . $ddl);
            } catch (PDOException $e) {
                $this->log('WARN', 'Unable to ensure column ' . $column . ': ' . $e->getMessage());
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
            $this->log('WARN', 'Unable to backfill migration metadata columns: ' . $e->getMessage());
        }
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
            $this->log('WARN', 'Unable to read schema_migrations columns: ' . $e->getMessage());
        }
    }

    private function hasSchemaMigrationColumn(string $column): bool
    {
        if ($this->schemaMigrationColumns === null) {
            $this->refreshSchemaMigrationColumns();
        }
        return in_array($column, $this->schemaMigrationColumns ?? [], true);
    }

    private function refreshAppliedMigrations(): void
    {
        $this->appliedMigrations = [];
        try {
            $this->refreshSchemaMigrationColumns();
            $nameColumn = $this->hasSchemaMigrationColumn('filename') ? 'filename' : 'migration_name';
            $dateColumn = $this->hasSchemaMigrationColumn('executed_at') ? 'executed_at' : 'applied_at';
            $checksumColumn = $this->hasSchemaMigrationColumn('checksum') ? 'checksum' : 'NULL AS checksum';
            $successColumn = $this->hasSchemaMigrationColumn('success') ? 'success' : '1 AS success';
            $executionMsColumn = $this->hasSchemaMigrationColumn('execution_time_ms') ? 'execution_time_ms' : 'NULL AS execution_time_ms';
            $notesColumn = $this->hasSchemaMigrationColumn('notes') ? 'notes' : 'NULL AS notes';
            $stmt = $this->pdo->query(
                'SELECT ' . $nameColumn . ' AS filename, ' . $dateColumn . ' AS applied_at, ' . $checksumColumn . ', ' . $successColumn . ', ' . $executionMsColumn . ', ' . $notesColumn . '
                 FROM ' . self::SCHEMA_TABLE . '
                 ORDER BY ' . $nameColumn . ' ASC'
            );
            if (!$stmt) {
                return;
            }
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $filename = trim((string)($row['filename'] ?? ''));
                if ($filename === '') {
                    continue;
                }
                $this->appliedMigrations[$filename] = [
                    'applied_at' => $row['applied_at'] ?? null,
                    'checksum' => $row['checksum'] ?? null,
                    'success' => !array_key_exists('success', $row) || (int)$row['success'] === 1,
                    'execution_time_ms' => isset($row['execution_time_ms']) ? (int)$row['execution_time_ms'] : null,
                    'notes' => $row['notes'] ?? null,
                ];
            }
            $stmt->closeCursor();
        } catch (PDOException $e) {
            $this->log('WARN', 'Failed to read schema_migrations: ' . $e->getMessage());
        }
    }

    private function tableExists(string $table): bool
    {
        $schema = $this->getDatabaseName();
        if ($schema === '') {
            return false;
        }
        try {
            $stmt = $this->pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = :schema AND table_name = :table LIMIT 1');
            $stmt->execute(['schema' => $schema, 'table' => $table]);
            $exists = (bool)$stmt->fetchColumn();
            $stmt->closeCursor();
            return $exists;
        } catch (PDOException $e) {
            $this->log('WARN', 'Table check failed for ' . $table . ': ' . $e->getMessage());
            return false;
        }
    }

    private function getDatabaseName(): string
    {
        if ($this->databaseName !== null) {
            return $this->databaseName;
        }
        try {
            $stmt = $this->pdo->query('SELECT DATABASE()');
            $value = '';
            if ($stmt) {
                $value = (string)$stmt->fetchColumn();
                $stmt->closeCursor();
            }
        } catch (PDOException $e) {
            $value = '';
        }
        return $this->databaseName = $value;
    }

    private function log(string $level, string $message): void
    {
        $dir = dirname($this->logPath);
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $line = sprintf(
            '[%s] [%s@%s] %s: %s',
            (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            $this->actor,
            $this->ip,
            $level,
            $message
        );
        @file_put_contents($this->logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
