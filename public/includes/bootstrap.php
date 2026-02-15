<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    require_once $configPath;
}

$dbPath = __DIR__ . '/../db.php';
if (file_exists($dbPath)) {
    require_once $dbPath;
}
