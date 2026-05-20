<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/document_status.php';
require_once __DIR__ . '/includes/document_pdf_versions.php';

ensureDocumentNumberingSettingsTable($pdo);
ensureSalesDocumentsTable($pdo);
ensureDocumentAcceptanceTables($pdo);
ensureDocumentPdfVersionsTable($pdo);

$pageTitle = 'Dokumenty sprzedażowe';
$documentTypes = ['order' => 'Zlecenie', 'annex' => 'Aneks'];
$statuses = documentStatusLabels();

function documentsH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function documentsMoney($value, string $currency): string
{
    return number_format((float)$value, 2, ',', ' ') . ' ' . $currency;
}

function documentsCsvValue($value): string
{
    return str_replace(["\r", "\n"], ' ', (string)$value);
}

$typeFilter = trim((string)($_GET['type'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$clientFilter = trim((string)($_GET['client'] ?? ''));
$campaignFilter = (int)($_GET['campaign_id'] ?? 0);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$onlyNoPdf = !empty($_GET['no_pdf']);
$onlyPending = !empty($_GET['pending_acceptance']);
$onlyRejected = !empty($_GET['rejected']);
$exportCsv = (string)($_GET['export'] ?? '') === 'csv';
$sort = (string)($_GET['sort'] ?? 'issue_date');
$dir = strtolower((string)($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

if (!isset($documentTypes[$typeFilter])) {
    $typeFilter = '';
}
if (!isset($statuses[$statusFilter])) {
    $statusFilter = '';
}
if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$sortColumns = [
    'issue_date' => 'd.issue_date',
    'document_number' => 'd.document_number',
    'client' => 'client_name',
    'status' => 'd.status',
    'gross_value' => 'd.gross_value',
];
$sortSql = $sortColumns[$sort] ?? 'd.issue_date';

$latestAcceptanceSql = "SELECT l1.*
    FROM document_acceptance_log l1
    INNER JOIN (
        SELECT document_id, MAX(id) AS max_id
        FROM document_acceptance_log
        GROUP BY document_id
    ) lm ON lm.max_id = l1.id";

$where = [];
$params = [];
if ($typeFilter !== '') {
    $where[] = 'd.document_type = :document_type';
    $params[':document_type'] = $typeFilter;
}
if ($statusFilter !== '') {
    $where[] = 'd.status = :status';
    $params[':status'] = $statusFilter;
}
if ($clientFilter !== '') {
    $where[] = 'k.nazwa_firmy LIKE :client';
    $params[':client'] = '%' . $clientFilter . '%';
}
if ($campaignFilter > 0) {
    $where[] = 'd.campaign_id = :campaign_id';
    $params[':campaign_id'] = $campaignFilter;
}
if ($dateFrom !== '') {
    $where[] = 'd.issue_date >= :date_from';
    $params[':date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'd.issue_date <= :date_to';
    $params[':date_to'] = $dateTo;
}
if ($onlyNoPdf) {
    $where[] = "(d.pdf_path IS NULL OR d.pdf_path = '')";
}
if ($onlyPending) {
    $where[] = "d.status = 'sent' AND (la.action IS NULL OR la.action NOT IN ('accepted','rejected'))";
}
if ($onlyRejected) {
    $where[] = "la.action = 'rejected'";
}

$baseFrom = "FROM documents d
    LEFT JOIN klienci k ON k.id = d.client_id
    LEFT JOIN ($latestAcceptanceSql) la ON la.document_id = d.id";
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$summarySql = "SELECT
        SUM(CASE WHEN d.status = 'draft' THEN 1 ELSE 0 END) AS draft_count,
        SUM(CASE WHEN d.status = 'issued' THEN 1 ELSE 0 END) AS issued_count,
        SUM(CASE WHEN d.status = 'sent' THEN 1 ELSE 0 END) AS sent_count,
        SUM(CASE WHEN d.status = 'accepted' THEN 1 ELSE 0 END) AS accepted_count,
        SUM(CASE WHEN d.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
        SUM(CASE WHEN la.action = 'rejected' THEN 1 ELSE 0 END) AS rejected_count,
        SUM(CASE WHEN d.pdf_path IS NULL OR d.pdf_path = '' THEN 1 ELSE 0 END) AS no_pdf_count,
        SUM(CASE WHEN d.status = 'sent' AND (la.action IS NULL OR la.action NOT IN ('accepted','rejected')) THEN 1 ELSE 0 END) AS pending_count
    $baseFrom";
$summary = $pdo->query($summarySql)->fetch(PDO::FETCH_ASSOC) ?: [];

$sql = "SELECT
        d.id, d.document_type, d.document_number, d.issue_date, d.status, d.gross_value, d.currency,
        d.title, d.pdf_path, d.campaign_id, k.nazwa_firmy AS client_name, la.action AS last_acceptance_action,
        la.created_at AS last_acceptance_at
    $baseFrom
    $whereSql
    ORDER BY $sortSql $dir, d.id DESC
    LIMIT 500";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

if ($exportCsv) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="dokumenty_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Numer', 'Typ', 'Klient', 'Kampania', 'Data wystawienia', 'Status', 'Brutto', 'Waluta', 'Ostatnia akceptacja']);
    foreach ($documents as $document) {
        fputcsv($out, [
            documentsCsvValue($document['document_number']),
            documentsCsvValue($documentTypes[$document['document_type']] ?? $document['document_type']),
            documentsCsvValue($document['client_name'] ?: '-'),
            (int)($document['campaign_id'] ?? 0) ?: '',
            documentsCsvValue($document['issue_date'] ?: ''),
            documentsCsvValue($statuses[$document['status']] ?? $document['status']),
            number_format((float)$document['gross_value'], 2, '.', ''),
            documentsCsvValue($document['currency']),
            documentsCsvValue($document['last_acceptance_action'] ?: ''),
        ]);
    }
    fclose($out);
    exit;
}

function documentsSortUrl(string $field): string
{
    $params = $_GET;
    $params['sort'] = $field;
    $params['dir'] = (($params['sort'] ?? '') === $field && strtolower((string)($params['dir'] ?? 'desc')) === 'asc') ? 'desc' : 'asc';
    unset($params['export']);
    return BASE_URL . '/dokumenty.php?' . http_build_query($params);
}

include __DIR__ . '/includes/header.php';
?>

<main class="container py-4" role="main" aria-labelledby="documents-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Dokumenty</p>
            <h1 id="documents-heading" class="h3 mb-2">Rejestr dokumentów sprzedażowych</h1>
            <p class="text-muted mb-0">Centralna lista zleceń i aneksów z numeracją dokumentów.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-primary btn-sm" href="<?= documentsH(BASE_URL . '/dokument_nowy_zlecenie.php') ?>">Nowe zlecenie</a>
            <a class="btn btn-outline-primary btn-sm" href="<?= documentsH(BASE_URL . '/dokument_nowy_aneks.php') ?>">Nowy aneks</a>
            <a class="btn btn-outline-secondary btn-sm" href="<?= documentsH(BASE_URL . '/dokumenty.php?' . http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>">CSV</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php
        $cards = [
            ['Robocze', $summary['draft_count'] ?? 0, 'secondary'],
            ['Wystawione', $summary['issued_count'] ?? 0, 'primary'],
            ['Wysłane', $summary['sent_count'] ?? 0, 'info'],
            ['Zaakceptowane', $summary['accepted_count'] ?? 0, 'success'],
            ['Anulowane', $summary['cancelled_count'] ?? 0, 'danger'],
            ['Odrzucone online', $summary['rejected_count'] ?? 0, 'warning'],
            ['Bez PDF', $summary['no_pdf_count'] ?? 0, 'dark'],
            ['Czekające na akceptację', $summary['pending_count'] ?? 0, 'light'],
        ];
        ?>
        <?php foreach ($cards as [$label, $value, $tone]): ?>
            <div class="col-6 col-lg-3">
                <div class="border rounded p-3 h-100">
                    <div class="small text-muted"><?= documentsH($label) ?></div>
                    <div class="h4 mb-0 text-<?= documentsH($tone === 'light' ? 'body' : $tone) ?>"><?= (int)$value ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="card shadow-sm mb-4" aria-label="Filtry dokumentów">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="type">Typ</label>
                    <select class="form-select" id="type" name="type">
                        <option value="">Wszystkie</option>
                        <?php foreach ($documentTypes as $value => $label): ?>
                            <option value="<?= documentsH($value) ?>" <?= $typeFilter === $value ? 'selected' : '' ?>><?= documentsH($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="">Wszystkie</option>
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= documentsH($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= documentsH($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="client">Klient</label>
                    <input class="form-control" id="client" name="client" value="<?= documentsH($clientFilter) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="campaign_id">ID kampanii</label>
                    <input class="form-control" type="number" min="1" id="campaign_id" name="campaign_id" value="<?= $campaignFilter > 0 ? (int)$campaignFilter : '' ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="date_from">Data od</label>
                    <input class="form-control" type="date" id="date_from" name="date_from" value="<?= documentsH($dateFrom) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="date_to">Data do</label>
                    <input class="form-control" type="date" id="date_to" name="date_to" value="<?= documentsH($dateTo) ?>">
                </div>
                <div class="col-md-6 d-flex flex-wrap gap-3">
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="no_pdf" value="1" <?= $onlyNoPdf ? 'checked' : '' ?>> Bez PDF</label>
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="pending_acceptance" value="1" <?= $onlyPending ? 'checked' : '' ?>> Czekające</label>
                    <label class="form-check"><input class="form-check-input" type="checkbox" name="rejected" value="1" <?= $onlyRejected ? 'checked' : '' ?>> Odrzucone</label>
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-outline-primary">Filtruj</button>
                    <a class="btn btn-outline-secondary" href="<?= documentsH(BASE_URL . '/dokumenty.php') ?>">Wyczyść</a>
                </div>
            </form>
        </div>
    </section>

    <section class="card shadow-sm" aria-label="Lista dokumentów">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th><a href="<?= documentsH(documentsSortUrl('document_number')) ?>">Numer</a></th>
                        <th>Typ</th>
                        <th><a href="<?= documentsH(documentsSortUrl('client')) ?>">Klient</a></th>
                        <th><a href="<?= documentsH(documentsSortUrl('issue_date')) ?>">Data</a></th>
                        <th><a href="<?= documentsH(documentsSortUrl('status')) ?>">Status</a></th>
                        <th class="text-end"><a href="<?= documentsH(documentsSortUrl('gross_value')) ?>">Brutto</a></th>
                        <th class="text-end">Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!$documents): ?>
                        <tr><td colspan="7" class="text-muted text-center py-4">Brak dokumentów dla wybranych filtrów.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($documents as $document): ?>
                        <?php
                        $typeLabel = $documentTypes[$document['document_type']] ?? $document['document_type'];
                        $statusLabel = $statuses[$document['status']] ?? $document['status'];
                        $isRejected = (string)($document['last_acceptance_action'] ?? '') === 'rejected';
                        ?>
                        <tr>
                            <td class="fw-semibold">
                                <?= documentsH($document['document_number']) ?>
                                <?php if (empty($document['pdf_path'])): ?><span class="badge text-bg-dark ms-1">bez PDF</span><?php endif; ?>
                            </td>
                            <td><?= documentsH($typeLabel) ?></td>
                            <td>
                                <?= documentsH($document['client_name'] ?: '-') ?>
                                <?php if (!empty($document['title'])): ?><div class="small text-muted"><?= documentsH($document['title']) ?></div><?php endif; ?>
                                <?php if ($isRejected): ?><span class="badge text-bg-warning mt-1">Odrzucony przez klienta</span><?php endif; ?>
                            </td>
                            <td><?= documentsH($document['issue_date'] ?: '-') ?></td>
                            <td><span class="badge <?= documentsH(documentStatusBadgeClass((string)$document['status'])) ?>"><?= documentsH($statusLabel) ?></span></td>
                            <td class="text-end"><?= documentsH(documentsMoney($document['gross_value'], (string)$document['currency'])) ?></td>
                            <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="<?= documentsH(BASE_URL . '/dokument_podglad.php?id=' . (int)$document['id']) ?>">Podgląd</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
