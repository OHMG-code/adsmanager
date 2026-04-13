<?php

declare(strict_types=1);

/**
 * Self-contained DB migration runner (browser-friendly).
 * URL: /crm/public/admin/migrate_all.php?token=...&dry=1
 * URL: /crm/public/admin/migrate_all.php?token=... (run)
 * URL: /crm/public/admin/migrate_all.php?token=...&force=1
 */

$root = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
$root = rtrim($root, '/');
$GLOBALS['APP_ROOT'] = $root;

require_once $root . '/config/config.php';
require_once $root . '/public/includes/db_utils.php';

header('Content-Type: text/html; charset=utf-8');

$expectedToken = resolveMigratorToken();
$token = trim((string)($_GET['token'] ?? ''));
$dryRun = isset($_GET['dry']) && $_GET['dry'] !== '0';
$force = isset($_GET['force']) && $_GET['force'] !== '0';
$confirmYes = strtoupper(trim((string)($_GET['confirm'] ?? ''))) === 'YES';
$safeMode = ($expectedToken === '');
$forcedDryRun = false;
if (!$dryRun && !$confirmYes) {
    $forcedDryRun = true;
    $dryRun = true;
}
$actionName = resolveActionName($dryRun, $force, $forcedDryRun);

if ($safeMode) {
    require_once $root . '/public/includes/auth.php';
    requireLogin();
    $currentUser = fetchCurrentUser($pdo);
    $role = $currentUser ? normalizeRole($currentUser) : '';
    $isAdmin = in_array($role, ['Administrator', 'Manager'], true) || isSuperAdmin();
    if (!$isAdmin) {
        renderPage('Access denied', '<p>SAFE MODE requires Administrator or Manager role.</p>', 403);
    }
    $requestHost = $_SERVER['HTTP_HOST'] ?? '';
    $expectedHost = resolveExpectedHost();
    if ($expectedHost !== '' && !sameHost($requestHost, $expectedHost)) {
        renderPage('Access denied', '<p>SAFE MODE is restricted to the application host.</p>', 403);
    }
    if (!$dryRun && !$confirmYes) {
        $forcedDryRun = true;
        $dryRun = true;
    }
} else {
    if ($token === '' || !hash_equals($expectedToken, $token)) {
        renderPage('Access denied', '<p>Invalid or missing token.</p>', 403);
    }
}

$migrationsDir = realpath($root . '/sql/migrations') ?: ($root . '/sql/migrations');
[$logPath, $logWritable] = resolveLogPath($root . '/storage/logs', 'migrator.log');
$logWarnings = [];
if (!$logWritable) {
    $logWarnings[] = 'Log directory is not writable: ' . htmlspecialchars($logPath, ENT_QUOTES);
}
logLine(
    $logPath,
    'START migrate_all action=' . $actionName . ' dry=' . ($dryRun ? '1' : '0') . ' force=' . ($force ? '1' : '0') .
    ' confirm=' . ($confirmYes ? 'YES' : 'NO') . ' safe=' . ($safeMode ? '1' : '0') . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown')
);

$rows = [];
$summary = [
    'total' => 0,
    'applied' => 0,
    'skipped' => 0,
    'failed' => 0,
    'dry' => 0,
];
$lastError = null;
$lastStatement = null;
$bootstrapNotice = null;

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
    ensureSchemaMigrationsTable($pdo);

    $files = listMigrationFiles($migrationsDir);
    logLine($logPath, 'MIGRATIONS_DIR ' . $migrationsDir . ' files=' . count($files));
    $summary['total'] = count($files);

    $hasCompanies = tableExists($pdo, 'companies');
    $bootstrapInfo = ensureCompaniesMigrationFirst($files, $hasCompanies);
    $files = $bootstrapInfo['files'];
    $bootstrapNotice = $bootstrapInfo['notice'];
    if ($bootstrapNotice) {
        logLine($logPath, $bootstrapNotice);
    }

    if (!$hasCompanies && $bootstrapInfo['error']) {
        $lastError = $bootstrapInfo['error'];
        logLine($logPath, 'ERROR ' . $lastError);
    } else {
        foreach ($files as $file) {
            $filename = basename($file);
            $start = microtime(true);

            $alreadyAppliedAt = getAppliedAt($pdo, $filename);
            if (!$force && $alreadyAppliedAt !== null) {
                $rows[] = buildRow($filename, 'skipped', $start, 'Already applied at ' . $alreadyAppliedAt);
                logLine($logPath, 'SKIP ' . $filename . ' applied_at=' . $alreadyAppliedAt);
                $summary['skipped']++;
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                $rows[] = buildRow($filename, 'error', $start, 'Cannot read file');
                $summary['failed']++;
                $lastError = 'Cannot read migration file: ' . $filename;
                break;
            }

            $statements = splitSqlStatements($sql);
            $statementCount = count($statements);

            if ($dryRun) {
                $rows[] = buildRow($filename, 'dry-run', $start, 'Would execute ' . $statementCount . ' statement(s)');
                logLine($logPath, 'DRY ' . $filename . ' statements=' . $statementCount);
                $summary['dry']++;
                continue;
            }

            $result = executeStatements($pdo, $statements, $filename);
            if (!$result['ok']) {
                $summary['failed']++;
                $lastError = $result['error'] ?? 'Migration failed';
                $lastStatement = $result['statement'] ?? null;
                $rows[] = buildRow($filename, 'error', $start, $lastError);
                logLine($logPath, 'ERROR ' . $filename . ' ' . $lastError);
                break;
            }

            $durationMs = (int)round((microtime(true) - $start) * 1000);
            markApplied($pdo, $filename, $force, $durationMs);
            $rows[] = buildRow($filename, 'applied', $start, 'Executed ' . $statementCount . ' statement(s), ' . $durationMs . ' ms');
            logLine($logPath, 'OK ' . $filename . ' statements=' . $statementCount);
            $summary['applied']++;
        }
    }
} catch (Throwable $e) {
    $summary['failed']++;
    $lastError = $e->getMessage();
    logLine($logPath, 'ERROR ' . $lastError);
}

$sanity = runSanityChecks($pdo ?? null);
logLine($logPath, 'END status=' . ($lastError ? 'error' : 'ok') . ' applied=' . ($summary['applied'] ?? 0) . ' skipped=' . ($summary['skipped'] ?? 0) . ' dry=' . ($summary['dry'] ?? 0) . ' failed=' . ($summary['failed'] ?? 0));

$body = buildHtmlBody([
    'rows' => $rows,
    'summary' => $summary,
    'dryRun' => $dryRun,
    'forcedDryRun' => $forcedDryRun,
    'force' => $force,
    'logPath' => $logPath,
    'logWarnings' => $logWarnings,
    'bootstrapNotice' => $bootstrapNotice ?? null,
    'lastError' => $lastError,
    'lastStatement' => $lastStatement,
    'sanity' => $sanity,
    'safeMode' => $safeMode,
    'safeLinks' => buildSafeLinks(),
    'tokenConfigured' => $expectedToken !== '',
    'confirmYes' => $confirmYes,
    'actionName' => $actionName,
]);
renderPage('DB migration runner', $body, $lastError ? 500 : 200);

function resolveMigratorToken(): string
{
    $env = getenv('MIGRATOR_TOKEN');
    if ($env !== false && trim($env) !== '') {
        return trim($env);
    }
    if (defined('MIGRATOR_TOKEN')) {
        $val = (string)MIGRATOR_TOKEN;
        return $val !== '' ? $val : '';
    }
    $candidates = [];
    foreach (['dbConfig', 'config', 'appConfig', 'settings'] as $name) {
        if (isset($GLOBALS[$name]) && is_array($GLOBALS[$name])) {
            $candidates[] = $GLOBALS[$name];
        }
    }
    foreach ($candidates as $config) {
        if (!empty($config['migrator_token'])) {
            return trim((string)$config['migrator_token']);
        }
    }
    $envToken = readDotEnvToken($GLOBALS['APP_ROOT'] ?? '');
    if ($envToken !== '') {
        return $envToken;
    }
    return '';
}

function readDotEnvToken(string $root): string
{
    if ($root === '') {
        return '';
    }
    $path = rtrim($root, '/') . '/.env';
    if (!is_file($path) || !is_readable($path)) {
        return '';
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return '';
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        if ($key !== 'MIGRATOR_TOKEN') {
            continue;
        }
        $value = trim($value);
        $value = trim($value, "\"'");
        return $value;
    }
    return '';
}

function resolveActionName(bool $dryRun, bool $force, bool $forcedDryRun): string
{
    if ($dryRun) {
        return $forcedDryRun ? 'dry-run (forced)' : 'dry-run';
    }
    if ($force) {
        return 'run-all';
    }
    return 'run';
}

function resolveExpectedHost(): string
{
    $base = defined('BASE_URL') ? (string)BASE_URL : '';
    if ($base !== '' && preg_match('~^https?://~i', $base)) {
        $host = (string)(parse_url($base, PHP_URL_HOST) ?? '');
        return strtolower(trim($host));
    }
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
    return strtolower(trim((string)$host));
}

function sameHost(string $a, string $b): bool
{
    $na = normalizeHost($a);
    $nb = normalizeHost($b);
    if ($na === '' || $nb === '') {
        return false;
    }
    return $na === $nb;
}

function normalizeHost(string $host): string
{
    $host = strtolower(trim($host));
    if ($host === '') {
        return '';
    }
    $parts = explode(':', $host);
    return $parts[0] ?? $host;
}

function buildSafeLinks(): array
{
    $self = $_SERVER['PHP_SELF'] ?? '';
    $dry = $self . '?dry=1';
    $run = $self . '?confirm=YES';
    return ['dry' => $dry, 'run' => $run];
}

function renderPage(string $title, string $body, int $status = 200): void
{
    http_response_code($status);
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>';
    echo '<style>body{font-family:Arial,sans-serif;margin:16px;}table{border-collapse:collapse;width:100%;}th,td{border:1px solid #ccc;padding:8px;text-align:left;}th{background:#f3f3f3;}code{font-family:Consolas,monospace;}pre{white-space:pre-wrap;background:#f8f8f8;border:1px solid #ddd;padding:8px;} .alert{padding:10px;background:#ffe5e5;border:1px solid #ff9b9b;margin:10px 0;} .warn{padding:10px;background:#fff7d6;border:1px solid #f2c94c;margin:10px 0;} .ok{padding:10px;background:#e5ffe5;border:1px solid #98e098;margin:10px 0;} </style>';
    echo '</head><body>'; 
    echo $body;
    echo '</body></html>';
    exit;
}

function resolveLogPath(string $dir, string $filename): array
{
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $path = rtrim($dir, '/') . '/' . $filename;
    $writable = is_dir($dir) && is_writable($dir);
    return [$path, $writable];
}

function logLine(?string $path, string $message): void
{
    if ($path === null) {
        return;
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    @file_put_contents($path, $line, FILE_APPEND);
}

function listMigrationFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob(rtrim($dir, '/') . '/*.sql');
    if (!$files) {
        return [];
    }
    sort($files, SORT_STRING);
    return $files;
}

function ensureSchemaMigrationsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        migration_name VARCHAR(255) NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        executed_at DATETIME NULL,
        success TINYINT(1) NOT NULL DEFAULT 1,
        execution_time_ms INT UNSIGNED NULL,
        notes VARCHAR(255) NULL,
        UNIQUE KEY uq_schema_migrations_filename (filename)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE ?');
        $stmt->execute([$column]);
        $val = (bool)$stmt->fetchColumn();
        $stmt->closeCursor();
        return $val;
    } catch (Throwable $e) {
        return false;
    }
}

function getAppliedAt(PDO $pdo, string $filename): ?string
{
    $stmt = $pdo->prepare('SELECT applied_at FROM schema_migrations WHERE filename = :f LIMIT 1');
    $stmt->execute([':f' => $filename]);
    $val = $stmt->fetchColumn();
    $stmt->closeCursor();
    return $val ? (string)$val : null;
}

function markApplied(PDO $pdo, string $filename, bool $force, ?int $executionTimeMs = null): void
{
    $now = date('Y-m-d H:i:s');
    $fields = ['filename', 'applied_at'];
    $params = [
        ':filename' => $filename,
        ':applied_at' => $now,
    ];

    if (columnExists($pdo, 'schema_migrations', 'migration_name')) {
        $fields[] = 'migration_name';
        $params[':migration_name'] = $filename;
    }
    if (columnExists($pdo, 'schema_migrations', 'executed_at')) {
        $fields[] = 'executed_at';
        $params[':executed_at'] = $now;
    }
    if (columnExists($pdo, 'schema_migrations', 'success')) {
        $fields[] = 'success';
        $params[':success'] = 1;
    }
    if (columnExists($pdo, 'schema_migrations', 'execution_time_ms')) {
        $fields[] = 'execution_time_ms';
        $params[':execution_time_ms'] = $executionTimeMs;
    }
    if (columnExists($pdo, 'schema_migrations', 'notes')) {
        $fields[] = 'notes';
        $params[':notes'] = null;
    }

    $placeholders = array_map(static fn (string $field): string => ':' . $field, $fields);
    $updates = ['applied_at = VALUES(applied_at)'];
    if (in_array('migration_name', $fields, true)) {
        $updates[] = 'migration_name = COALESCE(VALUES(migration_name), migration_name)';
    }
    if (in_array('executed_at', $fields, true)) {
        $updates[] = 'executed_at = VALUES(executed_at)';
    }
    if (in_array('success', $fields, true)) {
        $updates[] = 'success = VALUES(success)';
    }
    if (in_array('execution_time_ms', $fields, true)) {
        $updates[] = 'execution_time_ms = VALUES(execution_time_ms)';
    }
    if (in_array('notes', $fields, true)) {
        $updates[] = 'notes = VALUES(notes)';
    }

    $stmt = $pdo->prepare(
        'INSERT INTO schema_migrations (' . implode(', ', $fields) . ')
         VALUES (' . implode(', ', $placeholders) . ')
         ON DUPLICATE KEY UPDATE ' . implode(', ', $updates)
    );
    $stmt->execute($params);
}

function ensureCompaniesMigrationFirst(array $files, bool $hasCompanies): array
{
    $notice = null;
    $error = null;
    if ($hasCompanies) {
        return ['files' => $files, 'notice' => null, 'error' => null];
    }

    $byName = [];
    $byContent = [];
    $exact = null;
    foreach ($files as $file) {
        $base = basename($file);
        if ($base === '2026_01_27_00_create_companies.sql') {
            $exact = $file;
        }
        if (stripos($base, 'create_companies') !== false) {
            $byName[] = $file;
        }
        $content = @file_get_contents($file);
        if ($content !== false && preg_match('/CREATE\s+TABLE\s+(IF\s+NOT\s+EXISTS\s+)?`?companies`?/i', $content)) {
            $byContent[] = $file;
        }
    }

    $bootstrap = $exact ?? $byName[0] ?? $byContent[0] ?? null;
    if ($bootstrap === null) {
        $error = 'Companies table missing and no bootstrap migration found.';
        return ['files' => $files, 'notice' => null, 'error' => $error];
    }

    $files = array_values(array_filter($files, static fn($f) => $f !== $bootstrap));
    array_unshift($files, $bootstrap);
    $notice = 'Preflight: companies table missing, forcing first migration: ' . basename($bootstrap);
    return ['files' => $files, 'notice' => $notice, 'error' => null];
}

function splitSqlStatements(string $sql): array
{
    $sql = ltrim($sql, "\xEF\xBB\xBF");
    $len = strlen($sql);
    $statements = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $inLineComment = false;
    $inBlockComment = false;

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $next = $i + 1 < $len ? $sql[$i + 1] : '';

        if ($inLineComment) {
            if ($ch === "\n") {
                $inLineComment = false;
                $buffer .= "\n";
            }
            continue;
        }
        if ($inBlockComment) {
            if ($ch === '*' && $next === '/') {
                $inBlockComment = false;
                $i++;
                $buffer .= ' ';
            }
            continue;
        }

        if (!$inSingle && !$inDouble && !$inBacktick) {
            if ($ch === '-' && $next === '-') {
                $prev = $i > 0 ? $sql[$i - 1] : '';
                $after = $i + 2 < $len ? $sql[$i + 2] : '';
                if (($prev === '' || ctype_space($prev)) && ($after === '' || ctype_space($after))) {
                    $inLineComment = true;
                    $i++;
                    continue;
                }
            }
            if ($ch === '#') {
                $inLineComment = true;
                continue;
            }
            if ($ch === '/' && $next === '*') {
                $inBlockComment = true;
                $i++;
                continue;
            }
        }

        if ($inSingle) {
            if ($ch === '\\') {
                $buffer .= $ch;
                if ($next !== '') {
                    $buffer .= $next;
                    $i++;
                }
                continue;
            }
            if ($ch === "'") {
                if ($next === "'") {
                    $buffer .= "''";
                    $i++;
                    continue;
                }
                $inSingle = false;
            }
            $buffer .= $ch;
            continue;
        }
        if ($inDouble) {
            if ($ch === '\\') {
                $buffer .= $ch;
                if ($next !== '') {
                    $buffer .= $next;
                    $i++;
                }
                continue;
            }
            if ($ch === '"') {
                if ($next === '"') {
                    $buffer .= '""';
                    $i++;
                    continue;
                }
                $inDouble = false;
            }
            $buffer .= $ch;
            continue;
        }
        if ($inBacktick) {
            if ($ch === '`') {
                $inBacktick = false;
            }
            $buffer .= $ch;
            continue;
        }

        if ($ch === "'") {
            $inSingle = true;
            $buffer .= $ch;
            continue;
        }
        if ($ch === '"') {
            $inDouble = true;
            $buffer .= $ch;
            continue;
        }
        if ($ch === '`') {
            $inBacktick = true;
            $buffer .= $ch;
            continue;
        }

        if ($ch === ';') {
            $stmt = trim($buffer);
            if ($stmt !== '') {
                $statements[] = $stmt;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $ch;
    }

    $stmt = trim($buffer);
    if ($stmt !== '') {
        $statements[] = $stmt;
    }

    return $statements;
}

function executeStatements(PDO $pdo, array $statements, string $filename): array
{
    if ($statements === []) {
        return ['ok' => true];
    }

    $lastStatement = null;
    $started = false;

    try {
        $pdo->beginTransaction();
        $started = true;

        foreach ($statements as $statement) {
            $lastStatement = $statement;
            $stmt = null;
            try {
                $stmt = $pdo->prepare($statement);
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

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return [
            'ok' => false,
            'error' => $e->getMessage(),
            'statement' => $lastStatement,
        ];
    }
}

function buildRow(string $filename, string $status, float $start, string $message): array
{
    $elapsedMs = (int)round((microtime(true) - $start) * 1000);
    return [
        'filename' => $filename,
        'status' => $status,
        'time' => $elapsedMs,
        'message' => $message,
    ];
}

function runSanityChecks(?PDO $pdo): array
{
    if ($pdo === null) {
        return [];
    }
    $res = [
        'companies_table' => tableExists($pdo, 'companies'),
        'gus_refresh_queue' => tableExists($pdo, 'gus_refresh_queue'),
        'klienci_company_id' => columnExists($pdo, 'klienci', 'company_id'),
    ];
    return $res;
}

function buildHtmlBody(array $data): string
{
    $rows = $data['rows'] ?? [];
    $summary = $data['summary'] ?? [];
    $dryRun = !empty($data['dryRun']);
    $forcedDryRun = !empty($data['forcedDryRun']);
    $force = !empty($data['force']);
    $logPath = $data['logPath'] ?? '';
    $logWarnings = $data['logWarnings'] ?? [];
    $bootstrapNotice = $data['bootstrapNotice'] ?? null;
    $lastError = $data['lastError'] ?? null;
    $lastStatement = $data['lastStatement'] ?? null;
    $sanity = $data['sanity'] ?? [];
    $safeMode = !empty($data['safeMode']);
    $safeLinks = $data['safeLinks'] ?? [];
    $tokenConfigured = !empty($data['tokenConfigured']);
    $confirmYes = !empty($data['confirmYes']);
    $actionName = (string)($data['actionName'] ?? '');

    $adminLink = defined('BASE_URL') ? BASE_URL . '/admin/index.php' : '/crm/public/admin/index.php';
    $html = '<p><a href="' . htmlspecialchars($adminLink, ENT_QUOTES) . '">&larr; Panel narzędzi technicznych</a></p>';
    $html .= '<h1>DB migration runner</h1>';
    if ($safeMode) {
        $html .= '<div class="warn"><strong>SAFE MODE (no MIGRATOR_TOKEN configured)</strong></div>';
        $html .= '<div class="warn">Run is allowed only for admin session + confirm=YES.</div>';
        if (!empty($safeLinks['dry']) || !empty($safeLinks['run'])) {
            $html .= '<p>';
            if (!empty($safeLinks['dry'])) {
                $html .= '<a href="' . htmlspecialchars($safeLinks['dry'], ENT_QUOTES) . '">DRY-RUN</a>';
            }
            if (!empty($safeLinks['run'])) {
                $html .= ' | <a href="' . htmlspecialchars($safeLinks['run'], ENT_QUOTES) . '">RUN (confirm=YES)</a>';
            }
            $html .= '</p>';
        }
    }
    if ($forcedDryRun) {
        $html .= '<div class="warn">confirm=YES missing, switched to DRY-RUN.</div>';
    }
    if ($force) {
        $html .= '<div class="warn"><strong>FORCE mode enabled:</strong> schema_migrations is ignored and all migrations will be re-applied.</div>';
    }
    if ($dryRun) {
        $html .= '<div class="warn"><strong>DRY-RUN:</strong> no SQL executed; showing what would run.</div>';
    }
    if ($bootstrapNotice) {
        $html .= '<div class="warn">' . htmlspecialchars($bootstrapNotice, ENT_QUOTES) . '</div>';
    }
    foreach ($logWarnings as $warn) {
        $html .= '<div class="warn">' . $warn . '</div>';
    }
    if ($lastError) {
        $html .= '<div class="alert"><strong>Error:</strong> ' . htmlspecialchars((string)$lastError, ENT_QUOTES) . '</div>';
    } elseif (!$dryRun) {
        $html .= '<div class="ok">Migrations completed.</div>';
    }

    $html .= '<h2>Summary</h2>';
    $html .= '<ul>';
    $html .= '<li>Token configured: <strong>' . ($tokenConfigured ? 'YES' : 'NO') . '</strong></li>';
    $html .= '<li>SAFE MODE: <strong>' . ($safeMode ? 'YES' : 'NO') . '</strong></li>';
    $html .= '<li>confirm=YES: <strong>' . ($confirmYes ? 'YES' : 'NO') . '</strong></li>';
    $html .= '<li>Action: <strong>' . htmlspecialchars($actionName !== '' ? $actionName : 'n/a', ENT_QUOTES) . '</strong></li>';
    $html .= '</ul>';
    $html .= '<h2>Migration summary</h2>';
    $html .= '<ul>';
    $html .= '<li>Total: <strong>' . (int)($summary['total'] ?? 0) . '</strong></li>';
    $html .= '<li>Applied: <strong>' . (int)($summary['applied'] ?? 0) . '</strong></li>';
    $html .= '<li>Skipped: <strong>' . (int)($summary['skipped'] ?? 0) . '</strong></li>';
    $html .= '<li>Dry-run: <strong>' . (int)($summary['dry'] ?? 0) . '</strong></li>';
    $html .= '<li>Failed: <strong>' . (int)($summary['failed'] ?? 0) . '</strong></li>';
    $html .= '</ul>';

    $html .= '<h2>Migration log</h2>';
    $html .= '<table><thead><tr><th>Migration</th><th>Status</th><th>Time (ms)</th><th>Message</th></tr></thead><tbody>';
    if ($rows === []) {
        $html .= '<tr><td colspan="4">No migrations found.</td></tr>';
    } else {
        foreach ($rows as $row) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($row['filename'], ENT_QUOTES) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['status'], ENT_QUOTES) . '</td>';
            $html .= '<td>' . htmlspecialchars((string)$row['time'], ENT_QUOTES) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['message'], ENT_QUOTES) . '</td>';
            $html .= '</tr>';
        }
    }
    $html .= '</tbody></table>';

    if ($lastStatement) {
        $html .= '<h3>Last statement</h3>';
        $html .= '<pre>' . htmlspecialchars((string)$lastStatement, ENT_QUOTES) . '</pre>';
    }

    $html .= '<h2>Sanity check</h2>';
    $html .= '<table><thead><tr><th>Check</th><th>Result</th></tr></thead><tbody>';
    $html .= '<tr><td>SHOW TABLES LIKE \'companies\'</td><td>' . (!empty($sanity['companies_table']) ? 'PRESENT' : 'MISSING') . '</td></tr>';
    $html .= '<tr><td>SHOW TABLES LIKE \'gus_refresh_queue\'</td><td>' . (!empty($sanity['gus_refresh_queue']) ? 'PRESENT' : 'MISSING') . '</td></tr>';
    $html .= '<tr><td>SHOW COLUMNS FROM klienci LIKE \'company_id\'</td><td>' . (!empty($sanity['klienci_company_id']) ? 'PRESENT' : 'MISSING') . '</td></tr>';
    $html .= '</tbody></table>';

    $html .= '<p>Log file: <code>' . htmlspecialchars($logPath, ENT_QUOTES) . '</code></p>';

    return $html;
}
