<?php
require_once __DIR__ . '/includes/config.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/emisje_helpers.php';

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

ensureSpotAudioFilesTable($pdo);
ensureSpotColumns($pdo);
ensureKampanieOwnershipColumns($pdo);

$fileId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($fileId <= 0) {
    http_response_code(400);
    echo 'Nieprawidłowy identyfikator pliku.';
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM spot_audio_files WHERE id = ? LIMIT 1");
$stmt->execute([$fileId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$file) {
    http_response_code(404);
    echo 'Plik nie został znaleziony.';
    exit;
}

$spotId = (int)$file['spot_id'];
if (!canAccessSpot($pdo, $spotId, $currentUser)) {
    http_response_code(403);
    echo 'Brak uprawnień do pobrania tego pliku.';
    exit;
}

$storageDir = dirname(__DIR__) . '/storage/audio';
$storedFilename = (string)$file['stored_filename'];
$path = $storageDir . '/' . $storedFilename;
if (!is_file($path)) {
    http_response_code(404);
    echo 'Plik nie istnieje na serwerze.';
    exit;
}

$mime = $file['mime_type'] ?: 'application/octet-stream';
$downloadName = $file['original_filename'] ?? ('spot_' . $spotId . '.audio');
$downloadName = basename($downloadName);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . addslashes($downloadName) . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;


