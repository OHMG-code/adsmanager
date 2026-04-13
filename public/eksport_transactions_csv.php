<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

ensureTransactionsTable($pdo);
ensureKampanieOwnershipColumns($pdo);
ensureLeadColumns($pdo);
ensureClientLeadColumns($pdo);

$where = ['1=1'];
$params = [];

$status = trim((string)($_GET['status'] ?? ''));
if ($status !== '') {
    $where[] = 't.status = :status';
    $params[':status'] = $status;
}

$from = trim((string)($_GET['date_from'] ?? ''));
if ($from !== '') {
    $where[] = 'COALESCE(t.won_at, t.created_at) >= :from';
    $params[':from'] = $from . ' 00:00:00';
}

$to = trim((string)($_GET['date_to'] ?? ''));
if ($to !== '') {
    $where[] = 'COALESCE(t.won_at, t.created_at) <= :to';
    $params[':to'] = $to . ' 23:59:59';
}

$role = normalizeRole($currentUser);
if ($role === 'Handlowiec' && !isAdminOverride($currentUser)) {
    $where[] = 'COALESCE(NULLIF(t.owner_user_id, 0), NULLIF(k.owner_user_id, 0), NULLIF(l.owner_user_id, 0), NULLIF(l.assigned_user_id, 0), NULLIF(c.owner_user_id, 0), NULLIF(c.assigned_user_id, 0)) = :current_user_id';
    $params[':current_user_id'] = (int)$currentUser['id'];
}

$sql = "SELECT
        t.id, t.campaign_id, t.client_id, t.source_lead_id, t.owner_user_id,
        t.value_netto, t.value_brutto, t.status, t.won_at, t.created_at, t.updated_at
    FROM transactions t
    LEFT JOIN kampanie k ON k.id = t.campaign_id
    LEFT JOIN leady l ON l.id = COALESCE(NULLIF(t.source_lead_id, 0), NULLIF(k.source_lead_id, 0))
    LEFT JOIN klienci c ON c.id = COALESCE(NULLIF(t.client_id, 0), NULLIF(k.klient_id, 0), NULLIF(l.client_id, 0))
    WHERE " . implode(' AND ', $where) . "
    ORDER BY COALESCE(t.won_at, t.created_at) DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filename = 'transactions_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Campaign ID', 'Client ID', 'Source lead ID', 'Owner user', 'Value netto', 'Value brutto', 'Status', 'Won at', 'Created at', 'Updated at'], ';');
foreach ($rows as $row) {
    fputcsv($out, [
        (int)($row['id'] ?? 0),
        (string)($row['campaign_id'] ?? ''),
        (string)($row['client_id'] ?? ''),
        (string)($row['source_lead_id'] ?? ''),
        (string)($row['owner_user_id'] ?? ''),
        (string)($row['value_netto'] ?? ''),
        (string)($row['value_brutto'] ?? ''),
        (string)($row['status'] ?? ''),
        (string)($row['won_at'] ?? ''),
        (string)($row['created_at'] ?? ''),
        (string)($row['updated_at'] ?? ''),
    ], ';');
}
fclose($out);
exit;
