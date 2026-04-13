<?php
// Centralne ustawienia aplikacji niezależne od bazy.
if (!defined('BASE_URL')) {
    $envBaseUrl = getenv('APP_BASE_URL');
    if ($envBaseUrl !== false && trim($envBaseUrl) !== '') {
        $baseUrl = '/' . trim((string)$envBaseUrl, '/');
    } else {
        $baseUrl = '';
        $publicDir = realpath(dirname(__DIR__));
        $documentRoot = isset($_SERVER['DOCUMENT_ROOT']) ? realpath((string)$_SERVER['DOCUMENT_ROOT']) : false;
        if ($publicDir !== false && $documentRoot !== false) {
            $prefix = str_replace('\\', '/', $documentRoot);
            $target = str_replace('\\', '/', $publicDir);
            if ($prefix !== '' && strpos($target, $prefix) === 0) {
                $relative = trim(substr($target, strlen($prefix)), '/');
                if ($relative !== '') {
                    $baseUrl = '/' . $relative;
                }
            }
        }
    }
    define('BASE_URL', $baseUrl);
}
if (!defined('DEBUG_AUTH')) {
    define('DEBUG_AUTH', false);
}

$rootDir = dirname(__DIR__, 2);
$dbLocalPath = $rootDir . '/config/db.local.php';
$dbLocalConfig = [];
if (is_file($dbLocalPath) && is_readable($dbLocalPath)) {
    $loadedConfig = include $dbLocalPath;
    if (is_array($loadedConfig)) {
        $dbLocalConfig = $loadedConfig;
    }
}

$envAppEnvRaw = getenv('APP_ENV');
$cfgAppEnvRaw = trim((string)($dbLocalConfig['app_env'] ?? ''));
$resolvedAppEnv = trim((string)($envAppEnvRaw !== false ? $envAppEnvRaw : $cfgAppEnvRaw));
if ($resolvedAppEnv === '') {
    $resolvedAppEnv = is_file($rootDir . '/storage/installed.lock') ? 'production' : 'development';
}
$resolvedAppEnv = strtolower($resolvedAppEnv);
if ($resolvedAppEnv === 'prod') {
    $resolvedAppEnv = 'production';
} elseif ($resolvedAppEnv === 'dev' || $resolvedAppEnv === 'local') {
    $resolvedAppEnv = 'development';
}
if (!in_array($resolvedAppEnv, ['production', 'development', 'staging', 'test'], true)) {
    $resolvedAppEnv = is_file($rootDir . '/storage/installed.lock') ? 'production' : 'development';
}

$isProductionLike = $resolvedAppEnv === 'production';
$cfgAppDebugRaw = $dbLocalConfig['app_debug'] ?? null;
$envAppDebugRaw = getenv('APP_DEBUG');
$debugSource = $envAppDebugRaw !== false ? $envAppDebugRaw : $cfgAppDebugRaw;
if ($debugSource === null || (is_string($debugSource) && trim($debugSource) === '')) {
    $resolvedAppDebug = !$isProductionLike;
} elseif (is_bool($debugSource)) {
    $resolvedAppDebug = $debugSource;
} else {
    $normalizedDebug = strtolower(trim((string)$debugSource));
    $resolvedAppDebug = in_array($normalizedDebug, ['1', 'true', 'yes', 'on'], true);
}
if ($isProductionLike) {
    $resolvedAppDebug = false;
}

if (!defined('APP_ENV')) {
    define('APP_ENV', $resolvedAppEnv);
}
if (!defined('APP_DEBUG')) {
    define('APP_DEBUG', $resolvedAppDebug);
}

// Runtime policy for dev/prod.
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

$logDir = $rootDir . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/php.log';
if (!is_file($logFile)) {
    @touch($logFile);
}
ini_set('error_log', $logFile);
