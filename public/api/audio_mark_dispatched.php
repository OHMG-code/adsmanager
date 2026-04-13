<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../includes/emisje_helpers.php';
require_once __DIR__ . '/../includes/briefs.php';
require_once __DIR__ . '/../includes/communication_events.php';
require_once __DIR__ . '/../includes/communication_templates.php';
require_once __DIR__ . '/../includes/task_automation.php';

requireLogin();

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

ensureSpotAudioFilesTable($pdo);
ensureSpotAudioDispatchesTable($pdo);
ensureSpotAudioDispatchItemsTable($pdo);
ensureSpotColumns($pdo);
ensureIntegrationsLogsTable($pdo);
ensureCommunicationEventsTable($pdo);
ensureNotificationsTable($pdo);
ensureCrmTasksTable($pdo);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['audio_upload_error'] = 'Nieprawidlowa metoda zadania.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    $_SESSION['audio_upload_error'] = 'Niepoprawny token formularza.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

$spotId = isset($_POST['spot_id']) ? (int)$_POST['spot_id'] : 0;
if ($spotId <= 0) {
    $_SESSION['audio_upload_error'] = 'Nieprawidlowy identyfikator spotu.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

$redirect = BASE_URL . '/edytuj_spot.php?id=' . $spotId;

if (!canAccessSpot($pdo, $spotId, $currentUser)) {
    $_SESSION['audio_upload_error'] = 'Brak uprawnien do tego spotu.';
    header('Location: ' . $redirect);
    exit;
}

$audioIdsRaw = $_POST['audio_ids'] ?? [];
$audioIds = [];
if (is_array($audioIdsRaw)) {
    foreach ($audioIdsRaw as $idRaw) {
        $idVal = (int)$idRaw;
        if ($idVal > 0) {
            $audioIds[$idVal] = $idVal;
        }
    }
}
$audioIds = array_values($audioIds);

if (!$audioIds) {
    $_SESSION['audio_upload_error'] = 'Wybierz co najmniej jedna wersje audio do oznaczenia jako wyslana.';
    header('Location: ' . $redirect);
    exit;
}

$placeholders = implode(',', array_fill(0, count($audioIds), '?'));
$stmtSpot = $pdo->prepare('SELECT kampania_id FROM spoty WHERE id = ? LIMIT 1');
$stmtSpot->execute([$spotId]);
$campaignId = (int)($stmtSpot->fetchColumn() ?: 0);

$stmtFiles = $pdo->prepare("SELECT id, production_status
    FROM spot_audio_files
    WHERE spot_id = ?
      AND id IN ($placeholders)");
$stmtFiles->execute(array_merge([$spotId], $audioIds));
$files = $stmtFiles->fetchAll(PDO::FETCH_ASSOC) ?: [];
if (!$files) {
    $_SESSION['audio_upload_error'] = 'Nie znaleziono wskazanych wersji audio dla tego spotu.';
    header('Location: ' . $redirect);
    exit;
}

$note = trim((string)($_POST['dispatch_note'] ?? ''));
if ($note === '') {
    $note = null;
}

$dispatchId = 0;
try {
    $pdo->beginTransaction();

    $stmtDispatch = $pdo->prepare("INSERT INTO spot_audio_dispatches
        (campaign_id, spot_id, dispatched_by_user_id, dispatched_at, channel, note)
        VALUES (?, ?, ?, NOW(), 'manual', ?)");
    $stmtDispatch->execute([
        $campaignId > 0 ? $campaignId : 0,
        $spotId,
        (int)$currentUser['id'],
        $note,
    ]);
    $dispatchId = (int)$pdo->lastInsertId();

    $stmtItem = $pdo->prepare("INSERT INTO spot_audio_dispatch_items (dispatch_id, spot_audio_file_id)
        VALUES (?, ?)");
    $stmtUpdateDispatched = $pdo->prepare("UPDATE spot_audio_files
        SET production_status = ?
        WHERE id = ?");
    $statusDispatchedDb = audioProductionStatusDbValue('wyslana_do_klienta');

    foreach ($files as $file) {
        $fileId = (int)($file['id'] ?? 0);
        if ($fileId <= 0) {
            continue;
        }
        $stmtItem->execute([$dispatchId, $fileId]);

        $canonicalStatus = normalizeAudioProductionStatus((string)($file['production_status'] ?? ''));
        if (in_array($canonicalStatus, ['robocza', 'wyslana_do_klienta'], true)) {
            $stmtUpdateDispatched->execute([$statusDispatchedDb, $fileId]);
        }
    }

    if ($campaignId > 0 && tableExists($pdo, 'kampanie')) {
        $stmtCampaign = $pdo->prepare('SELECT realization_status FROM kampanie WHERE id = :id LIMIT 1');
        $stmtCampaign->execute([':id' => $campaignId]);
        $currentStatus = trim((string)($stmtCampaign->fetchColumn() ?: ''));
        if (!in_array($currentStatus, ['zaakceptowany_spot', 'do_emisji', 'zakonczony'], true)) {
            $stmtSetStatus = $pdo->prepare("UPDATE kampanie SET realization_status = 'wersje_wyslane' WHERE id = :id");
            $stmtSetStatus->execute([':id' => $campaignId]);
        }
    }

    $stmtLog = $pdo->prepare("INSERT INTO integrations_logs (user_id, type, request_id, message)
        VALUES (?, 'audio_dispatched', ?, ?)");
    $stmtLog->execute([
        (int)$currentUser['id'],
        'dispatch_' . $dispatchId,
        'Oznaczono wysylke wersji audio dla spotu #' . $spotId,
    ]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('audio_mark_dispatched: ' . $e->getMessage());
    $_SESSION['audio_upload_error'] = 'Nie udalo sie zapisac wysylki wersji audio.';
}

if (empty($_SESSION['audio_upload_error']) && $dispatchId > 0) {
    $dispatchKey = communicationBuildIdempotencyKey('audio_dispatched', [$dispatchId]);
    $dispatchComm = communicationLogEvent($pdo, [
        'event_type' => 'audio_dispatched',
        'idempotency_key' => $dispatchKey,
        'direction' => 'outbound_client',
        'status' => 'logged',
        'subject' => 'Wersje audio kampanii #' . $campaignId . ' oznaczone jako wysłane',
        'body' => 'Spot #' . $spotId . ', liczba wersji: ' . count($files),
        'meta_json' => [
            'dispatch_id' => $dispatchId,
            'spot_id' => $spotId,
            'campaign_id' => $campaignId,
            'versions_count' => count($files),
            'channel' => 'manual',
            'note' => $note,
        ],
        'campaign_id' => $campaignId > 0 ? $campaignId : null,
        'dispatch_id' => $dispatchId,
        'created_by_user_id' => (int)$currentUser['id'],
    ]);
    if (empty($dispatchComm['ok'])) {
        error_log('audio_mark_dispatched: communication event failed: ' . ($dispatchComm['error'] ?? 'unknown'));
    }

    $taskResult = createEventTask($pdo, 'audio_dispatched', [
        'campaign_id' => $campaignId,
        'dispatch_id' => $dispatchId,
        'communication_event_id' => (int)($dispatchComm['id'] ?? 0),
        'event_at' => date('Y-m-d H:i:s'),
        'actor_user_id' => (int)$currentUser['id'],
    ]);
    if (empty($taskResult['ok'])) {
        error_log('audio_mark_dispatched: task creation failed: ' . ($taskResult['error'] ?? 'unknown'));
    }

    if ($campaignId > 0) {
        $users = communicationResolveCampaignUsers($pdo, $campaignId);
        $tmpl = communicationTemplateInternal('audio_dispatched', [
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
                'audio_dispatched',
                (string)$tmpl['body'],
                'edytuj_spot.php?id=' . $spotId
            );
        }
    }

    $_SESSION['audio_upload_success'] = 'Wersje audio zostaly oznaczone jako wyslane do klienta.';
}

header('Location: ' . $redirect);
exit;
