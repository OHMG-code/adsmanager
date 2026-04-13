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

ensureKampanieOwnershipColumns($pdo);
ensureKampanieSalesValueColumn($pdo);
$cols = getTableColumns($pdo, 'kampanie');
if (!$cols) {
    echo json_encode(['success' => true, 'items' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$limit = max(1, min(500, $limit));

$where = ['1=1'];
$params = [];
[$ownerWhere, $ownerParams] = ownerConstraint($cols, $currentUser, 'k');
if ($ownerWhere !== '') {
    $where[] = $ownerWhere;
    $params = array_merge($params, $ownerParams);
}

$status = trim((string)($_GET['status'] ?? ''));
if ($status !== '' && hasColumn($cols, 'status')) {
    $where[] = 'k.status = :status';
    $params[':status'] = $status;
}

$realization = trim((string)($_GET['realization_status'] ?? ''));
if ($realization !== '' && hasColumn($cols, 'realization_status')) {
    $where[] = 'k.realization_status = :realization_status';
    $params[':realization_status'] = $realization;
}

$from = trim((string)($_GET['date_from'] ?? ''));
if ($from !== '' && hasColumn($cols, 'created_at')) {
    $where[] = 'k.created_at >= :date_from';
    $params[':date_from'] = $from . ' 00:00:00';
}

$to = trim((string)($_GET['date_to'] ?? ''));
if ($to !== '' && hasColumn($cols, 'created_at')) {
    $where[] = 'k.created_at <= :date_to';
    $params[':date_to'] = $to . ' 23:59:59';
}

$select = [
    'k.id',
    hasColumn($cols, 'klient_id') ? 'k.klient_id' : 'NULL AS klient_id',
    hasColumn($cols, 'klient_nazwa') ? 'k.klient_nazwa' : "'' AS klient_nazwa",
    hasColumn($cols, 'status') ? 'k.status' : "'' AS status",
    hasColumn($cols, 'realization_status') ? 'k.realization_status' : "'' AS realization_status",
    hasColumn($cols, 'data_start') ? 'k.data_start' : 'NULL AS data_start',
    hasColumn($cols, 'data_koniec') ? 'k.data_koniec' : 'NULL AS data_koniec',
    hasColumn($cols, 'owner_user_id') ? 'k.owner_user_id' : 'NULL AS owner_user_id',
    hasColumn($cols, 'source_lead_id') ? 'k.source_lead_id' : 'NULL AS source_lead_id',
    hasColumn($cols, 'wartosc_netto') ? 'k.wartosc_netto' : 'NULL AS wartosc_netto',
    hasColumn($cols, 'created_at') ? 'k.created_at' : 'NULL AS created_at',
];

try {
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM kampanie k WHERE ' . implode(' AND ', $where) . ' ORDER BY k.id DESC LIMIT ' . $limit;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode(['success' => true, 'items' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('api/kampanie: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Nie udało się pobrać kampanii.'], JSON_UNESCAPED_UNICODE);
}
exit;
