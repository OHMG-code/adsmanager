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
$action = trim((string)($payload['action'] ?? ''));
$days = isset($payload['days']) ? (int)$payload['days'] : 0;

if ($leadId <= 0 || !in_array($action, ['done', 'snooze'], true)) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidłowe dane wejściowe.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$leadCols = getTableColumns($pdo, 'leady');
$stmt = $pdo->prepare("SELECT id, owner_user_id FROM leady WHERE id = ? LIMIT 1");
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

try {
    $pdo->beginTransaction();
    $updateParts = [];
    $params = [':id' => $leadId];

    if ($action === 'done') {
        if (hasColumn($leadCols, 'next_action_at')) {
            $updateParts[] = 'next_action_at = NULL';
        }
        if (hasColumn($leadCols, 'next_action_date')) {
            $updateParts[] = 'next_action_date = NULL';
        }
    } else {
        $days = max(1, $days);
        if (hasColumn($leadCols, 'next_action_at')) {
            $updateParts[] = 'next_action_at = DATE_ADD(NOW(), INTERVAL ' . $days . ' DAY)';
        }
        if (hasColumn($leadCols, 'next_action_date')) {
            $updateParts[] = 'next_action_date = DATE(DATE_ADD(NOW(), INTERVAL ' . $days . ' DAY))';
        }
    }

    if ($updateParts) {
        $stmtUpdate = $pdo->prepare("UPDATE leady SET " . implode(', ', $updateParts) . " WHERE id = :id");
        $stmtUpdate->execute($params);
    }

    $stmtActivity = $pdo->prepare("INSERT INTO leady_aktywnosci (lead_id, user_id, typ, opis) VALUES (:lead_id, :user_id, :typ, :opis)");
    $stmtActivity->execute([
        ':lead_id' => $leadId,
        ':user_id' => (int)$currentUser['id'],
        ':typ' => 'followup_done',
        ':opis' => $action === 'done' ? 'Oznaczono follow-up jako wykonany.' : ('Przesunięto follow-up o ' . $days . ' dni.'),
    ]);

    $pdo->commit();
    echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('lead_followup_update: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Nie udało się zaktualizować follow-up.'], JSON_UNESCAPED_UNICODE);
}
exit;


