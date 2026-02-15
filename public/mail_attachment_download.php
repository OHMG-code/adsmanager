<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/mailbox_service.php';
require_once __DIR__ . '/../config/config.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

$attachmentId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($attachmentId <= 0) {
    http_response_code(400);
    exit('Brak identyfikatora zalacznika.');
}

ensureCrmMailTables($pdo);
try {
    $stmt = $pdo->prepare(
        'SELECT a.*, m.entity_type, m.entity_id, m.mail_account_id, ma.user_id AS account_user_id
         FROM mail_attachments a
         JOIN mail_messages m ON m.id = a.mail_message_id
         LEFT JOIN mail_accounts ma ON ma.id = m.mail_account_id
         WHERE a.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $attachmentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $row = null;
}

if (!$row) {
    http_response_code(404);
    exit('Nie znaleziono zalacznika.');
}

$entityType = (string)($row['entity_type'] ?? '');
$entityId = (int)($row['entity_id'] ?? 0);
if ($entityType !== '' && $entityId > 0) {
    if (!canAccessCrmObject(mailboxCrmType($entityType), $entityId, $currentUser)) {
        http_response_code(403);
        exit('Brak dostepu.');
    }
} else {
    $accountUserId = (int)($row['account_user_id'] ?? 0);
    if (!canManageSystem($currentUser) && $accountUserId !== (int)$currentUser['id']) {
        http_response_code(403);
        exit('Brak dostepu.');
    }
}

$path = trim((string)($row['storage_path'] ?? ''));
if ($path === '') {
    $path = trim((string)($row['stored_path'] ?? ''));
}
if ($path === '') {
    http_response_code(404);
    exit('Brak pliku.');
}

$baseDir = realpath(dirname(__DIR__) . '/storage');
$absPath = realpath(dirname(__DIR__) . '/' . ltrim($path, '/'));
if (!$baseDir || !$absPath || strpos($absPath, $baseDir) !== 0 || !is_file($absPath)) {
    $baseDirPublic = realpath(__DIR__ . '/storage');
    $absPathPublic = realpath(__DIR__ . '/' . ltrim($path, '/'));
    if (!$baseDirPublic || !$absPathPublic || strpos($absPathPublic, $baseDirPublic) !== 0 || !is_file($absPathPublic)) {
        http_response_code(404);
        exit('Brak pliku.');
    }
    $absPath = $absPathPublic;
}

$filename = basename((string)($row['filename'] ?? basename($absPath)));
$filename = str_replace(['"', "'", "\n", "\r"], '_', $filename);
$mimeType = trim((string)($row['mime_type'] ?? ''));
if ($mimeType === '') {
    $mimeType = 'application/octet-stream';
}

header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($absPath));
readfile($absPath);
exit;
