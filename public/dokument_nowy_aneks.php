<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/document_numbering.php';
require_once __DIR__ . '/includes/document_audit.php';

ensureDocumentNumberingSettingsTable($pdo);
ensureSalesDocumentsTable($pdo);
ensureDocumentAnnexDetailsTable($pdo);
ensureDocumentAuditLogTable($pdo);

$pageTitle = 'Nowy aneks do zlecenia';
$csrfToken = getCsrfToken();
$errors = [];
$statuses = [
    'draft' => 'Roboczy',
    'issued' => 'Wystawiony',
    'sent' => 'Wyslany',
    'accepted' => 'Zaakceptowany',
    'cancelled' => 'Anulowany',
];

function annexH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function annexClean(string $key, int $maxLength = 255): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    $value = preg_replace('/[[:cntrl:]]+/u', ' ', $value);
    $value = trim((string)$value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
}

function annexDateOrNull(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function annexDecimal(string $key): float
{
    $value = str_replace([' ', ','], ['', '.'], (string)($_POST[$key] ?? '0'));
    return is_numeric($value) ? (float)$value : 0.0;
}

function annexMoney($value, string $currency): string
{
    return number_format((float)$value, 2, ',', ' ') . ' ' . $currency;
}

$companyProfile = $pdo->query('SELECT * FROM company_profile ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
$defaultVatRate = (float)($companyProfile['default_vat_rate'] ?? 23.0);

$stmt = $pdo->query("SELECT
        d.id,
        d.document_number,
        d.client_id,
        d.campaign_id,
        d.company_profile_id,
        d.issue_date,
        d.valid_from,
        d.valid_to,
        d.net_value,
        d.vat_rate,
        d.gross_value,
        d.currency,
        d.title,
        k.nazwa_firmy AS client_name
    FROM documents d
    LEFT JOIN klienci k ON k.id = d.client_id
    WHERE d.document_type = 'order'
    ORDER BY d.issue_date DESC, d.id DESC
    LIMIT 500");
$baseOrders = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
if ($stmt) {
    $stmt->closeCursor();
}

$form = [
    'base_document_id' => (string)($_POST['base_document_id'] ?? ($_GET['base_document_id'] ?? '')),
    'issue_date' => (string)($_POST['issue_date'] ?? date('Y-m-d')),
    'new_valid_from' => (string)($_POST['new_valid_from'] ?? ''),
    'new_valid_to' => (string)($_POST['new_valid_to'] ?? ''),
    'title' => (string)($_POST['title'] ?? 'Aneks do zlecenia emisji reklamy'),
    'change_description' => (string)($_POST['change_description'] ?? ''),
    'net_value' => (string)($_POST['net_value'] ?? '0.00'),
    'vat_rate' => (string)($_POST['vat_rate'] ?? number_format($defaultVatRate, 2, '.', '')),
    'currency' => (string)($_POST['currency'] ?? 'PLN'),
    'status' => (string)($_POST['status'] ?? 'draft'),
    'notes' => (string)($_POST['notes'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Niepoprawny token CSRF.';
    }

    $baseDocumentId = (int)($_POST['base_document_id'] ?? 0);
    $issueDate = annexDateOrNull((string)($_POST['issue_date'] ?? ''));
    $newValidFrom = annexDateOrNull((string)($_POST['new_valid_from'] ?? ''));
    $newValidTo = annexDateOrNull((string)($_POST['new_valid_to'] ?? ''));
    $title = annexClean('title');
    $changeDescription = annexClean('change_description', 8000);
    $netValue = annexDecimal('net_value');
    $vatRate = annexDecimal('vat_rate');
    $vatValue = round($netValue * ($vatRate / 100), 2);
    $grossValue = round($netValue + $vatValue, 2);
    $currency = strtoupper(annexClean('currency', 3)) ?: 'PLN';
    $status = annexClean('status', 30);
    $notes = annexClean('notes', 4000);

    $baseDocument = null;
    if ($baseDocumentId <= 0) {
        $errors[] = 'Zlecenie bazowe jest wymagane.';
    } else {
        $stmt = $pdo->prepare("SELECT * FROM documents WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $baseDocumentId]);
        $baseDocument = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        if (!$baseDocument) {
            $errors[] = 'Wybrane zlecenie bazowe nie istnieje.';
        } elseif ((string)$baseDocument['document_type'] !== 'order') {
            $errors[] = 'Aneks mozna utworzyc tylko do dokumentu typu zlecenie.';
        }
    }
    if ($issueDate === null) {
        $errors[] = 'Data wystawienia aneksu jest wymagana.';
    }
    if ($newValidFrom === null || $newValidTo === null) {
        $errors[] = 'Nowy okres emisji od/do jest wymagany.';
    }
    if ($newValidFrom !== null && $newValidTo !== null && $newValidFrom > $newValidTo) {
        $errors[] = 'Nowa data konca emisji nie moze byc wczesniejsza niz data poczatku.';
    }
    if ($changeDescription === '') {
        $errors[] = 'Opis zmiany jest wymagany.';
    }
    if ($netValue < 0) {
        $errors[] = 'Wartosc netto aneksu nie moze byc ujemna.';
    }
    if ($vatRate < 0) {
        $errors[] = 'Stawka VAT nie moze byc ujemna.';
    }
    if (!array_key_exists($status, $statuses)) {
        $errors[] = 'Niepoprawny status dokumentu.';
    }

    if (!$errors && $baseDocument) {
        try {
            $pdo->beginTransaction();
            $documentNumber = generateDocumentNumber($pdo, 'annex', new DateTimeImmutable($issueDate));

            $stmt = $pdo->prepare("INSERT INTO documents
                (document_type, document_number, related_document_id, client_id, campaign_id, company_profile_id, issue_date, valid_from, valid_to,
                 status, title, net_value, vat_rate, vat_value, gross_value, currency, created_by, notes)
                VALUES
                ('annex', :document_number, :related_document_id, :client_id, :campaign_id, :company_profile_id, :issue_date, :valid_from, :valid_to,
                 :status, :title, :net_value, :vat_rate, :vat_value, :gross_value, :currency, :created_by, :notes)");
            $stmt->execute([
                ':document_number' => $documentNumber,
                ':related_document_id' => (int)$baseDocument['id'],
                ':client_id' => $baseDocument['client_id'] !== null ? (int)$baseDocument['client_id'] : null,
                ':campaign_id' => $baseDocument['campaign_id'] !== null ? (int)$baseDocument['campaign_id'] : null,
                ':company_profile_id' => $baseDocument['company_profile_id'] !== null ? (int)$baseDocument['company_profile_id'] : null,
                ':issue_date' => $issueDate,
                ':valid_from' => $newValidFrom,
                ':valid_to' => $newValidTo,
                ':status' => $status,
                ':title' => $title !== '' ? $title : 'Aneks do zlecenia emisji reklamy',
                ':net_value' => number_format($netValue, 2, '.', ''),
                ':vat_rate' => number_format($vatRate, 2, '.', ''),
                ':vat_value' => number_format($vatValue, 2, '.', ''),
                ':gross_value' => number_format($grossValue, 2, '.', ''),
                ':currency' => $currency,
                ':created_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
                ':notes' => $notes !== '' ? $notes : null,
            ]);
            $documentId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO document_annex_details
                (document_id, base_document_id, change_description, old_valid_from, old_valid_to, new_valid_from, new_valid_to,
                 old_net_value, old_gross_value, new_net_value, new_gross_value)
                VALUES
                (:document_id, :base_document_id, :change_description, :old_valid_from, :old_valid_to, :new_valid_from, :new_valid_to,
                 :old_net_value, :old_gross_value, :new_net_value, :new_gross_value)");
            $stmt->execute([
                ':document_id' => $documentId,
                ':base_document_id' => (int)$baseDocument['id'],
                ':change_description' => $changeDescription,
                ':old_valid_from' => $baseDocument['valid_from'] ?: null,
                ':old_valid_to' => $baseDocument['valid_to'] ?: null,
                ':new_valid_from' => $newValidFrom,
                ':new_valid_to' => $newValidTo,
                ':old_net_value' => number_format((float)$baseDocument['net_value'], 2, '.', ''),
                ':old_gross_value' => number_format((float)$baseDocument['gross_value'], 2, '.', ''),
                ':new_net_value' => number_format($netValue, 2, '.', ''),
                ':new_gross_value' => number_format($grossValue, 2, '.', ''),
            ]);

            $pdo->commit();
            logDocumentAudit($pdo, $documentId, 'document_created', 'Utworzono nowy aneks', [
                'user_id' => (int)($_SESSION['user_id'] ?? 0),
                'new_value' => $documentNumber,
                'metadata' => [
                    'document_type' => 'annex',
                    'base_document_id' => (int)$baseDocument['id'],
                    'campaign_id' => $baseDocument['campaign_id'] !== null ? (int)$baseDocument['campaign_id'] : null,
                    'status' => $status,
                ],
            ]);
            header('Location: ' . BASE_URL . '/dokument_podglad.php?id=' . $documentId . '&created=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Nie udalo sie zapisac aneksu: ' . $e->getMessage();
        }
    }
}

$computedNet = max(0.0, annexDecimal('net_value'));
$computedVatRate = max(0.0, annexDecimal('vat_rate'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $computedVatRate = $defaultVatRate;
}
$computedVat = round($computedNet * ($computedVatRate / 100), 2);
$computedGross = round($computedNet + $computedVat, 2);

include __DIR__ . '/includes/header.php';
?>

<main class="container py-4" role="main" aria-labelledby="new-annex-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Dokumenty</p>
            <h1 id="new-annex-heading" class="h3 mb-2">Nowy aneks do zlecenia</h1>
            <p class="text-muted mb-0">Dokument typu aneks powiazany z istniejacym zleceniem emisji.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= annexH(BASE_URL . '/dokumenty.php') ?>">Wroc do listy</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= annexH($error) ?></div>
    <?php endforeach; ?>

    <?php if (!$baseOrders): ?>
        <div class="alert alert-warning">Brak zlecen typu order. Najpierw utworz zlecenie bazowe.</div>
    <?php endif; ?>

    <form method="post" class="card shadow-sm">
        <input type="hidden" name="csrf_token" value="<?= annexH($csrfToken) ?>">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label" for="base_document_id">Zlecenie bazowe *</label>
                    <select class="form-select" id="base_document_id" name="base_document_id" required>
                        <option value="">Wybierz zlecenie</option>
                        <?php foreach ($baseOrders as $order): ?>
                            <?php
                            $label = $order['document_number'] . ' - ' . (($order['client_name'] ?? '') ?: 'Klient #' . (int)$order['client_id']);
                            $period = trim((string)($order['valid_from'] ?? '') . ' - ' . (string)($order['valid_to'] ?? ''));
                            ?>
                            <option
                                value="<?= (int)$order['id'] ?>"
                                data-client="<?= annexH($order['client_name'] ?: '-') ?>"
                                data-period="<?= annexH($period ?: '-') ?>"
                                data-net="<?= annexH(annexMoney($order['net_value'], (string)$order['currency'])) ?>"
                                data-gross="<?= annexH(annexMoney($order['gross_value'], (string)$order['currency'])) ?>"
                                data-valid-from="<?= annexH($order['valid_from'] ?? '') ?>"
                                data-valid-to="<?= annexH($order['valid_to'] ?? '') ?>"
                                data-vat-rate="<?= annexH($order['vat_rate'] ?? $defaultVatRate) ?>"
                                data-currency="<?= annexH($order['currency'] ?? 'PLN') ?>"
                                <?= (string)$order['id'] === $form['base_document_id'] ? 'selected' : '' ?>
                            >
                                <?= annexH($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Klient</label>
                    <input class="form-control" type="text" id="client_preview" value="" readonly>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="issue_date">Data wystawienia aneksu *</label>
                    <input class="form-control" type="date" id="issue_date" name="issue_date" required value="<?= annexH($form['issue_date']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="new_valid_from">Nowa emisja od *</label>
                    <input class="form-control" type="date" id="new_valid_from" name="new_valid_from" required value="<?= annexH($form['new_valid_from']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="new_valid_to">Nowa emisja do *</label>
                    <input class="form-control" type="date" id="new_valid_to" name="new_valid_to" required value="<?= annexH($form['new_valid_to']) ?>">
                </div>

                <div class="col-md-8">
                    <label class="form-label" for="title">Tytul aneksu</label>
                    <input class="form-control" type="text" id="title" name="title" value="<?= annexH($form['title']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= annexH($value) ?>" <?= $form['status'] === $value ? 'selected' : '' ?>><?= annexH($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12">
                    <div class="row g-3 small text-muted">
                        <div class="col-md-4"><strong>Stary okres:</strong> <span id="old_period_preview">-</span></div>
                        <div class="col-md-4"><strong>Stare netto:</strong> <span id="old_net_preview">-</span></div>
                        <div class="col-md-4"><strong>Stare brutto:</strong> <span id="old_gross_preview">-</span></div>
                    </div>
                </div>

                <div class="col-12">
                    <label class="form-label" for="change_description">Opis zmiany *</label>
                    <textarea class="form-control" id="change_description" name="change_description" rows="4" required><?= annexH($form['change_description']) ?></textarea>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="net_value">Wartosc netto aneksu</label>
                    <input class="form-control" type="number" min="0" step="0.01" id="net_value" name="net_value" value="<?= annexH($form['net_value']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="vat_rate">VAT (%)</label>
                    <input class="form-control" type="number" min="0" step="0.01" id="vat_rate" name="vat_rate" value="<?= annexH($form['vat_rate']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">VAT</label>
                    <input class="form-control" type="text" id="vat_preview" readonly value="<?= annexH(number_format($computedVat, 2, '.', '')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Brutto</label>
                    <input class="form-control" type="text" id="gross_preview" readonly value="<?= annexH(number_format($computedGross, 2, '.', '')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="currency">Waluta</label>
                    <input class="form-control" type="text" maxlength="3" id="currency" name="currency" value="<?= annexH($form['currency']) ?>">
                </div>

                <div class="col-12">
                    <label class="form-label" for="notes">Notatki</label>
                    <textarea class="form-control" id="notes" name="notes" rows="4"><?= annexH($form['notes']) ?></textarea>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-secondary" href="<?= annexH(BASE_URL . '/dokumenty.php') ?>">Anuluj</a>
            <button class="btn btn-primary" type="submit" <?= !$baseOrders ? 'disabled' : '' ?>>Utworz aneks</button>
        </div>
    </form>
</main>

<script>
(function () {
    const baseSelect = document.getElementById('base_document_id');
    const clientPreview = document.getElementById('client_preview');
    const oldPeriodPreview = document.getElementById('old_period_preview');
    const oldNetPreview = document.getElementById('old_net_preview');
    const oldGrossPreview = document.getElementById('old_gross_preview');
    const newValidFrom = document.getElementById('new_valid_from');
    const newValidTo = document.getElementById('new_valid_to');
    const vatRate = document.getElementById('vat_rate');
    const currency = document.getElementById('currency');
    const netValue = document.getElementById('net_value');
    const vatPreview = document.getElementById('vat_preview');
    const grossPreview = document.getElementById('gross_preview');

    function selectedOption() {
        return baseSelect.options[baseSelect.selectedIndex] || null;
    }

    function refreshBase() {
        const option = selectedOption();
        clientPreview.value = option ? (option.dataset.client || '') : '';
        oldPeriodPreview.textContent = option ? (option.dataset.period || '-') : '-';
        oldNetPreview.textContent = option ? (option.dataset.net || '-') : '-';
        oldGrossPreview.textContent = option ? (option.dataset.gross || '-') : '-';
        if (option && option.value) {
            if (!newValidFrom.value) newValidFrom.value = option.dataset.validFrom || '';
            if (!newValidTo.value) newValidTo.value = option.dataset.validTo || '';
            if (!currency.value) currency.value = option.dataset.currency || 'PLN';
            if (vatRate.value === '') vatRate.value = option.dataset.vatRate || '23.00';
        }
    }

    function refreshValues() {
        const net = parseFloat(String(netValue.value || '0').replace(',', '.')) || 0;
        const rate = parseFloat(String(vatRate.value || '0').replace(',', '.')) || 0;
        const vat = Math.round(net * rate) / 100;
        const gross = Math.round((net + vat) * 100) / 100;
        vatPreview.value = vat.toFixed(2);
        grossPreview.value = gross.toFixed(2);
    }

    baseSelect.addEventListener('change', refreshBase);
    netValue.addEventListener('input', refreshValues);
    vatRate.addEventListener('input', refreshValues);
    refreshBase();
    refreshValues();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
