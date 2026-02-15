<?php
require_once __DIR__ . '/../includes/config.php';
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Wymagane logowanie.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../includes/lead_pipeline_helpers.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nie można zweryfikować użytkownika.'], JSON_UNESCAPED_UNICODE);
    exit;
}

ensureLeadColumns($pdo);
ensureLeadActivityTable($pdo);

$payload = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$leadId = isset($payload['lead_id']) ? (int)$payload['lead_id'] : 0;
$newStatusRaw = trim((string)($payload['new_status'] ?? ''));
if ($leadId <= 0 || $newStatusRaw === '') {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe dane wejściowe.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$newStatus = normalizeLeadStatus($newStatusRaw);
if (!in_array($newStatus, leadStandardStatuses(), true)) {
    echo json_encode(['success' => false, 'error' => 'Nieobsługiwany status.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$leadCols = getTableColumns($pdo, 'leady');
$stmt = $pdo->prepare("SELECT id, owner_user_id, status FROM leady WHERE id = ? LIMIT 1");
$stmt->execute([$leadId]);
$lead = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$lead) {
    echo json_encode(['success' => false, 'error' => 'Lead nie został znaleziony.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (normalizeRole($currentUser) === 'Handlowiec') {
    $ownerId = (int)($lead['owner_user_id'] ?? 0);
    if ($ownerId !== (int)$currentUser['id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Brak uprawnień do tego leada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$oldStatus = $lead['status'] ?? '';
if ($oldStatus === $newStatus) {
    echo json_encode(['success' => true, 'data' => ['status' => $newStatus]]);
    exit;
}

try {
    $pdo->beginTransaction();
    $updateParts = ['status = :status'];
    $params = [':status' => $newStatus, ':id' => $leadId];

    if ($newStatus === 'Oferta wysłana') {
        if (hasColumn($leadCols, 'next_action')) {
            $updateParts[] = 'next_action = :next_action';
            $params[':next_action'] = 'Follow-up po ofercie';
        }
        if (hasColumn($leadCols, 'next_action_at')) {
            $updateParts[] = 'next_action_at = DATE_ADD(NOW(), INTERVAL 2 DAY)';
        }
        if (hasColumn($leadCols, 'next_action_date')) {
            $updateParts[] = 'next_action_date = DATE(DATE_ADD(NOW(), INTERVAL 2 DAY))';
        }
    }

    $stmtUpdate = $pdo->prepare("UPDATE leady SET " . implode(', ', $updateParts) . " WHERE id = :id");
    $stmtUpdate->execute($params);

    $stmtActivity = $pdo->prepare("INSERT INTO leady_aktywnosci (lead_id, user_id, typ, opis) VALUES (:lead_id, :user_id, 'status_change', :opis)");
    $stmtActivity->execute([
        ':lead_id' => $leadId,
        ':user_id' => (int)$currentUser['id'],
        ':opis' => 'Status: ' . normalizeLeadStatus($oldStatus) . ' → ' . $newStatus,
    ]);

    if ($newStatus === 'Oferta wysłana') {
        $stmtOffer = $pdo->prepare("INSERT INTO leady_aktywnosci (lead_id, user_id, typ, opis) VALUES (:lead_id, :user_id, 'offer_sent', :opis)");
        $stmtOffer->execute([
            ':lead_id' => $leadId,
            ':user_id' => (int)$currentUser['id'],
            ':opis' => 'Wysłano ofertę (zmiana statusu).',
        ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'data' => ['status' => $newStatus]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('lead_update_status: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Nie udało się zaktualizować statusu.'], JSON_UNESCAPED_UNICODE);
}
exit;


