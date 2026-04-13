<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    if (!headers_sent()) {
        session_start();
    } else {
        headers_sent($file, $line);
        error_log(sprintf(
            'bootstrap: skipped session_start because headers were already sent at %s:%d',
            (string)$file,
            (int)$line
        ));
    }
}

$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

$dbPath = __DIR__ . '/../db.php';
if (file_exists($dbPath)) {
    require_once $dbPath;
}

$maintenanceGuardPath = __DIR__ . '/../../services/AppUpdateMaintenanceGuard.php';
if (isset($pdo) && $pdo instanceof PDO && file_exists($maintenanceGuardPath)) {
    require_once $maintenanceGuardPath;
    AppUpdateMaintenanceGuard::enforce($pdo, defined('BASE_URL') ? (string)BASE_URL : '');
}
