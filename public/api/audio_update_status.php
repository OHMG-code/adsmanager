<?php
require_once __DIR__ . '/../includes/config.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../includes/emisje_helpers.php';

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

ensureSpotAudioFilesTable($pdo);
ensureSpotColumns($pdo);
ensureSystemConfigColumns($pdo);
ensureIntegrationsLogsTable($pdo);

$audioId = isset($_POST['audio_id']) ? (int)$_POST['audio_id'] : 0;
$action = trim((string)($_POST['action'] ?? ''));
$override = !empty($_POST['override']);
$reason = trim((string)($_POST['reason'] ?? ''));

if ($audioId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    $_SESSION['audio_upload_error'] = 'Nieprawidłowe żądanie zmiany statusu audio.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

$role = normalizeRole($currentUser);
if (!in_array($role, ['Manager', 'Administrator'], true)) {
    $_SESSION['audio_upload_error'] = 'Brak uprawnień do akceptacji/odrzucenia audio.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

$stmt = $pdo->prepare("SELECT f.*, s.dlugosc_s, s.dlugosc, s.id AS spot_id FROM spot_audio_files f JOIN spoty s ON s.id = f.spot_id WHERE f.id = ? LIMIT 1");
$stmt->execute([$audioId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$file) {
    $_SESSION['audio_upload_error'] = 'Nie znaleziono pliku audio.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

$spotId = (int)$file['spot_id'];
$redirect = BASE_URL . '/edytuj_spot.php?id=' . $spotId;

if (!canAccessSpot($pdo, $spotId, $currentUser)) {
    $_SESSION['audio_upload_error'] = 'Brak uprawnień do tego spotu.';
    header('Location: ' . $redirect);
    exit;
}

if ((int)$file['is_active'] !== 1) {
    $_SESSION['audio_upload_error'] = 'Status można zmieniać tylko dla aktywnej wersji.';
    header('Location: ' . $redirect);
    exit;
}

function detectAudioDuration(string $path): ?float
{
    if (!function_exists('shell_exec')) {
        return null;
    }
    $probe = shell_exec('command -v ffprobe');
    if (!$probe) {
        return null;
    }
    $cmd = 'ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($path);
    $out = shell_exec($cmd);
    if (!$out) {
        return null;
    }
    $value = trim($out);
    return is_numeric($value) ? (float)$value : null;
}

function mimeMatchesExt(?string $mime, string $ext): bool
{
    $mime = strtolower((string)$mime);
    $ext = strtolower($ext);
    $map = [
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/x-pn-wav'],
        'mp3' => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg'],
    ];
    if (!isset($map[$ext])) {
        return false;
    }
    if ($mime === '') {
        return true;
    }
    return in_array($mime, $map[$ext], true);
}

if ($action === 'reject' && $reason === '') {
    $_SESSION['audio_upload_error'] = 'Podaj powód odrzucenia.';
    header('Location: ' . $redirect);
    exit;
}

$storedFilename = (string)$file['stored_filename'];
$storageDir = dirname(__DIR__, 2) . '/storage/audio';
$path = $storageDir . '/' . $storedFilename;
if (!is_file($path)) {
    $_SESSION['audio_upload_error'] = 'Plik audio nie istnieje na serwerze.';
    header('Location: ' . $redirect);
    exit;
}

$ext = strtolower(pathinfo($storedFilename, PATHINFO_EXTENSION));
if (!in_array($ext, ['wav', 'mp3'], true)) {
    $_SESSION['audio_upload_error'] = 'Nieprawidłowe rozszerzenie pliku audio.';
    header('Location: ' . $redirect);
    exit;
}
if (!mimeMatchesExt((string)($file['mime_type'] ?? ''), $ext)) {
    $_SESSION['audio_upload_error'] = 'MIME pliku nie zgadza się z rozszerzeniem.';
    header('Location: ' . $redirect);
    exit;
}

$duration = detectAudioDuration($path);
$spotLength = (int)($file['dlugosc_s'] ?? 0);
if ($spotLength <= 0 && isset($file['dlugosc'])) {
    $spotLength = (int)$file['dlugosc'];
}
if ($spotLength <= 0) {
    $spotLength = 30;
}

if ($action === 'approve') {
    if ($duration !== null) {
        $diff = abs($duration - $spotLength);
        if ($diff > 1.0 && !$override) {
            $_SESSION['audio_upload_error'] = sprintf(
                'Długość pliku (%.0f s) nie zgadza się z długością spotu (%d s).',
                round($duration),
                $spotLength
            );
            header('Location: ' . $redirect);
            exit;
        }
    } else if (!$override) {
        $_SESSION['audio_upload_error'] = 'Nie udało się zweryfikować długości audio. Zaznacz override, aby zaakceptować.';
        header('Location: ' . $redirect);
        exit;
    }
}

try {
    $pdo->beginTransaction();
    if ($action === 'approve') {
        $stmtUpdate = $pdo->prepare("UPDATE spot_audio_files
            SET production_status = 'Zaakceptowany', approved_by_user_id = ?, approved_at = NOW(), rejection_reason = NULL
            WHERE id = ?");
        $stmtUpdate->execute([(int)$currentUser['id'], $audioId]);
        $stmtLog = $pdo->prepare("INSERT INTO integrations_logs (user_id, type, request_id, message) VALUES (?, 'audio_approved', ?, ?)");
        $stmtLog->execute([(int)$currentUser['id'], 'audio_' . $audioId, 'Zaakceptowano audio dla spotu #' . $spotId]);
        $_SESSION['audio_upload_success'] = 'Plik audio został zaakceptowany.';
    } else {
        $stmtUpdate = $pdo->prepare("UPDATE spot_audio_files
            SET production_status = 'Odrzucony', approved_by_user_id = NULL, approved_at = NULL, rejection_reason = ?
            WHERE id = ?");
        $stmtUpdate->execute([$reason, $audioId]);
        $stmtLog = $pdo->prepare("INSERT INTO integrations_logs (user_id, type, request_id, message) VALUES (?, 'audio_rejected', ?, ?)");
        $stmtLog->execute([(int)$currentUser['id'], 'audio_' . $audioId, 'Odrzucono audio dla spotu #' . $spotId . ': ' . $reason]);
        $_SESSION['audio_upload_success'] = 'Plik audio został odrzucony.';
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('audio_update_status: ' . $e->getMessage());
    $_SESSION['audio_upload_error'] = 'Nie udało się zapisać statusu audio.';
}

header('Location: ' . $redirect);
exit;


