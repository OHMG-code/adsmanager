<?php
// config/config.php
// Kolejnosc: 1) config/db.local.php (poza repo), 2) zmienne srodowiskowe.

// Ustal bazowy URL aplikacji (dla linkow/redirectow).
$baseConfigPath = __DIR__ . '/../public/includes/config.php';
if (file_exists($baseConfigPath)) {
    require_once $baseConfigPath;
} elseif (!defined('BASE_URL')) {
    define('BASE_URL', '/crm/public');
}

$rootDir = dirname(__DIR__);
require_once $rootDir . '/services/InstallGuard.php';

// Load .env once for the whole app (shared for web + CLI + admin tools).
if (empty($GLOBALS['__dotenv_loaded'])) {
    $GLOBALS['__dotenv_loaded'] = true;
    $envPath = $rootDir . '/.env';
    if (is_file($envPath) && is_readable($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                $pos = strpos($line, '=');
                if ($pos === false) {
                    continue;
                }
                $key = trim(substr($line, 0, $pos));
                $value = trim(substr($line, $pos + 1));
                if ($key === '') {
                    continue;
                }
                if (
                    getenv($key) === false
                    && !array_key_exists($key, $_ENV)
                    && !array_key_exists($key, $_SERVER)
                ) {
                    putenv($key . '=' . $value);
                    $_ENV[$key] = $value;
                    $_SERVER[$key] = $value;
                }
            }
        }
    }
}

$dbConfig = [];
$localPath = __DIR__ . '/db.local.php';
if (file_exists($localPath)) {
    $loaded = include $localPath;
    if (is_array($loaded)) {
        $dbConfig = $loaded;
    }
}

$host = array_key_exists('host', $dbConfig) ? (string)$dbConfig['host'] : (string)(getenv('DB_HOST') ?: '');
$port = array_key_exists('port', $dbConfig) ? (int)$dbConfig['port'] : (int)(getenv('DB_PORT') ?: 3306);
$port = $port > 0 ? $port : 3306;
$db = array_key_exists('name', $dbConfig) ? (string)$dbConfig['name'] : (string)(getenv('DB_NAME') ?: '');
$user = array_key_exists('user', $dbConfig) ? (string)$dbConfig['user'] : (string)(getenv('DB_USER') ?: '');
$pass = array_key_exists('pass', $dbConfig) ? (string)$dbConfig['pass'] : (string)(getenv('DB_PASS') ?: '');
$charset = array_key_exists('charset', $dbConfig) ? (string)$dbConfig['charset'] : (string)(getenv('DB_CHARSET') ?: 'utf8mb4');
$tablePrefix = array_key_exists('table_prefix', $dbConfig) ? trim((string)$dbConfig['table_prefix']) : trim((string)(getenv('DB_TABLE_PREFIX') ?: ''));
if ($charset === '') {
    $charset = 'utf8mb4';
}
if (!defined('DB_TABLE_PREFIX')) {
    define('DB_TABLE_PREFIX', $tablePrefix);
}

$appEnv = trim((string)($dbConfig['app_env'] ?? getenv('APP_ENV') ?: ''));
if ($appEnv === '' && defined('APP_ENV')) {
    $appEnv = (string)APP_ENV;
}
if ($appEnv === '') {
    $appEnv = 'production';
}
$appEnv = strtolower($appEnv);
if ($appEnv === 'prod') {
    $appEnv = 'production';
} elseif ($appEnv === 'dev' || $appEnv === 'local') {
    $appEnv = 'development';
}
if (!in_array($appEnv, ['production', 'development', 'staging', 'test'], true)) {
    $appEnv = 'production';
}
if (!defined('APP_ENV')) {
    define('APP_ENV', $appEnv);
}

$appDebugRaw = $dbConfig['app_debug'] ?? (getenv('APP_DEBUG') !== false ? getenv('APP_DEBUG') : null);
if (!defined('APP_DEBUG')) {
    if ($appDebugRaw === null || (is_string($appDebugRaw) && trim($appDebugRaw) === '')) {
        define('APP_DEBUG', APP_ENV !== 'production');
    } elseif (is_bool($appDebugRaw)) {
        define('APP_DEBUG', $appDebugRaw);
    } else {
        $normalizedDebug = strtolower(trim((string)$appDebugRaw));
        define('APP_DEBUG', in_array($normalizedDebug, ['1', 'true', 'yes', 'on'], true));
    }
}

$appVersion = trim((string)($dbConfig['app_version'] ?? getenv('APP_VERSION') ?? ''));
if ($appVersion === '') {
    $releasePath = $rootDir . '/release.json';
    if (is_file($releasePath) && is_readable($releasePath)) {
        $releasePayload = file_get_contents($releasePath);
        if ($releasePayload !== false) {
            $releaseJson = json_decode($releasePayload, true);
            if (is_array($releaseJson)) {
                $appVersion = trim((string)($releaseJson['version'] ?? ''));
            }
        }
    }
}
if ($appVersion === '') {
    $appVersion = '0.0.0.0';
}
if (!defined('APP_VERSION')) {
    define('APP_VERSION', $appVersion);
}

$migratorToken = trim((string)($dbConfig['migrator_token'] ?? getenv('MIGRATOR_TOKEN') ?? ''));
if (!defined('MIGRATOR_TOKEN')) {
    define('MIGRATOR_TOKEN', $migratorToken);
}

$envUpdateManifestUrl = getenv('UPDATE_MANIFEST_URL');
if ($envUpdateManifestUrl === false || trim((string)$envUpdateManifestUrl) === '') {
    $envUpdateManifestUrl = getenv('APP_UPDATE_MANIFEST_URL');
}
$updateManifestUrl = trim((string)($dbConfig['update_manifest_url'] ?? $dbConfig['manifest_url'] ?? ($envUpdateManifestUrl !== false ? $envUpdateManifestUrl : '')));
if (!defined('UPDATE_MANIFEST_URL')) {
    define('UPDATE_MANIFEST_URL', $updateManifestUrl);
}

$bootstrapInfo = InstallGuard::inspect([
    'host' => $host,
    'port' => $port,
    'name' => $db,
    'user' => $user,
    'pass' => $pass,
    'charset' => $charset,
], $_SERVER);
$GLOBALS['app_bootstrap'] = $bootstrapInfo;

if (!defined('APP_BOOTSTRAP_STATUS')) {
    define('APP_BOOTSTRAP_STATUS', (string)($bootstrapInfo['status'] ?? 'unknown'));
}
if (!defined('APP_INSTALL_STATE')) {
    define('APP_INSTALL_STATE', (string)($bootstrapInfo['install_state'] ?? InstallGuard::STATE_NOT_INSTALLED));
}
if (!defined('APP_BOOTSTRAP_REASON')) {
    define('APP_BOOTSTRAP_REASON', (string)($bootstrapInfo['reason'] ?? 'unknown'));
}
if (!defined('APP_REQUEST_KIND')) {
    define('APP_REQUEST_KIND', (string)($bootstrapInfo['request_kind'] ?? InstallGuard::REQUEST_WEB_HTML));
}

$secretKey = trim((string)($dbConfig['app_secret'] ?? getenv('APP_SECRET_KEY') ?? ''));
if ($secretKey === '' && APP_BOOTSTRAP_STATUS === InstallGuard::STATUS_INSTALLED && $host !== '' && $db !== '' && $user !== '') {
    $secretKey = hash('sha256', $host . '|' . $db . '|' . $user, false);
}
if (!defined('APP_SECRET_KEY')) {
    define('APP_SECRET_KEY', $secretKey);
}

$mailKey = trim((string)($dbConfig['mail_secret'] ?? getenv('MAIL_SECRET_KEY') ?? ''));
if (!defined('MAIL_SECRET_KEY')) {
    define('MAIL_SECRET_KEY', $mailKey);
}

$pdo = null;
if (APP_BOOTSTRAP_STATUS !== InstallGuard::STATUS_INSTALLED) {
    emitBootstrapInterruption($bootstrapInfo);
}

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $db, $charset);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
];
if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
    $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    }
} catch (PDOException $e) {
    $bootstrapError = $bootstrapInfo;
    $bootstrapError['status'] = InstallGuard::STATUS_DB_ERROR;
    $bootstrapError['reason'] = 'runtime_pdo_error';
    $bootstrapError['message'] = 'Nie udało się utworzyć połączenia PDO: ' . $e->getMessage();
    $GLOBALS['app_bootstrap'] = $bootstrapError;
    emitBootstrapInterruption($bootstrapError);
}

/**
 * @param array<string,mixed> $info
 */
function emitBootstrapInterruption(array $info): void
{
    $status = (string)($info['status'] ?? 'unknown');
    $reason = (string)($info['reason'] ?? 'unknown');
    $requestKind = (string)($info['request_kind'] ?? InstallGuard::REQUEST_WEB_HTML);
    $detailMessage = trim((string)($info['message'] ?? ''));

    error_log(sprintf(
        'config bootstrap interrupted: status=%s reason=%s script=%s uri=%s detail=%s',
        $status,
        $reason,
        (string)($info['script_name'] ?? '-'),
        (string)($info['request_uri'] ?? '-'),
        $detailMessage !== '' ? $detailMessage : '-'
    ));

    if ($status === InstallGuard::STATUS_NOT_INSTALLED && $requestKind === InstallGuard::REQUEST_WEB_HTML && !InstallGuard::isInstallerEntry($_SERVER)) {
        if (!headers_sent()) {
            header('Location: ' . InstallGuard::installerUrl(BASE_URL, $info));
            exit;
        }
    }

    $publicMessage = resolveBootstrapPublicMessage($status, $reason);
    if ($requestKind === InstallGuard::REQUEST_CLI) {
        fwrite(STDERR, $publicMessage . PHP_EOL);
        if ($detailMessage !== '' && $detailMessage !== $publicMessage) {
            fwrite(STDERR, $detailMessage . PHP_EOL);
        }
        exit(1);
    }

    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $publicMessage;
    exit;
}

function resolveBootstrapPublicMessage(string $status, string $reason): string
{
    if ($status === InstallGuard::STATUS_NOT_INSTALLED) {
        if ($reason === 'database_missing') {
            return 'Aplikacja nie jest jeszcze zainstalowana. Docelowa baza danych nie istnieje lub nie zostala przygotowana.';
        }
        return 'Aplikacja nie jest jeszcze zainstalowana.';
    }

    if ($status === InstallGuard::STATUS_DB_ERROR) {
        return 'Aplikacja jest skonfigurowana, ale nie moze polaczyc sie z baza danych.';
    }

    return 'Bootstrap aplikacji zostal przerwany.';
}
