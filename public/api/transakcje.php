<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Wymagane logowanie.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Brak sesji użytkownika.'], JSON_UNESCAPED_UNICODE);
    exit;
}

ensureTransactionsTable($pdo);
ensureKampanieOwnershipColumns($pdo);
ensureLeadColumns($pdo);
ensureClientLeadColumns($pdo);

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$limit = max(1, min(500, $limit));
$params = [];
$where = ['1=1'];

$status = trim((string)($_GET['status'] ?? ''));
if ($status !== '') {
    $where[] = 't.status = :status';
    $params[':status'] = $status;
}

$from = trim((string)($_GET['date_from'] ?? ''));
if ($from !== '') {
    $where[] = 'COALESCE(t.won_at, t.created_at) >= :date_from';
    $params[':date_from'] = $from . ' 00:00:00';
}

$to = trim((string)($_GET['date_to'] ?? ''));
if ($to !== '') {
    $where[] = 'COALESCE(t.won_at, t.created_at) <= :date_to';
    $params[':date_to'] = $to . ' 23:59:59';
}

$role = normalizeRole($currentUser);
if ($role === 'Handlowiec' && !isAdminOverride($currentUser)) {
    $where[] = 'COALESCE(NULLIF(t.owner_user_id, 0), NULLIF(k.owner_user_id, 0), NULLIF(l.owner_user_id, 0), NULLIF(l.assigned_user_id, 0), NULLIF(c.owner_user_id, 0), NULLIF(c.assigned_user_id, 0)) = :current_user_id';
    $params[':current_user_id'] = (int)$currentUser['id'];
}

try {
    $sql = "SELECT
            t.id, t.campaign_id, t.client_id, t.source_lead_id, t.owner_user_id,
            t.value_netto, t.value_brutto, t.status, t.won_at, t.created_at, t.updated_at
        FROM transactions t
        LEFT JOIN kampanie k ON k.id = t.campaign_id
        LEFT JOIN leady l ON l.id = COALESCE(NULLIF(t.source_lead_id, 0), NULLIF(k.source_lead_id, 0))
        LEFT JOIN klienci c ON c.id = COALESCE(NULLIF(t.client_id, 0), NULLIF(k.klient_id, 0), NULLIF(l.client_id, 0))
        WHERE " . implode(' AND ', $where) . "
        ORDER BY COALESCE(t.won_at, t.created_at) DESC
        LIMIT {$limit}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('api/transakcje: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Nie udało się pobrać transakcji.'], JSON_UNESCAPED_UNICODE);
}
exit;
