<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/emisje_helpers.php';
require_once __DIR__ . '/includes/briefs.php';
require_once __DIR__ . '/includes/audio_sources.php';
require_once __DIR__ . '/includes/crm_activity.php';

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['audio_upload_error'] = 'Nieprawidlowa metoda zadania.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

ensureSystemConfigColumns($pdo);
ensureSpotAudioFilesTable($pdo);
ensureSpotColumns($pdo);
ensureKampanieOwnershipColumns($pdo);

$spotId = isset($_POST['spot_id']) ? (int)$_POST['spot_id'] : 0;
if ($spotId <= 0) {
    $_SESSION['audio_upload_error'] = 'Nieprawidlowy identyfikator spotu.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

$redirect = BASE_URL . '/edytuj_spot.php?id=' . $spotId;

if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    $_SESSION['audio_upload_error'] = 'Niepoprawny token formularza.';
    header('Location: ' . $redirect);
    exit;
}

if (!canAccessSpot($pdo, $spotId, $currentUser)) {
    $_SESSION['audio_upload_error'] = 'Brak uprawnien do wgrywania plikow dla tego spotu.';
    header('Location: ' . $redirect);
    exit;
}

$stmtCfg = $pdo->query("SELECT audio_upload_max_mb, audio_allowed_ext FROM konfiguracja_systemu WHERE id = 1");
$cfg = $stmtCfg ? $stmtCfg->fetch(PDO::FETCH_ASSOC) : [];
$maxMb = max(1, (int)($cfg['audio_upload_max_mb'] ?? 50));
$allowedExt = (string)($cfg['audio_allowed_ext'] ?? 'wav,mp3,m4a');
$allowedList = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', $allowedExt)))));

$file = $_FILES['audio_file'] ?? null;
if (!$file) {
    $_SESSION['audio_upload_error'] = 'Nie udalo sie przeslac pliku.';
    header('Location: ' . $redirect);
    exit;
}

$validation = audioValidateUploadedFile($file, $allowedList, $maxMb);
if (empty($validation['ok'])) {
    $_SESSION['audio_upload_error'] = (string)($validation['error'] ?? 'Nieprawidlowy plik audio.');
    header('Location: ' . $redirect);
    exit;
}

$stmtMax = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) FROM spot_audio_files WHERE spot_id = ?');
$stmtMax->execute([$spotId]);
$versionNo = ((int)$stmtMax->fetchColumn()) + 1;

$storageDir = audioStorageDir();
if (!$storageDir) {
    $_SESSION['audio_upload_error'] = 'Brak dostepu do katalogu na pliki audio.';
    header('Location: ' . $redirect);
    exit;
}

$ext = (string)$validation['ext'];
$storedFilename = audioCreateStoredFilename($spotId, $versionNo, $ext);
$targetPath = $storageDir . '/' . $storedFilename;

if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
    $_SESSION['audio_upload_error'] = 'Nie udalo sie zapisac pliku na serwerze.';
    header('Location: ' . $redirect);
    exit;
}

$originalName = (string)$validation['original_name'];
$mimeType = (string)$validation['mime'];
$fileSize = (int)@filesize($targetPath);
$sha256 = hash_file('sha256', $targetPath) ?: null;
$metadata = audioProbeMetadata($targetPath);
$uploadNote = trim((string)($_POST['upload_note'] ?? '')) ?: null;

try {
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE spot_audio_files SET is_active = 0 WHERE spot_id = ?')->execute([$spotId]);
    $stmtInsert = $pdo->prepare("INSERT INTO spot_audio_files
        (spot_id, version_no, is_active, is_final, original_filename, stored_filename, mime_type, file_size, audio_format, duration_seconds, bitrate, sample_rate, channels, sha256, production_status, client_audio_status, uploaded_by_user_id, upload_note)
        VALUES (?, ?, 1, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmtInsert->execute([
        $spotId,
        $versionNo,
        $originalName,
        $storedFilename,
        $mimeType,
        $fileSize > 0 ? $fileSize : null,
        $ext,
        $metadata['duration_seconds'],
        $metadata['bitrate'],
        $metadata['sample_rate'],
        $metadata['channels'],
        $sha256,
        audioProductionStatusDbValue('robocza'),
        'do_weryfikacji',
        (int)$currentUser['id'],
        $uploadNote,
    ]);
    $pdo->prepare("UPDATE spoty SET client_audio_status = 'do_weryfikacji' WHERE id = ?")->execute([$spotId]);
    $pdo->commit();

    $stmtSpot = $pdo->prepare('SELECT kampania_id FROM spoty WHERE id = ? LIMIT 1');
    $stmtSpot->execute([$spotId]);
    audioLogCampaignActivity(
        $pdo,
        (int)($stmtSpot->fetchColumn() ?: 0),
        'Wgrano plik audio dla spotu #' . $spotId . ': ' . $originalName,
        (int)$currentUser['id'],
        'Wgrano plik audio'
    );
    $_SESSION['audio_upload_success'] = 'Nowa wersja zostala zapisana i przekazana do weryfikacji.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    @unlink($targetPath);
    error_log('upload_spot_audio: ' . $e->getMessage());
    $_SESSION['audio_upload_error'] = 'Blad zapisu informacji o pliku.';
}

header('Location: ' . $redirect);
exit;
