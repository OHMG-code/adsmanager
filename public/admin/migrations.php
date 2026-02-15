<?php

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

$root = realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../../');
$root = rtrim($root, '/');
$panicLog = $root . '/storage/logs/migrator_fatal.log';
$panicLogDir = dirname($panicLog);

$writePanicLog = static function (string $message) use ($panicLog, $panicLogDir): void {
    if (!is_dir($panicLogDir)) {
        @mkdir($panicLogDir, 0775, true);
    }
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    @file_put_contents($panicLog, $line . PHP_EOL, FILE_APPEND);
};

$prevErrorHandler = null;
$prevErrorHandler = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$prevErrorHandler, $writePanicLog): bool {
    $uri = $_SERVER['REQUEST_URI'] ?? 'n/a';
    $writePanicLog(sprintf('PHP error (%s) %s in %s:%d [URI: %s]', $severity, $message, $file, $line, $uri));
    if (is_callable($prevErrorHandler)) {
        return (bool)$prevErrorHandler($severity, $message, $file, $line);
    }
    return false;
});

register_shutdown_function(static function () use ($writePanicLog): void {
    $err = error_get_last();
    if (!$err) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_RECOVERABLE_ERROR];
    if (!in_array($err['type'], $fatalTypes, true)) {
        return;
    }
    $uri = $_SERVER['REQUEST_URI'] ?? 'n/a';
    $writePanicLog(sprintf(
        'PHP fatal (%s) %s in %s:%d [URI: %s]',
        $err['type'],
        $err['message'] ?? 'unknown',
        $err['file'] ?? 'unknown',
        $err['line'] ?? 0,
        $uri
    ));
});

$prevExceptionHandler = null;
$prevExceptionHandler = set_exception_handler(static function (Throwable $e) use (&$prevExceptionHandler, $writePanicLog): void {
    $uri = $_SERVER["REQUEST_URI"] ?? "n/a";
    $writePanicLog(sprintf("Uncaught exception %s: %s in %s:%d [URI: %s]",
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        $uri
    ));
    if (is_callable($prevExceptionHandler)) {
        $prevExceptionHandler($e);
        return;
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>Migrations error</title></head><body>';
    echo '<h1>Migrations error</h1>';
    echo '<p>Uncaught exception occurred. See migrator_fatal.log for details.</p>';
    echo '</body></html>';
    exit;
});

$minimalMode = isset($_GET['minimal']) && $_GET['minimal'] !== '0';
if ($minimalMode) {
    header('Content-Type: text/html; charset=utf-8');
    $migrationsDir = $root . '/sql/migrations';
    $files = is_dir($migrationsDir) ? glob($migrationsDir . '/*.sql') : [];
    if (!is_array($files)) {
        $files = [];
    }
    sort($files, SORT_STRING);
    $fatalTail = [];
    if (is_file($panicLog) && is_readable($panicLog)) {
        $fatalTail = @file($panicLog, FILE_IGNORE_NEW_LINES);
        if (!is_array($fatalTail)) {
            $fatalTail = [];
        }
        if (count($fatalTail) > 20) {
            $fatalTail = array_slice($fatalTail, -20);
        }
    }

    echo '<!doctype html><html><head><meta charset="utf-8"><title>Migrations minimal</title>';
    echo '<style>body{font-family:Arial, sans-serif;margin:16px;}pre{background:#f6f6f6;padding:8px;}</style>';
    echo '</head><body>';
    echo '<h1>DB migrations (minimal mode)</h1>';
    echo '<p>PHP: <strong>' . htmlspecialchars(PHP_VERSION, ENT_QUOTES) . '</strong></p>';
    echo '<p>File: <code>' . htmlspecialchars(__FILE__, ENT_QUOTES) . '</code></p>';
    echo '<p>Detected migrations: <strong>' . count($files) . '</strong></p>';
    if ($files) {
        echo '<ul>';
        foreach ($files as $file) {
            echo '<li>' . htmlspecialchars(basename($file), ENT_QUOTES) . '</li>';
        }
        echo '</ul>';
    }
    if ($fatalTail) {
        echo '<h2>Last fatal log tail</h2>';
        echo '<pre><code>' . htmlspecialchars(implode("\n", $fatalTail), ENT_QUOTES) . '</code></pre>';
    }
    echo '</body></html>';
    exit;
}

$requireFile = static function (string $path, string $label) use ($writePanicLog): void {
    if (!is_file($path)) {
        $msg = sprintf('Missing required file: %s (%s)', $label, $path);
        $writePanicLog($msg);
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo $msg;
        exit;
    }
    require_once $path;
};

$loadDotEnv = static function (string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return [];
    }
    $result = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strncmp($line, '#', 1) === 0) {
            continue;
        }
        if (strncmp($line, 'export ', 7) === 0) {
            $line = trim(substr($line, 7));
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        $value = trim($value, "\"'");
        if ($key !== '') {
            $result[$key] = $value;
        }
    }
    return $result;
};

$dbLocalPath = $root . '/config/db.local.php';
$dbLocalConfig = [];
$dbLocalReadable = false;
if (is_file($dbLocalPath) && is_readable($dbLocalPath)) {
    $loaded = include $dbLocalPath;
    if (is_array($loaded)) {
        $dbLocalConfig = $loaded;
        $dbLocalReadable = true;
    }
}

$dotenv = $loadDotEnv($root . '/.env');
$hydrateEnv = static function (string $key) use ($dotenv, $dbLocalConfig): void {
    $val = getenv($key);
    if ($val !== false && $val !== null) {
        return;
    }
    if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
        putenv($key . '=' . $_ENV[$key]);
        return;
    }
    if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
        putenv($key . '=' . $_SERVER[$key]);
        return;
    }
    if (array_key_exists($key, $dbLocalConfig) && $dbLocalConfig[$key] !== '') {
        putenv($key . '=' . $dbLocalConfig[$key]);
        return;
    }
    if (array_key_exists($key, $dotenv) && $dotenv[$key] !== '') {
        putenv($key . '=' . $dotenv[$key]);
    }
};

foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'DB_CHARSET', 'MIGRATOR_TOKEN'] as $envKey) {
    $hydrateEnv($envKey);
}

$requireFile($root . '/config/config.php', 'config/config.php');
$requireFile($root . '/public/includes/auth.php', 'public/includes/auth.php');
$requireFile($root . '/services/MigrationConsole.php', 'services/MigrationConsole.php');
$requireFile($root . '/services/migration_log_path.php', 'services/migration_log_path.php');

$pdoReady = isset($pdo) && $pdo instanceof PDO;
$pdoBootstrapError = null;
$pdoMissingKeys = [];
$pdoMissingSecret = false;
$pdoSource = 'config.php';

$configHostRaw = $host ?? null;
$configDbRaw = $db ?? null;
$configUserRaw = $user ?? null;
$configPassRaw = $pass ?? null;
$configCharsetRaw = $charset ?? null;

$configHost = is_string($configHostRaw) ? $configHostRaw : '';
$configDb = is_string($configDbRaw) ? $configDbRaw : '';
$configUser = is_string($configUserRaw) ? $configUserRaw : '';
$configPass = is_string($configPassRaw) ? $configPassRaw : '';
$configCharset = is_string($configCharsetRaw) ? $configCharsetRaw : '';
$configArray = isset($dbConfig) && is_array($dbConfig) ? $dbConfig : [];

$envSources = [];
$envMeta = [];
$readEnvValue = static function (string $key) use (&$envSources, &$envMeta, $dotenv, $dbLocalConfig): string {
    $val = getenv($key);
    if ($val !== false && $val !== null) {
        $envSources[$key] = 'getenv';
        $envMeta[$key] = ['type' => gettype($val), 'len' => strlen((string)$val)];
        return (string)$val;
    }
    if (isset($_ENV[$key])) {
        $envSources[$key] = '_ENV';
        $envMeta[$key] = ['type' => gettype($_ENV[$key]), 'len' => strlen((string)$_ENV[$key])];
        return (string)$_ENV[$key];
    }
    if (isset($_SERVER[$key])) {
        $envSources[$key] = '_SERVER';
        $envMeta[$key] = ['type' => gettype($_SERVER[$key]), 'len' => strlen((string)$_SERVER[$key])];
        return (string)$_SERVER[$key];
    }
    if (array_key_exists($key, $dbLocalConfig)) {
        $envSources[$key] = 'db.local.php';
        $envMeta[$key] = ['type' => gettype($dbLocalConfig[$key]), 'len' => strlen((string)$dbLocalConfig[$key])];
        return (string)$dbLocalConfig[$key];
    }
    if (array_key_exists($key, $dotenv)) {
        $envSources[$key] = '.env';
        $envMeta[$key] = ['type' => gettype($dotenv[$key]), 'len' => strlen((string)$dotenv[$key])];
        return (string)$dotenv[$key];
    }
    $envSources[$key] = 'missing';
    $envMeta[$key] = ['type' => 'missing', 'len' => 0];
    return '';
};

$configArrayHostRaw = $configArray['host'] ?? null;
$configArrayDbRaw = $configArray['name'] ?? null;
$configArrayUserRaw = $configArray['user'] ?? null;
$configArrayPassRaw = $configArray['pass'] ?? null;
$configArrayCharsetRaw = $configArray['charset'] ?? null;

$configArrayHost = is_string($configArrayHostRaw) ? $configArrayHostRaw : '';
$configArrayDb = is_string($configArrayDbRaw) ? $configArrayDbRaw : '';
$configArrayUser = is_string($configArrayUserRaw) ? $configArrayUserRaw : '';
$configArrayPass = is_string($configArrayPassRaw) ? $configArrayPassRaw : '';
$configArrayCharset = is_string($configArrayCharsetRaw) ? $configArrayCharsetRaw : '';

$dbLocalHostRaw = $dbLocalConfig['host'] ?? null;
$dbLocalDbRaw = $dbLocalConfig['name'] ?? null;
$dbLocalUserRaw = $dbLocalConfig['user'] ?? null;
$dbLocalPassRaw = $dbLocalConfig['pass'] ?? null;
$dbLocalCharsetRaw = $dbLocalConfig['charset'] ?? null;

$dbLocalHost = is_string($dbLocalHostRaw) ? $dbLocalHostRaw : '';
$dbLocalDb = is_string($dbLocalDbRaw) ? $dbLocalDbRaw : '';
$dbLocalUser = is_string($dbLocalUserRaw) ? $dbLocalUserRaw : '';
$dbLocalPass = is_string($dbLocalPassRaw) ? $dbLocalPassRaw : '';
$dbLocalCharset = is_string($dbLocalCharsetRaw) ? $dbLocalCharsetRaw : '';

$dbMeta = [];

if ($configHost !== '') {
    $resolvedHost = $configHost;
    $dbMeta['DB_HOST'] = ['source' => 'config.php', 'type' => gettype($configHostRaw), 'len' => strlen((string)$configHostRaw)];
} elseif ($configArrayHost !== '') {
    $resolvedHost = $configArrayHost;
    $dbMeta['DB_HOST'] = ['source' => 'db.local.php', 'type' => gettype($configArrayHostRaw), 'len' => strlen((string)$configArrayHostRaw)];
} elseif ($dbLocalHost !== '') {
    $resolvedHost = $dbLocalHost;
    $dbMeta['DB_HOST'] = ['source' => 'db.local.php', 'type' => gettype($dbLocalHostRaw), 'len' => strlen((string)$dbLocalHostRaw)];
} else {
    $resolvedHost = $readEnvValue('DB_HOST');
    $dbMeta['DB_HOST'] = ['source' => $envSources['DB_HOST'] ?? 'missing'] + ($envMeta['DB_HOST'] ?? ['type' => 'missing', 'len' => 0]);
}

if ($configDb !== '') {
    $resolvedDb = $configDb;
    $dbMeta['DB_NAME'] = ['source' => 'config.php', 'type' => gettype($configDbRaw), 'len' => strlen((string)$configDbRaw)];
} elseif ($configArrayDb !== '') {
    $resolvedDb = $configArrayDb;
    $dbMeta['DB_NAME'] = ['source' => 'db.local.php', 'type' => gettype($configArrayDbRaw), 'len' => strlen((string)$configArrayDbRaw)];
} elseif ($dbLocalDb !== '') {
    $resolvedDb = $dbLocalDb;
    $dbMeta['DB_NAME'] = ['source' => 'db.local.php', 'type' => gettype($dbLocalDbRaw), 'len' => strlen((string)$dbLocalDbRaw)];
} else {
    $resolvedDb = $readEnvValue('DB_NAME');
    $dbMeta['DB_NAME'] = ['source' => $envSources['DB_NAME'] ?? 'missing'] + ($envMeta['DB_NAME'] ?? ['type' => 'missing', 'len' => 0]);
}

if ($configUser !== '') {
    $resolvedUser = $configUser;
    $dbMeta['DB_USER'] = ['source' => 'config.php', 'type' => gettype($configUserRaw), 'len' => strlen((string)$configUserRaw)];
} elseif ($configArrayUser !== '') {
    $resolvedUser = $configArrayUser;
    $dbMeta['DB_USER'] = ['source' => 'db.local.php', 'type' => gettype($configArrayUserRaw), 'len' => strlen((string)$configArrayUserRaw)];
} elseif ($dbLocalUser !== '') {
    $resolvedUser = $dbLocalUser;
    $dbMeta['DB_USER'] = ['source' => 'db.local.php', 'type' => gettype($dbLocalUserRaw), 'len' => strlen((string)$dbLocalUserRaw)];
} else {
    $resolvedUser = $readEnvValue('DB_USER');
    $dbMeta['DB_USER'] = ['source' => $envSources['DB_USER'] ?? 'missing'] + ($envMeta['DB_USER'] ?? ['type' => 'missing', 'len' => 0]);
}

if ($configPass !== '') {
    $resolvedPass = $configPass;
    $dbMeta['DB_PASS'] = ['source' => 'config.php', 'type' => gettype($configPassRaw), 'len' => strlen((string)$configPassRaw)];
} elseif ($configArrayPass !== '') {
    $resolvedPass = $configArrayPass;
    $dbMeta['DB_PASS'] = ['source' => 'db.local.php', 'type' => gettype($configArrayPassRaw), 'len' => strlen((string)$configArrayPassRaw)];
} elseif ($dbLocalPass !== '') {
    $resolvedPass = $dbLocalPass;
    $dbMeta['DB_PASS'] = ['source' => 'db.local.php', 'type' => gettype($dbLocalPassRaw), 'len' => strlen((string)$dbLocalPassRaw)];
} else {
    $resolvedPass = $readEnvValue('DB_PASS');
    $dbMeta['DB_PASS'] = ['source' => $envSources['DB_PASS'] ?? 'missing'] + ($envMeta['DB_PASS'] ?? ['type' => 'missing', 'len' => 0]);
}

if ($configCharset !== '') {
    $resolvedCharset = $configCharset;
    $dbMeta['DB_CHARSET'] = ['source' => 'config.php', 'type' => gettype($configCharsetRaw), 'len' => strlen((string)$configCharsetRaw)];
} elseif ($configArrayCharset !== '') {
    $resolvedCharset = $configArrayCharset;
    $dbMeta['DB_CHARSET'] = ['source' => 'db.local.php', 'type' => gettype($configArrayCharsetRaw), 'len' => strlen((string)$configArrayCharsetRaw)];
} elseif ($dbLocalCharset !== '') {
    $resolvedCharset = $dbLocalCharset;
    $dbMeta['DB_CHARSET'] = ['source' => 'db.local.php', 'type' => gettype($dbLocalCharsetRaw), 'len' => strlen((string)$dbLocalCharsetRaw)];
} else {
    $resolvedCharset = $readEnvValue('DB_CHARSET');
    $dbMeta['DB_CHARSET'] = ['source' => $envSources['DB_CHARSET'] ?? 'missing'] + ($envMeta['DB_CHARSET'] ?? ['type' => 'missing', 'len' => 0]);
}

$resolvedHost = $resolvedHost === false ? '' : (string)$resolvedHost;
$resolvedDb = $resolvedDb === false ? '' : (string)$resolvedDb;
$resolvedUser = $resolvedUser === false ? '' : (string)$resolvedUser;
$resolvedPass = $resolvedPass === false ? '' : (string)$resolvedPass;
$resolvedCharset = $resolvedCharset === false || $resolvedCharset === '' ? 'utf8mb4' : (string)$resolvedCharset;

if (!$pdoReady) {
    $missing = [];
    if ($resolvedHost === '') {
        $missing[] = 'DB_HOST';
    }
    if ($resolvedDb === '') {
        $missing[] = 'DB_NAME';
    }
    if ($resolvedUser === '') {
        $missing[] = 'DB_USER';
    }
    if ($missing !== []) {
        $pdoMissingKeys = $missing;
        $writePanicLog('DB config missing: ' . implode(', ', $missing));
    }
    if ($resolvedPass === '') {
        $pdoMissingSecret = true;
        $writePanicLog('DB config missing: DB_PASS');
    }

    if ($missing === [] && $resolvedPass !== '') {
        try {
            $dsn = "mysql:host={$resolvedHost};dbname={$resolvedDb};charset={$resolvedCharset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ];
            if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
                $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
            }
            $pdo = new PDO($dsn, $resolvedUser, $resolvedPass, $options);
            $pdoReady = true;
        } catch (Throwable $e) {
            $pdoBootstrapError = $e->getMessage();
            $writePanicLog('PDO bootstrap failed: ' . $pdoBootstrapError);
        }
    }
}

if ($pdoReady) {
    try {
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    } catch (Throwable $e) {
        $writePanicLog('Failed to enable buffered queries: ' . $e->getMessage());
    }
}



if (!function_exists('isMigratorTokenValid')) {
    function isMigratorTokenValid(string $value, string $expected): bool
    {
        if ($expected === '') {
            return false;
        }
        return hash_equals($expected, $value);
    }
}

if (!function_exists('renderTokenForm')) {
    function renderTokenForm(string $message = ''): void
    {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Migrations token</title></head><body>';
        if ($message !== '') {
            echo '<p>' . htmlspecialchars($message, ENT_QUOTES) . '</p>';
        }
        echo '<h1>Provide migrator token</h1>';
        echo '<form method="get" action="">';
        echo '<label>Token: <input type="text" name="token" autocomplete="one-time-code"></label> ';
        echo '<button type="submit">Enter</button>';
        echo '</form>';
        echo '</body></html>';
        exit;
    }
}

if (!function_exists('htmlEscape')) {
    function htmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}

if (!function_exists('resolveMigratorToken')) {
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
        $envToken = readDotEnvToken(__DIR__ . '/../..');
        if ($envToken !== '') {
            return $envToken;
        }
        return '';
    }
}

if (!function_exists('readDotEnvToken')) {
    function readDotEnvToken(string $root): string
    {
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
            if ($line === '' || strncmp($line, '#', 1) === 0) {
                continue;
            }
            if (strncmp($line, 'export ', 7) === 0) {
                $line = trim(substr($line, 7));
            }
            if (strpos($line, '=') === false) {
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
}

if (!function_exists('buildMigratorUrl')) {
    function buildMigratorUrl(string $token, array $params = []): string
    {
        $base = BASE_URL . '/admin/migrate_all.php';
        if ($token !== '') {
            $params = ['token' => $token] + $params;
        }
        if (!$params) {
            return $base;
        }
        return $base . '?' . http_build_query($params);
    }
}

if (!function_exists('tailLog')) {
    function tailLog(string $path, int $maxLines): array
    {
        if (!file_exists($path)) {
            return [];
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($lines)) {
            return [];
        }
        if (count($lines) <= $maxLines) {
            return $lines;
        }
        return array_slice($lines, -$maxLines);
    }
}

if (!function_exists('storageDiagnostics')) {
    function storageDiagnostics(string $dir): array
    {
        return [
            'exists' => is_dir($dir),
            'writable' => is_dir($dir) && is_writable($dir),
        ];
    }
}

$formatDbMeta = static function (string $key, array $meta, string $value): string {
    $status = $value === '' ? 'missing' : 'set';
    $type = $meta['type'] ?? 'unknown';
    $len = (int)($meta['len'] ?? 0);
    $source = $meta['source'] ?? 'unknown';
    return sprintf('%s (type=%s, len=%d, source=%s)', $status, $type, $len, $source);
};

$maskValue = static function (string $value): string {
    if ($value === '') {
        return 'missing';
    }
    return 'set (len=' . strlen($value) . ')';
};

requireCapability('manage_system');

$expectedToken = resolveMigratorToken();
$tokenConfigured = $expectedToken !== '';
$safeMode = !$tokenConfigured;

$token = trim((string)($_GET['token'] ?? ''));
if ($tokenConfigured) {
    if (!isMigratorTokenValid($token, $expectedToken)) {
        renderTokenForm();
    }
} else {
    $token = '';
}

if (!$pdoReady) {
    $writePanicLog('PDO not initialized after config include; migrations UI cannot connect to DB.');
    $fatalLogLines = is_file($panicLog) ? tailLog($panicLog, 20) : [];
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>SQL migrations</title></head><body>';
    echo '<h1>SQL migrations</h1>';
    echo '<div class="alert">Brak połączenia z bazą (PDO nie jest zainicjalizowane). Sprawdź konfigurację DB.</div>';
    if ($pdoMissingKeys) {
        echo '<p>Brakujące ustawienia: <strong>' . htmlEscape(implode(', ', $pdoMissingKeys)) . '</strong></p>';
    }
    if (!empty($pdoMissingSecret)) {
        echo '<p>Brakujące ustawienia: <strong>DB_PASS</strong></p>';
    }
    if ($pdoBootstrapError) {
        echo '<p>Błąd połączenia PDO: <strong>' . htmlEscape($pdoBootstrapError) . '</strong></p>';
    }
    echo '<p>Źródło konfiguracji: <strong>' . htmlEscape($pdoSource) . '</strong></p>';
    echo '<h2>DB diagnostics</h2>';
    echo '<ul>';
    echo '<li>DB_HOST: <strong>' . htmlEscape($formatDbMeta('DB_HOST', $dbMeta['DB_HOST'] ?? [], $resolvedHost)) . '</strong></li>';
    echo '<li>DB_NAME: <strong>' . htmlEscape($formatDbMeta('DB_NAME', $dbMeta['DB_NAME'] ?? [], $resolvedDb)) . '</strong></li>';
    echo '<li>DB_USER: <strong>' . htmlEscape($formatDbMeta('DB_USER', $dbMeta['DB_USER'] ?? [], $resolvedUser)) . '</strong></li>';
    echo '<li>DB_PASS: <strong>' . htmlEscape($formatDbMeta('DB_PASS', $dbMeta['DB_PASS'] ?? [], $resolvedPass)) . '</strong></li>';
    echo '<li>DB_CHARSET: <strong>' . htmlEscape($formatDbMeta('DB_CHARSET', $dbMeta['DB_CHARSET'] ?? [], $resolvedCharset)) . '</strong></li>';
    echo '</ul>';
    if ($fatalLogLines) {
        echo '<h2>Last fatal log tail</h2>';
        echo '<pre><code>' . htmlEscape(implode("\n", $fatalLogLines)) . '</code></pre>';
    }
    echo '</body></html>';
    exit;
}

$logInfo = resolveMigrationLogPath();
$currentUser = currentUser();
$actor = $currentUser['login'] ?? 'web';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$migrationsDir = realpath($root . '/sql/migrations') ?: ($root . '/sql/migrations');
$service = new MigrationConsole($pdo, $migrationsDir, $logInfo['path'], $actor, $ip);
$migrationFiles = is_dir($migrationsDir) ? glob($migrationsDir . '/*.sql') : [];
$migrationCount = is_array($migrationFiles) ? count($migrationFiles) : 0;
$migrationLogLine = '[' . date('Y-m-d H:i:s') . '] ' . 'MIGRATIONS_DIR ' . $migrationsDir . ' files=' . $migrationCount;
@file_put_contents($logInfo['path'], $migrationLogLine . PHP_EOL, FILE_APPEND);
$migrations = $service->listMigrations();
$connectionInfo = $service->getConnectionInfo();
if (($connectionInfo['host'] ?? 'unknown') === 'unknown' && $resolvedHost !== '') {
    $connectionInfo['host'] = $resolvedHost;
}
if (($connectionInfo['database'] ?? 'unknown') === 'unknown' && $resolvedDb !== '') {
    $connectionInfo['database'] = $resolvedDb;
}
$serverVersion = null;
$serverVersionError = null;
try {
    $stmt = $pdo->query('SELECT VERSION()');
    if ($stmt) {
        $serverVersion = $stmt->fetchColumn();
        $stmt->closeCursor();
    }
} catch (Throwable $e) {
    $serverVersionError = $e->getMessage();
}
$driverName = null;
$driverError = null;
try {
    $driverName = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
} catch (Throwable $e) {
    $driverError = $e->getMessage();
}
$select1Ok = null;
$select1Error = null;
try {
    $stmt = $pdo->query('SELECT 1');
    if ($stmt) {
        $stmt->fetchAll();
        $stmt->closeCursor();
    }
    $select1Ok = true;
} catch (Throwable $e) {
    $select1Ok = false;
    $select1Error = $e->getMessage();
}
$companiesOk = $service->hasCompaniesTable();
$duplicates = $service->hasDuplicateCompaniesNip();
$csrfToken = getCsrfToken();
$logLines = tailLog($logInfo['path'], 200);
$fatalLogLines = is_file($panicLog) ? tailLog($panicLog, 20) : [];
$storageLogsDir = $root . '/storage/logs';
$fetchCompaniesSchemaDiagnostics = static function (PDO $pdo, bool $hasCompanies): array {
    $diag = [
        'has_table' => $hasCompanies,
        'columns' => [],
        'columns_error' => null,
        'create_sql' => null,
        'create_error' => null,
        'sanity_ok' => null,
        'sanity_error' => null,
    ];

    if (!$hasCompanies) {
        return $diag;
    }

    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM companies');
        $diag['columns'] = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
        if ($stmt) {
            $stmt->closeCursor();
        }
    } catch (Throwable $e) {
        $diag['columns_error'] = $e->getMessage();
    }

    try {
        $stmt = $pdo->query('SHOW CREATE TABLE companies');
        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        if ($stmt) {
            $stmt->fetchAll();
            $stmt->closeCursor();
        }
        $diag['create_sql'] = $row['Create Table'] ?? null;
    } catch (Throwable $e) {
        $diag['create_error'] = $e->getMessage();
    }

    try {
        $stmt = $pdo->query('SELECT c.name_full FROM companies c LIMIT 1');
        if ($stmt) {
            $stmt->fetchAll();
            $stmt->closeCursor();
        }
        $diag['sanity_ok'] = true;
    } catch (Throwable $e) {
        $diag['sanity_ok'] = false;
        $diag['sanity_error'] = $e->getMessage();
    }

    return $diag;
};

$selfCheck = static function (PDO $pdo, bool $hasCompanies): array {
    $result = ['ok' => true, 'message' => 'OK'];
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'companies'");
        if ($stmt) {
            $stmt->fetchAll();
            $stmt->closeCursor();
        }
        $stmt = $pdo->query('SELECT 1');
        if ($stmt) {
            $stmt->fetchAll();
            $stmt->closeCursor();
        }
        if ($hasCompanies) {
            $stmt = $pdo->query('SHOW COLUMNS FROM companies');
        } else {
            $stmt = $pdo->query("SHOW TABLES LIKE 'schema_migrations'");
        }
        if ($stmt) {
            $stmt->fetchAll();
            $stmt->closeCursor();
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => $e->getMessage()];
    }
    return $result;
};

$formatShowColumns = static function (array $columns): string {
    if ($columns === []) {
        return 'No rows.';
    }
    $lines = ['Field | Type | Null | Key | Default | Extra'];
    foreach ($columns as $col) {
        $lines[] = sprintf(
            '%s | %s | %s | %s | %s | %s',
            (string)($col['Field'] ?? ''),
            (string)($col['Type'] ?? ''),
            (string)($col['Null'] ?? ''),
            (string)($col['Key'] ?? ''),
            array_key_exists('Default', $col) ? (string)($col['Default'] ?? 'NULL') : '',
            (string)($col['Extra'] ?? '')
        );
    }
    return implode("\n", $lines);
};
$schemaDiagnostics = $fetchCompaniesSchemaDiagnostics($pdo, $companiesOk);
$selfCheckResult = $selfCheck($pdo, $companiesOk);
$storageDiagnostics = storageDiagnostics($storageLogsDir);
$migrationsReadable = is_dir($migrationsDir) && is_readable($migrationsDir);

?><!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>SQL migrations</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 16px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ccc; padding: 8px; text-align: left; }
        th { background: #f3f3f3; }
        form { display: inline; }
        .controls { margin-bottom: 16px; }
        .diagnostics { margin-top: 24px; padding: 12px; background: #f9f9f9; border: 1px solid #e0e0e0; }
        .alert { padding: 12px; background: #ffe5e5; border: 1px solid #ff9b9b; margin-bottom: 12px; }
        .success { padding: 12px; background: #e5ffe5; border: 1px solid #98e098; margin-bottom: 12px; }
        .log { max-height: 320px; overflow: auto; background: #111; color: #0f0; padding: 12px; }
        code { font-family: Consolas, monospace; }
        button { padding: 6px 12px; }
    </style>
</head>
<body>
    <h1>SQL migrations</h1>
    <p>DB connection: <strong><?= htmlEscape($connectionInfo['host']) ?></strong> / <strong><?= htmlEscape($connectionInfo['database']) ?></strong></p>
    <?php if (!empty($fatalLogLines)): ?>
        <div class="diagnostics">
            <h2>Last fatal log tail</h2>
            <div class="log"><code><?= htmlEscape(implode("\n", $fatalLogLines)) ?></code></div>
        </div>
    <?php endif; ?>
    <?php if (!$companiesOk): ?>
        <div class="alert">
            The <code>companies</code> table does not exist. Run the bootstrap migration first (2026_01_27_00_create_companies.sql) or import the dump via CLI.
        </div>
    <?php endif; ?>
    <?php if ($duplicates): ?>
        <div class="alert">
            Duplicate <code>nip</code> values were detected; the <code>11C_unique_companies_nip.sql</code> migration will be skipped.
        </div>
    <?php endif; ?>

    <?php if ($tokenConfigured): ?>
    <div class="controls">
        <form method="post" action="<?= BASE_URL ?>/handlers/run_migrations_batch.php">
            <input type="hidden" name="token" value="<?= htmlEscape($token) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlEscape($csrfToken) ?>">
            <input type="hidden" name="mode" value="next">
            <button type="submit">Run next migration</button>
        </form>
        <form method="post" action="<?= BASE_URL ?>/handlers/run_migrations_batch.php">
            <input type="hidden" name="token" value="<?= htmlEscape($token) ?>">
            <input type="hidden" name="csrf_token" value="<?= htmlEscape($csrfToken) ?>">
            <input type="hidden" name="mode" value="all">
            <button type="submit">Run all (safe, up to 5 files / 15s)</button>
        </form>
    </div>
    <?php else: ?>
        <div class="alert">Batch runner disabled without MIGRATOR_TOKEN. Use Browser migrator below.</div>
    <?php endif; ?>

    <div class="controls" style="margin-top:12px;">
        <h2>Browser migrator</h2>
        <p>
            <a href="<?= htmlEscape(buildMigratorUrl($token, ['dry' => 1])) ?>">DRY-RUN</a>
            | <a href="<?= htmlEscape(buildMigratorUrl($token, ['confirm' => 'YES'])) ?>">RUN (confirm=YES)</a>
            | <a href="<?= htmlEscape(buildMigratorUrl($token, ['confirm' => 'YES', 'force' => 1])) ?>">RUN ALL (confirm=YES)</a>
        </p>
        <?php if ($safeMode): ?>
            <div class="alert">SAFE MODE (no MIGRATOR_TOKEN configured).</div>
        <?php endif; ?>
    </div>

    <h2>Migration files</h2>
    <table>
        <thead>
            <tr>
                <th>Migration</th>
                <th>Status</th>
                <th>Applied at</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($migrations === []): ?>
            <tr><td colspan="4">No SQL migration files were found in <code><?= htmlEscape($migrationsDir) ?></code>.</td></tr>
        <?php endif; ?>
        <?php foreach ($migrations as $entry): ?>
            <?php $status = $entry['applied'] ? 'applied' : ($entry['skip_reason'] ? 'skipped' : 'pending'); ?>
            <tr>
                <td><?= htmlEscape($entry['filename']) ?></td>
                <td><?= htmlEscape($status) ?></td>
                <td><?= $entry['applied_at'] ? htmlEscape($entry['applied_at']) : '' ?></td>
                <td>
                    <?php if ($entry['applied']): ?>
                        ?
                    <?php elseif ($entry['skip_reason'] === 'duplicate_nip'): ?>
                        skipped (duplicate NIP)
                    <?php else: ?>
                        <form method="post" action="<?= BASE_URL ?>/handlers/run_migration.php">
                            <input type="hidden" name="token" value="<?= htmlEscape($token) ?>">
                            <input type="hidden" name="csrf_token" value="<?= htmlEscape($csrfToken) ?>">
                            <input type="hidden" name="filename" value="<?= htmlEscape($entry['filename']) ?>">
                            <button type="submit">Run</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="diagnostics">
        <h2>Diagnostics</h2>
        <ul>
            <li>php_sapi_name(): <strong><?= htmlEscape(php_sapi_name()) ?></strong> (<?= php_sapi_name() === 'cli' ? 'PHP CLI available' : 'PHP CLI likely missing' ?>)</li>
            <li>MIGRATOR_TOKEN configured: <?= $tokenConfigured ? '<strong>yes</strong>' : '<strong>no</strong>' ?></li>
            <li>SAFE MODE: <?= $safeMode ? '<strong>yes</strong>' : '<strong>no</strong>' ?></li>
            <li>DB server version: <?= $serverVersionError ? '<strong>error</strong> - ' . htmlEscape($serverVersionError) : '<strong>' . htmlEscape((string)($serverVersion ?? 'unknown')) . '</strong>' ?></li>
            <li>db.local.php readable: <?= $dbLocalReadable ? '<strong>yes</strong>' : '<strong>no</strong>' ?></li>
            <li>storage/logs writable: <?= $storageDiagnostics['writable'] ? '<strong>yes</strong>' : '<strong>no</strong>' ?></li>
            <li>sql/migrations readable: <?= $migrationsReadable ? '<strong>yes</strong>' : '<strong>no</strong>' ?></li>
            <li>Selfcheck (SHOW TABLES / SELECT 1 / SHOW COLUMNS): <?= $selfCheckResult['ok'] ? '<strong>ok</strong>' : '<strong>fail</strong>' ?><?= !$selfCheckResult['ok'] ? ' - ' . htmlEscape($selfCheckResult['message']) : '' ?></li>
            <li>Log file: <code><?= htmlEscape($logInfo['path']) ?></code><?= $logInfo['is_fallback'] ? ' (falling back to /tmp)' : '' ?></li>
        </ul>
    </div>

    <div class="diagnostics">
        <h2>DB diagnostics</h2>
        <ul>
            <li>Driver: <?= $driverError ? '<strong>error</strong> - ' . htmlEscape($driverError) : '<strong>' . htmlEscape((string)($driverName ?? 'unknown')) . '</strong>' ?></li>
            <li>Host: <strong><?= htmlEscape($maskValue($resolvedHost)) ?></strong></li>
            <li>Database: <strong><?= htmlEscape($maskValue($resolvedDb)) ?></strong></li>
            <li>Username: <strong><?= htmlEscape($maskValue($resolvedUser)) ?></strong></li>
            <li>Charset: <strong><?= htmlEscape($maskValue($resolvedCharset)) ?></strong></li>
            <li>SELECT 1: <?= $select1Ok === true ? '<strong>ok</strong>' : ($select1Ok === false ? '<strong>fail</strong> - ' . htmlEscape($select1Error ?? 'unknown') : '<strong>n/a</strong>') ?></li>
        </ul>
    </div>


    <div class="diagnostics">
        <h2>Schema diagnostics</h2>
        <?php if (empty($schemaDiagnostics['has_table'])): ?>
            <div class="alert">companies table missing.</div>
        <?php else: ?>
            <h3>SHOW COLUMNS FROM companies</h3>
            <?php if (!empty($schemaDiagnostics['columns_error'])): ?>
                <div class="alert"><?= htmlEscape($schemaDiagnostics['columns_error']) ?></div>
            <?php else: ?>
                <pre><code><?= htmlEscape($formatShowColumns($schemaDiagnostics['columns'] ?? [])) ?></code></pre>
            <?php endif; ?>

            <h3>SHOW CREATE TABLE companies</h3>
            <?php if (!empty($schemaDiagnostics['create_error'])): ?>
                <div class="alert"><?= htmlEscape($schemaDiagnostics['create_error']) ?></div>
            <?php else: ?>
                <pre><code><?= htmlEscape($schemaDiagnostics['create_sql'] ?? '') ?></code></pre>
            <?php endif; ?>

            <h3>DB sanity</h3>
            <p><code>SELECT c.name_full FROM companies c LIMIT 1</code></p>
            <?php if (!empty($schemaDiagnostics['sanity_ok'])): ?>
                <div class="success">OK</div>
            <?php else: ?>
                <div class="alert"><?= htmlEscape($schemaDiagnostics['sanity_error'] ?? 'Unknown error') ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    <h2>Recent logs</h2>
    <div class="log"><code><?= htmlEscape(implode("\n", $logLines) ?: 'Log is empty or not readable.') ?></code></div>
</body>
</html>
