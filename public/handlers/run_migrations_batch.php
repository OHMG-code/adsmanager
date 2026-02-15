<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../services/MigrationConsole.php';
require_once __DIR__ . '/../services/migration_log_path.php';

requireCapability('manage_system');

if (!defined('MIGRATOR_TOKEN') || MIGRATOR_TOKEN === '') {
    renderResponse('Batch runner disabled', 'MIGRATOR_TOKEN is not configured.', '');
}

$token = trim((string)($_POST['token'] ?? ''));
if (!isMigratorTokenValid($token)) {
    renderResponse('Invalid token', 'You must supply a valid migrator token.', '');
}

$csrf = $_POST['csrf_token'] ?? '';
if (!isCsrfTokenValid($csrf)) {
    renderResponse('CSRF failure', 'CSRF validation failed.', buildPanelUrl($token));
}

$mode = $_POST['mode'] ?? 'all';
if (!in_array($mode, ['next', 'all'], true)) {
    renderResponse('Invalid mode', 'Parameter mode must be either "next" or "all".', buildPanelUrl($token));
}

$logInfo = resolveMigrationLogPath();
$currentUser = currentUser();
$actor = $currentUser['login'] ?? 'web';
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$service = new MigrationConsole($pdo, __DIR__ . '/../sql/migrations', $logInfo['path'], $actor, $ip);

$batch = $mode === 'next'
    ? $service->runNextMigration()
    : $service->runBatchMigrations(5, 15);

$lines = [];
if (empty($batch['results'])) {
    $lines[] = 'No migrations were executed.';
} else {
    foreach ($batch['results'] as $result) {
        $status = $result['success'] ? 'ok' : 'failed';
        $lines[] = sprintf('%s [%s]: %s', $result['filename'] ?? '(unknown)', $status, $result['message'] ?? '');
    }
}

$lines[] = sprintf('Executed %d migration(s); %d pending.', $batch['executed'] ?? 0, $batch['remaining'] ?? 0);
if (!empty($batch['duration'])) {
    $lines[] = sprintf('Elapsed time: %.2fs.', (float)$batch['duration']);
}
if (!empty($batch['continue'])) {
    $lines[] = 'More migrations remain; click Run all (safe) again to continue.';
}

$success = true;
foreach ($batch['results'] as $result) {
    if (empty($result['success'])) {
        $success = false;
        break;
    }
}

renderResponse('Batch migration', implode("\n", $lines), buildPanelUrl($token), $success);

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
    return $token === '' ? $base : $base . '?token=' . rawurlencode($token);
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
