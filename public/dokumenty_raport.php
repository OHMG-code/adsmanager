<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/document_status.php';
require_once __DIR__ . '/includes/document_audit.php';

ensureSalesDocumentsTable($pdo);
ensureDocumentEmailLogTable($pdo);
ensureDocumentAcceptanceTables($pdo);
ensureDocumentAuditLogTable($pdo);

function docReportH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function docReportMoney($value): string
{
    return number_format((float)$value, 2, ',', ' ');
}

$types = ['order' => 'Zlecenie', 'annex' => 'Aneks'];
$dateFrom = trim((string)($_GET['date_from'] ?? date('Y-m-01')));
$dateTo = trim((string)($_GET['date_to'] ?? date('Y-m-t')));
$type = trim((string)($_GET['type'] ?? ''));
$client = trim((string)($_GET['client'] ?? ''));
$userId = (int)($_GET['user_id'] ?? 0);
$exportCsv = (string)($_GET['export'] ?? '') === 'csv';
if (!isset($types[$type])) {
    $type = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = date('Y-m-t');
}

$where = ['d.issue_date BETWEEN :date_from AND :date_to'];
$params = [':date_from' => $dateFrom, ':date_to' => $dateTo];
if ($type !== '') {
    $where[] = 'd.document_type = :type';
    $params[':type'] = $type;
}
if ($client !== '') {
    $where[] = 'k.nazwa_firmy LIKE :client';
    $params[':client'] = '%' . $client . '%';
}
if ($userId > 0) {
    $where[] = 'd.created_by = :user_id';
    $params[':user_id'] = $userId;
}
$whereSql = ' WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT d.status, COUNT(*) AS total, SUM(d.net_value) AS net_sum, SUM(d.gross_value) AS gross_sum
    FROM documents d
    LEFT JOIN klienci k ON k.id = d.client_id
    $whereSql
    GROUP BY d.status
    ORDER BY d.status");
$stmt->execute($params);
$byStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare("SELECT DATE_FORMAT(d.accepted_at, '%Y-%m') AS month_key, COUNT(*) AS total, SUM(d.gross_value) AS gross_sum
    FROM documents d
    LEFT JOIN klienci k ON k.id = d.client_id
    $whereSql AND d.status = 'accepted' AND d.accepted_at IS NOT NULL
    GROUP BY month_key
    ORDER BY month_key DESC");
$stmt->execute($params);
$acceptedMonthly = $stmt->fetchAll(PDO::FETCH_ASSOC);

$avgSql = "SELECT AVG(TIMESTAMPDIFF(HOUR, first_sent.sent_at, d.accepted_at)) AS avg_hours
    FROM documents d
    LEFT JOIN klienci k ON k.id = d.client_id
    INNER JOIN (
        SELECT document_id, MIN(sent_at) AS sent_at
        FROM document_email_log
        WHERE status = 'sent' AND sent_at IS NOT NULL
        GROUP BY document_id
    ) first_sent ON first_sent.document_id = d.id
    $whereSql AND d.status = 'accepted' AND d.accepted_at IS NOT NULL";
$stmt = $pdo->prepare($avgSql);
$stmt->execute($params);
$avgHours = $stmt->fetchColumn();

$stmt = $pdo->prepare("SELECT
        SUM(CASE WHEN la.action = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
        SUM(CASE WHEN a.event_type = 'document_acceptance_reminder_sent' THEN 1 ELSE 0 END) AS reminder_count
    FROM documents d
    LEFT JOIN klienci k ON k.id = d.client_id
    LEFT JOIN document_acceptance_log la ON la.document_id = d.id
    LEFT JOIN document_audit_log a ON a.document_id = d.id
    $whereSql");
$stmt->execute($params);
$eventCounts = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['rejected_count' => 0, 'reminder_count' => 0];

$stmt = $pdo->prepare("SELECT k.nazwa_firmy AS client_name, COUNT(*) AS total, SUM(d.gross_value) AS gross_sum
    FROM documents d
    LEFT JOIN klienci k ON k.id = d.client_id
    $whereSql
    GROUP BY d.client_id, k.nazwa_firmy
    ORDER BY gross_sum DESC
    LIMIT 20");
$stmt->execute($params);
$topClients = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($exportCsv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="raport_dokumentow_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Status', 'Liczba', 'Netto', 'Brutto']);
    foreach ($byStatus as $row) {
        fputcsv($out, [$row['status'], $row['total'], $row['net_sum'], $row['gross_sum']]);
    }
    fputcsv($out, []);
    fputcsv($out, ['Klient', 'Liczba', 'Brutto']);
    foreach ($topClients as $row) {
        fputcsv($out, [$row['client_name'], $row['total'], $row['gross_sum']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Raport dokumentów';
include __DIR__ . '/includes/header.php';
?>

<main class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Dokumenty</p>
            <h1 class="h3 mb-2">Raport dokumentów</h1>
            <p class="text-muted mb-0">Statusy, wartości, akceptacje i aktywność klientów.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= docReportH(BASE_URL . '/dokumenty_raport.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">CSV</a>
    </div>

    <section class="card shadow-sm mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3"><label class="form-label">Od</label><input class="form-control" type="date" name="date_from" value="<?= docReportH($dateFrom) ?>"></div>
                <div class="col-md-3"><label class="form-label">Do</label><input class="form-control" type="date" name="date_to" value="<?= docReportH($dateTo) ?>"></div>
                <div class="col-md-2"><label class="form-label">Typ</label><select class="form-select" name="type"><option value="">Wszystkie</option><?php foreach ($types as $key => $label): ?><option value="<?= docReportH($key) ?>" <?= $type === $key ? 'selected' : '' ?>><?= docReportH($label) ?></option><?php endforeach; ?></select></div>
                <div class="col-md-2"><label class="form-label">Klient</label><input class="form-control" name="client" value="<?= docReportH($client) ?>"></div>
                <div class="col-md-2"><label class="form-label">Użytkownik ID</label><input class="form-control" type="number" min="1" name="user_id" value="<?= $userId > 0 ? (int)$userId : '' ?>"></div>
                <div class="col-12"><button class="btn btn-outline-primary" type="submit">Filtruj</button></div>
            </form>
        </div>
    </section>

    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="border rounded p-3"><div class="small text-muted">Średni czas wysyłka -> akceptacja</div><div class="h4 mb-0"><?= $avgHours !== null ? docReportH(number_format((float)$avgHours / 24, 1, ',', ' ') . ' dni') : '-' ?></div></div></div>
        <div class="col-md-4"><div class="border rounded p-3"><div class="small text-muted">Odrzucenia</div><div class="h4 mb-0"><?= (int)($eventCounts['rejected_count'] ?? 0) ?></div></div></div>
        <div class="col-md-4"><div class="border rounded p-3"><div class="small text-muted">Przypomnienia</div><div class="h4 mb-0"><?= (int)($eventCounts['reminder_count'] ?? 0) ?></div></div></div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6"><section class="card shadow-sm"><div class="card-body"><h2 class="h5">Według statusu</h2><table class="table table-sm"><thead><tr><th>Status</th><th>Liczba</th><th>Netto</th><th>Brutto</th></tr></thead><tbody><?php foreach ($byStatus as $row): ?><tr><td><?= docReportH($row['status']) ?></td><td><?= (int)$row['total'] ?></td><td><?= docReportH(docReportMoney($row['net_sum'])) ?></td><td><?= docReportH(docReportMoney($row['gross_sum'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section></div>
        <div class="col-lg-6"><section class="card shadow-sm"><div class="card-body"><h2 class="h5">Zaakceptowane miesięcznie</h2><table class="table table-sm"><thead><tr><th>Miesiąc</th><th>Liczba</th><th>Brutto</th></tr></thead><tbody><?php foreach ($acceptedMonthly as $row): ?><tr><td><?= docReportH($row['month_key']) ?></td><td><?= (int)$row['total'] ?></td><td><?= docReportH(docReportMoney($row['gross_sum'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section></div>
        <div class="col-12"><section class="card shadow-sm"><div class="card-body"><h2 class="h5">Najaktywniejsi klienci</h2><table class="table table-sm"><thead><tr><th>Klient</th><th>Liczba</th><th>Brutto</th></tr></thead><tbody><?php foreach ($topClients as $row): ?><tr><td><?= docReportH($row['client_name'] ?: '-') ?></td><td><?= (int)$row['total'] ?></td><td><?= docReportH(docReportMoney($row['gross_sum'])) ?></td></tr><?php endforeach; ?></tbody></table></div></section></div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
