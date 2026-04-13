<?php
declare(strict_types=1);

require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/documents.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    http_response_code(403);
    exit('Brak dostepu.');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Brak identyfikatora kampanii.');
}

function resolveCampaignAudioFilePathForStream(?string $storedPath): ?string
{
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') {
        return null;
    }
    if ($storedPath[0] === '/' && is_file($storedPath)) {
        return $storedPath;
    }
    $normalized = ltrim($storedPath, '/');
    if (strpos($normalized, 'storage/') === 0) {
        return dirname(__DIR__) . '/' . $normalized;
    }
    return __DIR__ . '/' . $normalized;
}

$source = 'kampanie';
$row = null;
$stmt = $pdo->prepare('SELECT id, audio_file FROM kampanie WHERE id = :id LIMIT 1');
if ($stmt && $stmt->execute([':id' => $id])) {
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if ($row && normalizeRole($currentUser) === 'Handlowiec' && !canUserAccessKampania($pdo, $currentUser, $id)) {
    http_response_code(403);
    exit('Brak dostepu do kampanii.');
}

if (!$row) {
    $source = 'kampanie_tygodniowe';
    $stmt = $pdo->prepare('SELECT id, audio_file FROM kampanie_tygodniowe WHERE id = :id LIMIT 1');
    if ($stmt && $stmt->execute([':id' => $id])) {
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!$row) {
    http_response_code(404);
    exit('Nie znaleziono kampanii.');
}

$audioFile = trim((string)($row['audio_file'] ?? ''));
if ($audioFile === '') {
    http_response_code(404);
    exit('Brak pliku audio.');
}

$fullPath = resolveCampaignAudioFilePathForStream($audioFile);
if (!$fullPath || !is_file($fullPath) || !is_readable($fullPath)) {
    http_response_code(404);
    exit('Plik audio nie jest dostepny.');
}

$mimeType = 'application/octet-stream';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $detected = finfo_file($finfo, $fullPath);
        if (is_string($detected) && $detected !== '') {
            $mimeType = $detected;
        }
        finfo_close($finfo);
    }
}

$filename = basename($fullPath);
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . (string)filesize($fullPath));
header('Content-Disposition: inline; filename="' . str_replace('"', '', $filename) . '"');
header('Accept-Ranges: bytes');
header('Cache-Control: private, max-age=3600');

readfile($fullPath);
exit;

