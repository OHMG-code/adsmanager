<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../includes/emisje_helpers.php';
require_once __DIR__ . '/../includes/briefs.php';
require_once __DIR__ . '/../includes/audio_sources.php';
require_once __DIR__ . '/../includes/crm_activity.php';
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
    $_SESSION['audio_upload_error'] = 'Nieprawidlowe zadanie zmiany statusu audio.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}
if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    $_SESSION['audio_upload_error'] = 'Niepoprawny token formularza.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}
if (!in_array(normalizeRole($currentUser), ['Manager', 'Administrator', 'Handlowiec'], true)) {
    $_SESSION['audio_upload_error'] = 'Brak uprawnien do akceptacji/odrzucenia audio.';
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
    $_SESSION['audio_upload_error'] = 'Brak uprawnien do tego spotu.';
    header('Location: ' . $redirect);
    exit;
}
if ((int)$file['is_active'] !== 1) {
    $_SESSION['audio_upload_error'] = 'Status mozna zmieniac tylko dla aktywnej wersji.';
    header('Location: ' . $redirect);
    exit;
}
if ($action === 'reject' && $reason === '') {
    $_SESSION['audio_upload_error'] = 'Podaj powod odrzucenia.';
    header('Location: ' . $redirect);
    exit;
}

$storedFilename = (string)$file['stored_filename'];
$path = dirname(__DIR__, 2) . '/storage/audio/' . $storedFilename;
if (!is_file($path)) {
    $_SESSION['audio_upload_error'] = 'Plik audio nie istnieje na serwerze.';
    header('Location: ' . $redirect);
    exit;
}
$ext = strtolower(pathinfo($storedFilename, PATHINFO_EXTENSION));
if (!in_array($ext, ['wav', 'mp3', 'm4a'], true)) {
    $_SESSION['audio_upload_error'] = 'Nieprawidlowe rozszerzenie pliku audio.';
    header('Location: ' . $redirect);
    exit;
}
if (!audioMimeMatchesExt((string)($file['mime_type'] ?? ''), $ext)) {
    $_SESSION['audio_upload_error'] = 'MIME pliku nie zgadza sie z rozszerzeniem.';
    header('Location: ' . $redirect);
    exit;
}

$metadata = audioProbeMetadata($path);
$duration = $metadata['duration_seconds'];
$spotLength = (int)($file['dlugosc_s'] ?? 0);
if ($spotLength <= 0 && isset($file['dlugosc'])) {
    $spotLength = (int)$file['dlugosc'];
}
if ($spotLength <= 0) {
    $spotLength = 30;
}

$durationCheckSkipped = false;
if ($action === 'approve') {
    if ($duration !== null) {
        $diff = abs((float)$duration - $spotLength);
        if ($diff > 1.0 && !$override) {
            $_SESSION['audio_upload_error'] = sprintf('Dlugosc pliku (%.0f s) nie zgadza sie z dlugoscia spotu (%d s).', round((float)$duration), $spotLength);
            header('Location: ' . $redirect);
            exit;
        }
    } elseif (!$override) {
        $durationCheckSkipped = true;
    }
}

try {
    $pdo->beginTransaction();
    if ($action === 'approve') {
        $pdo->prepare('UPDATE spot_audio_files SET is_final = 0 WHERE spot_id = ?')->execute([$spotId]);
        $stmtUpdate = $pdo->prepare("UPDATE spot_audio_files
            SET production_status = ?, client_audio_status = 'zaakceptowany_do_emisji',
                approved_by_user_id = ?, approved_at = NOW(), rejection_reason = NULL, is_final = 1,
                duration_seconds = COALESCE(duration_seconds, ?),
                bitrate = COALESCE(bitrate, ?),
                sample_rate = COALESCE(sample_rate, ?),
                channels = COALESCE(channels, ?)
            WHERE id = ?");
        $stmtUpdate->execute([
            audioProductionStatusDbValue('zaakceptowana'),
            (int)$currentUser['id'],
            $metadata['duration_seconds'],
            $metadata['bitrate'],
            $metadata['sample_rate'],
            $metadata['channels'],
            $audioId,
        ]);
        $pdo->prepare("UPDATE spoty SET client_audio_status = 'zaakceptowany_do_emisji' WHERE id = ?")->execute([$spotId]);
        $logMessage = 'Zaakceptowano audio dla spotu #' . $spotId;
        if ($durationCheckSkipped) {
            $logMessage .= ' (bez weryfikacji dlugosci)';
        }
        $pdo->prepare("INSERT INTO integrations_logs (user_id, type, request_id, message) VALUES (?, 'audio_approved', ?, ?)")
            ->execute([(int)$currentUser['id'], 'audio_' . $audioId, $logMessage]);
    } else {
        $stmtUpdate = $pdo->prepare("UPDATE spot_audio_files
            SET production_status = ?, client_audio_status = 'odrzucony_do_poprawy',
                approved_by_user_id = NULL, approved_at = NULL, rejection_reason = ?, is_final = 0
            WHERE id = ?");
        $stmtUpdate->execute([audioProductionStatusDbValue('odrzucona'), $reason, $audioId]);
        $pdo->prepare("UPDATE spoty SET client_audio_status = 'odrzucony_do_poprawy' WHERE id = ?")->execute([$spotId]);
        $pdo->prepare("INSERT INTO integrations_logs (user_id, type, request_id, message) VALUES (?, 'audio_rejected', ?, ?)")
            ->execute([(int)$currentUser['id'], 'audio_' . $audioId, 'Odrzucono audio dla spotu #' . $spotId . ': ' . $reason]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('audio_update_status: ' . $e->getMessage());
    $_SESSION['audio_upload_error'] = 'Nie udalo sie zapisac statusu audio.';
}

if (empty($_SESSION['audio_upload_error']) && $action === 'approve') {
    if ($campaignId > 0) {
        syncCampaignStatusFromAudio($pdo, $campaignId);
        audioLogCampaignActivity($pdo, $campaignId, 'Plik audio zaakceptowano do emisji dla spotu #' . $spotId, (int)$currentUser['id'], 'Plik zaakceptowany');
    }
    $eventResult = communicationLogEvent($pdo, [
        'event_type' => 'audio_final_selected',
        'idempotency_key' => communicationBuildIdempotencyKey('audio_final_selected', [$audioId, $campaignId]),
        'direction' => 'system',
        'status' => 'logged',
        'subject' => 'Wybrano finalna wersje audio',
        'body' => 'Spot #' . $spotId . ', plik audio #' . $audioId,
        'meta_json' => [
            'spot_id' => $spotId,
            'campaign_id' => $campaignId,
            'audio_id' => $audioId,
            'duration_check_skipped' => $durationCheckSkipped,
        ],
        'campaign_id' => $campaignId > 0 ? $campaignId : null,
        'spot_audio_file_id' => $audioId,
        'created_by_user_id' => (int)$currentUser['id'],
    ]);
    if (empty($eventResult['ok'])) {
        error_log('audio_update_status: communication event failed: ' . ($eventResult['error'] ?? 'unknown'));
    }
    $_SESSION['audio_upload_success'] = $durationCheckSkipped
        ? 'Plik audio zostal zaakceptowany (bez weryfikacji dlugosci).'
        : 'Plik audio zostal zaakceptowany.';
} elseif (empty($_SESSION['audio_upload_error'])) {
    if ($campaignId > 0) {
        audioLogCampaignActivity($pdo, $campaignId, 'Plik audio odrzucono do poprawy dla spotu #' . $spotId . ': ' . $reason, (int)$currentUser['id'], 'Plik odrzucony');
    }
    $_SESSION['audio_upload_success'] = 'Plik audio zostal odrzucony.';
}

header('Location: ' . $redirect);
exit;
