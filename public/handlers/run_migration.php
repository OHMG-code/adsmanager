<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/MigrationConsole.php';
require_once __DIR__ . '/../services/migration_log_path.php';

requireCapability('manage_system');

if (!defined('MIGRATOR_TOKEN') || MIGRATOR_TOKEN === '') {
    renderResponse('Migration runner disabled', 'MIGRATOR_TOKEN is not configured.', '');
}

$token = trim((string)($_POST['token'] ?? ''));
if (!isMigratorTokenValid($token)) {
    renderResponse('Invalid token', 'You must supply a valid migrator token.', '');
}

$csrf = $_POST['csrf_token'] ?? '';
if (!isCsrfTokenValid($csrf)) {
    renderResponse('Invalid CSRF token', 'CSRF validation failed.', buildPanelUrl($token));
}

$filename = trim((string)($_POST['filename'] ?? ''));
$filename = basename($filename);
if ($filename === '') {
    renderResponse('Missing filename', 'Provide a migration filename to run.', buildPanelUrl($token));
}

$logInfo = resolveMigrationLogPath();
$currentUser = currentUser();
$actor = $currentUser['login'] ?? 'web';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$service = new MigrationConsole($pdo, __DIR__ . '/../sql/migrations', $logInfo['path'], $actor, $ip);
$result = $service->runMigration($filename);
renderResponse('Run migration', $result['message'], buildPanelUrl($token), $result['success'] ?? true);

function isMigratorTokenValid(string $value): bool
{
    if (MIGRATOR_TOKEN === '') {
        return false;
    }
    return hash_equals(MIGRATOR_TOKEN, $value);
}

function buildPanelUrl(string $token): string
{
    $base = BASE_URL . '/admin/migrations.php';
    if ($token === '') {
        return $base;
    }
    return $base . '?token=' . rawurlencode($token);
}

function htmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES);
}

function renderResponse(string $title, string $message, string $returnUrl, bool $success = true): void
{
    if (!$success) {
        http_response_code(500);
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><title>' . htmlEscape($title) . '</title></head><body>';
    echo '<h1>' . htmlEscape($title) . '</h1>';
    echo '<p>' . nl2br(htmlEscape($message)) . '</p>';
    if ($returnUrl !== '') {
        echo '<p><a href="' . htmlEscape($returnUrl) . '">Back to migrations panel</a></p>';
    }
    echo '</body></html>';
    exit;
}
