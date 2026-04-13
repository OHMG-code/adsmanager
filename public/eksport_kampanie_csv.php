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

ensureKampanieOwnershipColumns($pdo);
ensureKampanieSalesValueColumn($pdo);
$cols = getTableColumns($pdo, 'kampanie');

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

$from = trim((string)($_GET['date_from'] ?? ''));
if ($from !== '' && hasColumn($cols, 'created_at')) {
    $where[] = 'k.created_at >= :from';
    $params[':from'] = $from . ' 00:00:00';
}

$to = trim((string)($_GET['date_to'] ?? ''));
if ($to !== '' && hasColumn($cols, 'created_at')) {
    $where[] = 'k.created_at <= :to';
    $params[':to'] = $to . ' 23:59:59';
}

$sql = "SELECT
        k.id,
        k.klient_nazwa,
        k.status,
        k.realization_status,
        k.data_start,
        k.data_koniec,
        k.owner_user_id,
        k.source_lead_id,
        k.wartosc_netto,
        k.razem_netto,
        k.razem_brutto,
        k.created_at
    FROM kampanie k
    WHERE " . implode(' AND ', $where) . "
    ORDER BY k.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

$filename = 'kampanie_' . date('Ymd_His') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo "\xEF\xBB\xBF";
$out = fopen('php://output', 'w');
fputcsv($out, ['ID', 'Klient', 'Status', 'Realization status', 'Data start', 'Data koniec', 'Owner user', 'Source lead', 'Wartosc netto', 'Razem netto', 'Razem brutto', 'Created at'], ';');
foreach ($rows as $row) {
    fputcsv($out, [
        (int)($row['id'] ?? 0),
        (string)($row['klient_nazwa'] ?? ''),
        (string)($row['status'] ?? ''),
        (string)($row['realization_status'] ?? ''),
        (string)($row['data_start'] ?? ''),
        (string)($row['data_koniec'] ?? ''),
        (string)($row['owner_user_id'] ?? ''),
        (string)($row['source_lead_id'] ?? ''),
        (string)($row['wartosc_netto'] ?? ''),
        (string)($row['razem_netto'] ?? ''),
        (string)($row['razem_brutto'] ?? ''),
        (string)($row['created_at'] ?? ''),
    ], ';');
}
fclose($out);
exit;
