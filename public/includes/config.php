<?php
// Centralne ustawienia aplikacji niezależne od bazy.
if (!defined('BASE_URL')) {
    define('BASE_URL', '/crm/public');
}
if (!defined('DEBUG_AUTH')) {
    define('DEBUG_AUTH', false);
}

// Temporary server-side error logging.
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
$logDir = __DIR__ . '/../../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logFile = $logDir . '/php_errors.log';
if (!is_file($logFile)) {
    @touch($logFile);
}
ini_set('error_log', $logFile);
