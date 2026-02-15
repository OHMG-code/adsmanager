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

ensureSystemConfigColumns($pdo);
ensureSpotAudioFilesTable($pdo);
ensureSpotColumns($pdo);
ensureKampanieOwnershipColumns($pdo);

$spotId = isset($_POST['spot_id']) ? (int)$_POST['spot_id'] : 0;
if ($spotId <= 0) {
    $_SESSION['audio_upload_error'] = 'Nieprawidłowy identyfikator spotu.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

if (!canAccessSpot($pdo, $spotId, $currentUser)) {
    $_SESSION['audio_upload_error'] = 'Brak uprawnień do wgrywania plików dla tego spotu.';
    header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
    exit;
}

$stmtCfg = $pdo->query("SELECT audio_upload_max_mb, audio_allowed_ext FROM konfiguracja_systemu WHERE id = 1");
$cfg = $stmtCfg ? $stmtCfg->fetch(PDO::FETCH_ASSOC) : [];
$maxMb = max(1, (int)($cfg['audio_upload_max_mb'] ?? 50));
$allowedExt = (string)($cfg['audio_allowed_ext'] ?? 'wav,mp3');
$allowedList = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', $allowedExt)))));

$file = $_FILES['audio_file'] ?? null;
if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['audio_upload_error'] = 'Nie udało się przesłać pliku.';
    header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
    exit;
}

$originalName = (string)($file['name'] ?? '');
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if ($ext === '' || ($allowedList && !in_array($ext, $allowedList, true))) {
    $_SESSION['audio_upload_error'] = 'Niedozwolone rozszerzenie pliku.';
    header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
    exit;
}

$maxBytes = $maxMb * 1024 * 1024;
if (!empty($file['size']) && (int)$file['size'] > $maxBytes) {
    $_SESSION['audio_upload_error'] = 'Plik przekracza limit rozmiaru.';
    header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
    exit;
}

$stmtMax = $pdo->prepare("SELECT MAX(version_no) AS max_version FROM spot_audio_files WHERE spot_id = ?");
$stmtMax->execute([$spotId]);
$maxVersion = (int)($stmtMax->fetch(PDO::FETCH_ASSOC)['max_version'] ?? 0);
$versionNo = $maxVersion + 1;

$storageDir = dirname(__DIR__) . '/storage/audio';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0775, true);
}
if (!is_dir($storageDir) || !is_writable($storageDir)) {
    $_SESSION['audio_upload_error'] = 'Brak dostępu do katalogu na pliki audio.';
    header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
    exit;
}

$timestamp = time();
$random = bin2hex(random_bytes(4));
$storedFilename = sprintf('spot_%d_v%d_%d_%s.%s', $spotId, $versionNo, $timestamp, $random, $ext);
$targetPath = $storageDir . '/' . $storedFilename;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    $_SESSION['audio_upload_error'] = 'Nie udało się zapisać pliku na serwerze.';
    header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
    exit;
}

$sha256 = hash_file('sha256', $targetPath) ?: null;
$mimeType = !empty($file['type']) ? $file['type'] : (function_exists('mime_content_type') ? mime_content_type($targetPath) : null);
$fileSize = (int)@filesize($targetPath);
$uploadNote = trim((string)($_POST['upload_note'] ?? ''));
if ($uploadNote === '') {
    $uploadNote = null;
}

try {
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE spot_audio_files SET is_active = 0 WHERE spot_id = ?")->execute([$spotId]);
    $stmtInsert = $pdo->prepare("INSERT INTO spot_audio_files
        (spot_id, version_no, is_active, original_filename, stored_filename, mime_type, file_size, sha256, production_status, uploaded_by_user_id, upload_note)
        VALUES (?, ?, 1, ?, ?, ?, ?, ?, 'Do akceptacji', ?, ?)");
    $stmtInsert->execute([
        $spotId,
        $versionNo,
        $originalName,
        $storedFilename,
        $mimeType,
        $fileSize ?: null,
        $sha256,
        (int)$currentUser['id'],
        $uploadNote,
    ]);
    $pdo->commit();
    $_SESSION['audio_upload_success'] = 'Nowa wersja została zapisana.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @unlink($targetPath);
    $_SESSION['audio_upload_error'] = 'Błąd zapisu informacji o pliku.';
}

header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
exit;


