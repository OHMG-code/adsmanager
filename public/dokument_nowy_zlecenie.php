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
ensureDocumentOrderDetailsTable($pdo);
ensureDocumentAuditLogTable($pdo);

$pageTitle = 'Nowe zlecenie emisji';
$csrfToken = getCsrfToken();
$errors = [];
$statuses = [
    'draft' => 'Roboczy',
    'issued' => 'Wystawiony',
    'sent' => 'Wysłany',
    'accepted' => 'Zaakceptowany',
    'cancelled' => 'Anulowany',
];
$spotSources = [
    'client_material' => 'Klient dostarcza spot',
    'radio_production' => 'Radio produkuje spot',
];

function orderH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function orderClean(string $key, int $maxLength = 255): string
{
    $value = trim((string)($_POST[$key] ?? ''));
    $value = preg_replace('/[[:cntrl:]]+/u', ' ', $value);
    $value = trim((string)$value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $maxLength) : substr($value, 0, $maxLength);
}

function orderDateOrNull(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? $value : null;
}

function orderDecimal(string $key): float
{
    $value = str_replace([' ', ','], ['', '.'], (string)($_POST[$key] ?? '0'));
    return is_numeric($value) ? (float)$value : 0.0;
}

function orderInt(string $key): int
{
    $value = (string)($_POST[$key] ?? '0');
    return is_numeric($value) ? (int)$value : 0;
}

$companyProfile = $pdo->query('SELECT * FROM company_profile ORDER BY id ASC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
$defaultVatRate = (float)($companyProfile['default_vat_rate'] ?? 23.0);

$clients = $pdo->query("SELECT id, nazwa_firmy, nip FROM klienci ORDER BY nazwa_firmy ASC LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

$campaigns = [];
$campaignColumns = getTableColumns($pdo, 'kampanie');
if ($campaignColumns) {
    $campaigns = $pdo->query("SELECT id, klient_id, klient_nazwa, data_start, data_koniec, razem_netto, razem_brutto
        FROM kampanie
        ORDER BY created_at DESC, id DESC
        LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
}

$form = [
    'client_id' => (string)($_POST['client_id'] ?? ''),
    'campaign_id' => (string)($_POST['campaign_id'] ?? ''),
    'issue_date' => (string)($_POST['issue_date'] ?? date('Y-m-d')),
    'valid_from' => (string)($_POST['valid_from'] ?? ''),
    'valid_to' => (string)($_POST['valid_to'] ?? ''),
    'title' => (string)($_POST['title'] ?? 'Zlecenie emisji reklamy'),
    'net_value' => (string)($_POST['net_value'] ?? '0.00'),
    'vat_rate' => (string)($_POST['vat_rate'] ?? number_format($defaultVatRate, 2, '.', '')),
    'currency' => (string)($_POST['currency'] ?? 'PLN'),
    'status' => (string)($_POST['status'] ?? 'draft'),
    'notes' => (string)($_POST['notes'] ?? ''),
    'spot_source' => (string)($_POST['spot_source'] ?? ''),
    'material_deadline' => (string)($_POST['material_deadline'] ?? ''),
    'spot_length_seconds' => (string)($_POST['spot_length_seconds'] ?? '0'),
    'emission_count' => (string)($_POST['emission_count'] ?? '0'),
    'technical_notes' => (string)($_POST['technical_notes'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Niepoprawny token CSRF.';
    }

    $clientId = (int)($_POST['client_id'] ?? 0);
    $campaignId = (int)($_POST['campaign_id'] ?? 0);
    $issueDate = orderDateOrNull((string)($_POST['issue_date'] ?? ''));
    $validFrom = orderDateOrNull((string)($_POST['valid_from'] ?? ''));
    $validTo = orderDateOrNull((string)($_POST['valid_to'] ?? ''));
    $materialDeadline = orderDateOrNull((string)($_POST['material_deadline'] ?? ''));
    $title = orderClean('title');
    $netValue = orderDecimal('net_value');
    $vatRate = orderDecimal('vat_rate');
    $vatValue = round($netValue * ($vatRate / 100), 2);
    $grossValue = round($netValue + $vatValue, 2);
    $currency = strtoupper(orderClean('currency', 3)) ?: 'PLN';
    $status = orderClean('status', 30);
    $notes = orderClean('notes', 4000);
    $spotSource = orderClean('spot_source', 30);
    $spotLengthSeconds = orderInt('spot_length_seconds');
    $emissionCount = orderInt('emission_count');
    $technicalNotes = orderClean('technical_notes', 4000);

    if (!$companyProfile) {
        $errors[] = 'Brak profilu firmy. Uzupełnij Ustawienia -> Dane firmy.';
    }
    if ($clientId <= 0) {
        $errors[] = 'Klient jest wymagany.';
    } else {
        $stmt = $pdo->prepare('SELECT id FROM klienci WHERE id = ? LIMIT 1');
        $stmt->execute([$clientId]);
        if (!$stmt->fetchColumn()) {
            $errors[] = 'Wybrany klient nie istnieje.';
        }
        $stmt->closeCursor();
    }
    if ($campaignId > 0) {
        $stmt = $pdo->prepare('SELECT id, klient_id FROM kampanie WHERE id = ? LIMIT 1');
        $stmt->execute([$campaignId]);
        $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if (!$campaign) {
            $errors[] = 'Wybrana kampania nie istnieje.';
        } elseif (!empty($campaign['klient_id']) && (int)$campaign['klient_id'] !== $clientId) {
            $errors[] = 'Wybrana kampania jest przypisana do innego klienta.';
        }
    }
    if ($issueDate === null) {
        $errors[] = 'Data wystawienia jest wymagana.';
    }
    if ($validFrom === null || $validTo === null) {
        $errors[] = 'Okres emisji od/do jest wymagany.';
    }
    if ($validFrom !== null && $validTo !== null && $validFrom > $validTo) {
        $errors[] = 'Data końca emisji nie może być wcześniejsza niż data początku.';
    }
    if ($netValue < 0) {
        $errors[] = 'Wartość netto nie może być ujemna.';
    }
    if ($vatRate < 0) {
        $errors[] = 'Stawka VAT nie może być ujemna.';
    }
    if (!array_key_exists($status, $statuses)) {
        $errors[] = 'Niepoprawny status dokumentu.';
    }
    if (!array_key_exists($spotSource, $spotSources)) {
        $errors[] = 'Źródło spotu jest wymagane.';
    }
    if ($spotLengthSeconds < 0) {
        $errors[] = 'Długość spotu nie może być ujemna.';
    }
    if ($emissionCount < 0) {
        $errors[] = 'Liczba emisji nie może być ujemna.';
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $documentNumber = generateDocumentNumber($pdo, 'order', new DateTimeImmutable($issueDate));
            $stmt = $pdo->prepare("INSERT INTO documents
                (document_type, document_number, client_id, campaign_id, company_profile_id, issue_date, valid_from, valid_to,
                 status, title, net_value, vat_rate, vat_value, gross_value, currency, created_by, notes)
                VALUES
                ('order', :document_number, :client_id, :campaign_id, :company_profile_id, :issue_date, :valid_from, :valid_to,
                 :status, :title, :net_value, :vat_rate, :vat_value, :gross_value, :currency, :created_by, :notes)");
            $stmt->execute([
                ':document_number' => $documentNumber,
                ':client_id' => $clientId,
                ':campaign_id' => $campaignId > 0 ? $campaignId : null,
                ':company_profile_id' => (int)$companyProfile['id'],
                ':issue_date' => $issueDate,
                ':valid_from' => $validFrom,
                ':valid_to' => $validTo,
                ':status' => $status,
                ':title' => $title !== '' ? $title : 'Zlecenie emisji reklamy',
                ':net_value' => number_format($netValue, 2, '.', ''),
                ':vat_rate' => number_format($vatRate, 2, '.', ''),
                ':vat_value' => number_format($vatValue, 2, '.', ''),
                ':gross_value' => number_format($grossValue, 2, '.', ''),
                ':currency' => $currency,
                ':created_by' => (int)($_SESSION['user_id'] ?? 0) ?: null,
                ':notes' => $notes !== '' ? $notes : null,
            ]);
            $documentId = (int)$pdo->lastInsertId();

            $stmt = $pdo->prepare("INSERT INTO document_order_details
                (document_id, spot_source, material_deadline, spot_length_seconds, emission_count, technical_notes)
                VALUES (:document_id, :spot_source, :material_deadline, :spot_length_seconds, :emission_count, :technical_notes)");
            $stmt->execute([
                ':document_id' => $documentId,
                ':spot_source' => $spotSource,
                ':material_deadline' => $materialDeadline,
                ':spot_length_seconds' => $spotLengthSeconds,
                ':emission_count' => $emissionCount,
                ':technical_notes' => $technicalNotes !== '' ? $technicalNotes : null,
            ]);

            $pdo->commit();
            logDocumentAudit($pdo, $documentId, 'document_created', 'Utworzono nowe zlecenie', [
                'user_id' => (int)($_SESSION['user_id'] ?? 0),
                'new_value' => $documentNumber,
                'metadata' => [
                    'document_type' => 'order',
                    'client_id' => $clientId,
                    'campaign_id' => $campaignId > 0 ? $campaignId : null,
                    'status' => $status,
                ],
            ]);
            header('Location: ' . BASE_URL . '/dokument_podglad.php?id=' . $documentId . '&created=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = 'Nie udało się zapisać zlecenia: ' . $e->getMessage();
        }
    }
}

$computedNet = max(0.0, orderDecimal('net_value'));
$computedVatRate = max(0.0, orderDecimal('vat_rate'));
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $computedVatRate = $defaultVatRate;
}
$computedVat = round($computedNet * ($computedVatRate / 100), 2);
$computedGross = round($computedNet + $computedVat, 2);

include __DIR__ . '/includes/header.php';
?>

<main class="container py-4" role="main" aria-labelledby="new-order-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Dokumenty</p>
            <h1 id="new-order-heading" class="h3 mb-2">Nowe zlecenie emisji reklamy</h1>
            <p class="text-muted mb-0">Dokument typu zlecenie z danymi emisji i źródłem spotu.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= orderH(BASE_URL . '/dokumenty.php') ?>">Wróć do listy</a>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= orderH($error) ?></div>
    <?php endforeach; ?>

    <form method="post" class="card shadow-sm">
        <input type="hidden" name="csrf_token" value="<?= orderH($csrfToken) ?>">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="client_id">Klient *</label>
                    <select class="form-select" id="client_id" name="client_id" required>
                        <option value="">Wybierz klienta</option>
                        <?php foreach ($clients as $client): ?>
                            <?php $label = trim((string)$client['nazwa_firmy']) ?: ('Klient #' . (int)$client['id']); ?>
                            <option value="<?= (int)$client['id'] ?>" <?= (string)$client['id'] === $form['client_id'] ? 'selected' : '' ?>>
                                <?= orderH($label . (!empty($client['nip']) ? ' | NIP ' . $client['nip'] : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="campaign_id">Kampania</label>
                    <select class="form-select" id="campaign_id" name="campaign_id">
                        <option value="">Bez kampanii</option>
                        <?php foreach ($campaigns as $campaign): ?>
                            <?php
                            $campaignLabel = '#' . (int)$campaign['id'] . ' - ' . trim((string)($campaign['klient_nazwa'] ?? ''));
                            if (!empty($campaign['data_start']) || !empty($campaign['data_koniec'])) {
                                $campaignLabel .= ' (' . (string)$campaign['data_start'] . ' - ' . (string)$campaign['data_koniec'] . ')';
                            }
                            ?>
                            <option value="<?= (int)$campaign['id'] ?>" <?= (string)$campaign['id'] === $form['campaign_id'] ? 'selected' : '' ?>>
                                <?= orderH($campaignLabel) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="issue_date">Data wystawienia *</label>
                    <input class="form-control" type="date" id="issue_date" name="issue_date" required value="<?= orderH($form['issue_date']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="valid_from">Emisja od *</label>
                    <input class="form-control" type="date" id="valid_from" name="valid_from" required value="<?= orderH($form['valid_from']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="valid_to">Emisja do *</label>
                    <input class="form-control" type="date" id="valid_to" name="valid_to" required value="<?= orderH($form['valid_to']) ?>">
                </div>

                <div class="col-md-8">
                    <label class="form-label" for="title">Tytuł dokumentu</label>
                    <input class="form-control" type="text" id="title" name="title" value="<?= orderH($form['title']) ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <?php foreach ($statuses as $value => $label): ?>
                            <option value="<?= orderH($value) ?>" <?= $form['status'] === $value ? 'selected' : '' ?>><?= orderH($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="net_value">Netto</label>
                    <input class="form-control" type="number" min="0" step="0.01" id="net_value" name="net_value" value="<?= orderH($form['net_value']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="vat_rate">VAT (%)</label>
                    <input class="form-control" type="number" min="0" step="0.01" id="vat_rate" name="vat_rate" value="<?= orderH($form['vat_rate']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">VAT</label>
                    <input class="form-control" type="text" readonly value="<?= orderH(number_format($computedVat, 2, '.', '')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Brutto</label>
                    <input class="form-control" type="text" readonly value="<?= orderH(number_format($computedGross, 2, '.', '')) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="currency">Waluta</label>
                    <input class="form-control" type="text" maxlength="3" id="currency" name="currency" value="<?= orderH($form['currency']) ?>">
                </div>

                <div class="col-md-5">
                    <label class="form-label" for="spot_source">Źródło spotu *</label>
                    <select class="form-select" id="spot_source" name="spot_source" required>
                        <option value="">Wybierz</option>
                        <?php foreach ($spotSources as $value => $label): ?>
                            <option value="<?= orderH($value) ?>" <?= $form['spot_source'] === $value ? 'selected' : '' ?>><?= orderH($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="material_deadline">Termin materiału</label>
                    <input class="form-control" type="date" id="material_deadline" name="material_deadline" value="<?= orderH($form['material_deadline']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="spot_length_seconds">Długość spotu (s)</label>
                    <input class="form-control" type="number" min="0" id="spot_length_seconds" name="spot_length_seconds" value="<?= orderH($form['spot_length_seconds']) ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="emission_count">Liczba emisji</label>
                    <input class="form-control" type="number" min="0" id="emission_count" name="emission_count" value="<?= orderH($form['emission_count']) ?>">
                </div>
                <div class="col-md-9">
                    <label class="form-label" for="technical_notes">Uwagi techniczne</label>
                    <input class="form-control" type="text" id="technical_notes" name="technical_notes" value="<?= orderH($form['technical_notes']) ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="notes">Notatki</label>
                    <textarea class="form-control" id="notes" name="notes" rows="4"><?= orderH($form['notes']) ?></textarea>
                </div>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-secondary" href="<?= orderH(BASE_URL . '/dokumenty.php') ?>">Anuluj</a>
            <button class="btn btn-primary" type="submit">Utwórz zlecenie</button>
        </div>
    </form>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
