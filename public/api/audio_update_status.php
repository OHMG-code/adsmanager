<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../includes/emisje_helpers.php';
require_once __DIR__ . '/../includes/briefs.php';
require_once __DIR__ . '/../includes/communication_events.php';
require_once __DIR__ . '/../includes/communication_templates.php';

requireLogin();

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

ensureSpotAudioFilesTable($pdo);
ensureSpotColumns($pdo);
ensureSystemConfigColumns($pdo);
ensureIntegrationsLogsTable($pdo);
ensureCommunicationEventsTable($pdo);
ensureNotificationsTable($pdo);

$audioId = isset($_POST['audio_id']) ? (int)$_POST['audio_id'] : 0;
$action = trim((string)($_POST['action'] ?? ''));
$override = !empty($_POST['override']);
$reason = trim((string)($_POST['reason'] ?? ''));

if ($audioId <= 0 || !in_array($action, ['approve', 'reject'], true)) {
    $_SESSION['audio_upload_error'] = 'Nieprawidłowe żądanie zmiany statusu audio.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    $_SESSION['audio_upload_error'] = 'Niepoprawny token formularza.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

$role = normalizeRole($currentUser);
if (!in_array($role, ['Manager', 'Administrator', 'Handlowiec'], true)) {
    $_SESSION['audio_upload_error'] = 'Brak uprawnień do akceptacji/odrzucenia audio.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

$stmt = $pdo->prepare("SELECT f.*, s.dlugosc_s, s.dlugosc, s.id AS spot_id, s.kampania_id
    FROM spot_audio_files f
    JOIN spoty s ON s.id = f.spot_id
    WHERE f.id = ?
    LIMIT 1");
$stmt->execute([$audioId]);
$file = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$file) {
    $_SESSION['audio_upload_error'] = 'Nie znaleziono pliku audio.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

$spotId = (int)$file['spot_id'];
$campaignId = (int)($file['kampania_id'] ?? 0);
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
    $durationCheckSkipped = false;
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
        // ffprobe może nie być dostępny w środowisku - nie blokuj akceptacji.
        $durationCheckSkipped = true;
    }
}

try {
    $pdo->beginTransaction();
    if ($action === 'approve') {
        $pdo->prepare("UPDATE spot_audio_files SET is_final = 0 WHERE spot_id = ?")->execute([$spotId]);
        $stmtUpdate = $pdo->prepare("UPDATE spot_audio_files
            SET production_status = ?, approved_by_user_id = ?, approved_at = NOW(), rejection_reason = NULL, is_final = 1
            WHERE id = ?");
        $stmtUpdate->execute([audioProductionStatusDbValue('zaakceptowana'), (int)$currentUser['id'], $audioId]);
        $stmtLog = $pdo->prepare("INSERT INTO integrations_logs (user_id, type, request_id, message) VALUES (?, 'audio_approved', ?, ?)");
        $logMessage = 'Zaakceptowano audio dla spotu #' . $spotId;
        if (!empty($durationCheckSkipped)) {
            $logMessage .= ' (bez weryfikacji długości)';
        }
        $stmtLog->execute([(int)$currentUser['id'], 'audio_' . $audioId, $logMessage]);
    } else {
        $stmtUpdate = $pdo->prepare("UPDATE spot_audio_files
            SET production_status = ?, approved_by_user_id = NULL, approved_at = NULL, rejection_reason = ?, is_final = 0
            WHERE id = ?");
        $stmtUpdate->execute([audioProductionStatusDbValue('odrzucona'), $reason, $audioId]);
        $stmtLog = $pdo->prepare("INSERT INTO integrations_logs (user_id, type, request_id, message) VALUES (?, 'audio_rejected', ?, ?)");
        $stmtLog->execute([(int)$currentUser['id'], 'audio_' . $audioId, 'Odrzucono audio dla spotu #' . $spotId . ': ' . $reason]);
    }

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('audio_update_status: ' . $e->getMessage());
    $_SESSION['audio_upload_error'] = 'Nie udało się zapisać statusu audio.';
}

if (empty($_SESSION['audio_upload_error']) && $action === 'approve') {
    if ($campaignId > 0) {
        syncCampaignStatusFromAudio($pdo, $campaignId);
    }

    $idempotencyKey = communicationBuildIdempotencyKey('audio_final_selected', [$audioId, $campaignId]);
    $eventResult = communicationLogEvent($pdo, [
        'event_type' => 'audio_final_selected',
        'idempotency_key' => $idempotencyKey,
        'direction' => 'system',
        'status' => 'logged',
        'subject' => 'Wybrano finalną wersję audio',
        'body' => 'Spot #' . $spotId . ', plik audio #' . $audioId,
        'meta_json' => [
            'spot_id' => $spotId,
            'campaign_id' => $campaignId,
            'audio_id' => $audioId,
            'duration_check_skipped' => !empty($durationCheckSkipped),
        ],
        'campaign_id' => $campaignId > 0 ? $campaignId : null,
        'spot_audio_file_id' => $audioId,
        'created_by_user_id' => (int)$currentUser['id'],
    ]);
    if (empty($eventResult['ok'])) {
        error_log('audio_update_status: communication event failed: ' . ($eventResult['error'] ?? 'unknown'));
    }

    if ($campaignId > 0) {
        $users = communicationResolveCampaignUsers($pdo, $campaignId);
        $tmpl = communicationTemplateInternal('audio_final_selected', [
            'campaign_id' => $campaignId,
            'spot_id' => $spotId,
        ]);
        foreach ([
            (int)($users['campaign_owner_user_id'] ?? 0),
            (int)($users['production_owner_user_id'] ?? 0),
            (int)($users['lead_owner_user_id'] ?? 0),
        ] as $recipientUserId) {
            if ($recipientUserId <= 0) {
                continue;
            }
            communicationCreateInternalNotification(
                $pdo,
                $recipientUserId,
                'audio_final_selected',
                (string)$tmpl['body'],
                'edytuj_spot.php?id=' . $spotId
            );
        }
    }

    $_SESSION['audio_upload_success'] = !empty($durationCheckSkipped)
        ? 'Plik audio został zaakceptowany (bez weryfikacji długości).'
        : 'Plik audio został zaakceptowany.';
} elseif (empty($_SESSION['audio_upload_error'])) {
    $_SESSION['audio_upload_success'] = 'Plik audio został odrzucony.';
}

header('Location: ' . $redirect);
exit;
