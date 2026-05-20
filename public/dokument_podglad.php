<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/document_status.php';
require_once __DIR__ . '/includes/document_campaign_sync.php';
require_once __DIR__ . '/includes/document_acceptance.php';
require_once __DIR__ . '/includes/document_audit.php';
require_once __DIR__ . '/includes/document_pdf_versions.php';
require_once __DIR__ . '/includes/document_locks.php';

ensureDocumentOrderDetailsTable($pdo);
ensureDocumentAnnexDetailsTable($pdo);
ensureDocumentEmailLogTable($pdo);
ensureDocumentCampaignSyncLogTable($pdo);
ensureDocumentAcceptanceTables($pdo);
ensureDocumentAuditLogTable($pdo);
ensureDocumentPdfVersionsTable($pdo);

$pageTitle = 'Podgląd dokumentu';
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo 'Nie znaleziono dokumentu.';
    exit;
}

$stmt = $pdo->prepare("SELECT
        d.*,
        k.nazwa_firmy AS client_name,
        k.nip AS client_nip,
        k.adres AS client_address,
        k.email AS client_email,
        k.telefon AS client_phone,
        cp.company_name AS owner_company_name,
        cp.nip AS owner_nip,
        cp.address_street AS owner_address_street,
        cp.address_postal_code AS owner_address_postal_code,
        cp.address_city AS owner_address_city,
        cp.email AS owner_email,
        cp.phone AS owner_phone,
        base.document_number AS base_document_number,
        od.spot_source,
        od.material_deadline,
        od.spot_length_seconds,
        od.emission_count,
        od.technical_notes,
        ad.id AS annex_detail_id,
        ad.base_document_id,
        ad.change_description,
        ad.old_valid_from,
        ad.old_valid_to,
        ad.new_valid_from,
        ad.new_valid_to,
        ad.old_net_value,
        ad.old_gross_value,
        ad.new_net_value,
        ad.new_gross_value
    FROM documents d
    LEFT JOIN documents base ON base.id = d.related_document_id
    LEFT JOIN klienci k ON k.id = d.client_id
    LEFT JOIN company_profile cp ON cp.id = d.company_profile_id
    LEFT JOIN document_order_details od ON od.document_id = d.id
    LEFT JOIN document_annex_details ad ON ad.document_id = d.id
    WHERE d.id = :id
    LIMIT 1");
$stmt->execute([':id' => $id]);
$document = $stmt->fetch(PDO::FETCH_ASSOC);
$stmt->closeCursor();

$pdfVersions = listDocumentPdfVersions($pdo, $id);
$currentPdfVersion = null;
foreach ($pdfVersions as $pdfVersionRow) {
    if ((int)($pdfVersionRow['is_current'] ?? 0) === 1) {
        $currentPdfVersion = $pdfVersionRow;
        break;
    }
}

if (!$document) {
    http_response_code(404);
    echo 'Nie znaleziono dokumentu.';
    exit;
}

$stmt = $pdo->prepare("SELECT id, recipient_email, subject, status, error_message, sent_at, created_at
    FROM document_email_log
    WHERE document_id = :document_id
    ORDER BY created_at DESC, id DESC
    LIMIT 50");
$stmt->execute([':document_id' => $id]);
$emailLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

$stmt = $pdo->prepare("SELECT id, recipient_email, expires_at, used_at, revoked_at, created_at
    FROM document_acceptance_tokens
    WHERE document_id = :document_id
    ORDER BY created_at DESC, id DESC
    LIMIT 1");
$stmt->execute([':document_id' => $id]);
$acceptanceToken = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
$stmt->closeCursor();

$stmt = $pdo->prepare("SELECT id, token_id, action, recipient_email, ip_address, user_agent, note, created_at
    FROM document_acceptance_log
    WHERE document_id = :document_id
    ORDER BY created_at DESC, id DESC
    LIMIT 50");
$stmt->execute([':document_id' => $id]);
$acceptanceLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

$lastRejection = null;
$lastAcceptanceEvent = $acceptanceLogs[0] ?? null;
foreach ($acceptanceLogs as $logRow) {
    if ((string)$logRow['action'] === 'rejected') {
        $lastRejection = $logRow;
        break;
    }
}
$isRejectedLatest = $lastAcceptanceEvent && (string)$lastAcceptanceEvent['action'] === 'rejected';
$isDocumentClosed = isDocumentClosedStatus((string)$document['status']);
$activeAcceptanceToken = getActiveDocumentAcceptanceToken($pdo, $id);

$stmt = $pdo->prepare("SELECT a.*, u.login, u.imie, u.nazwisko
    FROM document_audit_log a
    LEFT JOIN uzytkownicy u ON u.id = a.user_id
    WHERE a.document_id = :document_id
    ORDER BY a.created_at DESC, a.id DESC
    LIMIT 30");
$stmt->execute([':document_id' => $id]);
$auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

$campaignSummary = getDocumentCampaignSummary($pdo, $id);
$linkedCampaign = $campaignSummary['campaign'] ?? null;
$linkedCampaignId = (int)($campaignSummary['campaign_id'] ?? 0);
$campaignWarnings = [];
if ($linkedCampaignId <= 0) {
    $campaignWarnings[] = 'Dokument nie ma powiazanej kampanii.';
} else {
    if (!empty($campaignSummary['missing_spot'])) {
        $campaignWarnings[] = 'Kampania nie ma przypisanego spotu.';
    }
    if (!empty($campaignSummary['missing_audio'])) {
        $campaignWarnings[] = 'Brak zaakceptowanego audio dla wszystkich spotow kampanii.';
    }
    if (!empty($campaignSummary['emission_blocked'])) {
        $campaignWarnings[] = 'Emisja jest obecnie zablokowana przez status kampanii lub spotow.';
    }
}

$types = [
    'order' => 'Zlecenie',
    'annex' => 'Aneks',
];
$statuses = documentStatusLabels();
$statusActionLabels = documentStatusActionLabels();
$csrfToken = getCsrfToken();
$possibleStatusActions = [];
foreach (array_keys($statuses) as $statusValue) {
    if (canTransitionDocumentStatus((string)$document['status'], $statusValue)) {
        $possibleStatusActions[] = $statusValue;
    }
}
$canSendDocumentEmail = !empty($document['pdf_path'])
    && (string)$document['status'] === 'issued'
    && filter_var((string)($document['client_email'] ?? ''), FILTER_VALIDATE_EMAIL);
$canSendReminder = (string)$document['status'] === 'sent'
    && !$isDocumentClosed
    && !$isRejectedLatest
    && $activeAcceptanceToken
    && !empty($document['pdf_path']);
$spotSources = [
    'client_material' => 'Klient dostarcza spot',
    'radio_production' => 'Radio produkuje spot',
];
$acceptanceActionLabels = [
    'viewed' => 'Wyświetlony',
    'downloaded_pdf' => 'Pobrano PDF',
    'accepted' => 'Zaakceptowany',
    'rejected' => 'Odrzucony',
    'expired' => 'Wygasły',
    'invalid' => 'Nieprawidłowy',
];

function previewH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function previewMoney($value, string $currency): string
{
    return number_format((float)$value, 2, ',', ' ') . ' ' . $currency;
}

function previewAuditMeta(?string $json): string
{
    $json = trim((string)$json);
    if ($json === '') {
        return '-';
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return substr($json, 0, 160);
    }
    $parts = [];
    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $parts[] = (string)$key . ': ' . (string)$value;
        if (count($parts) >= 4) {
            break;
        }
    }
    $summary = implode(' | ', $parts);
    return $summary !== '' ? substr($summary, 0, 220) : '-';
}

function previewBytes($bytes): string
{
    if ($bytes === null || $bytes === '') {
        return '-';
    }
    $bytes = (int)$bytes;
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2, ',', ' ') . ' MB';
    }
    return number_format($bytes / 1024, 1, ',', ' ') . ' KB';
}

include __DIR__ . '/includes/header.php';
?>

<main class="container py-4" role="main" aria-labelledby="document-preview-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Dokument</p>
            <h1 id="document-preview-heading" class="h3 mb-2"><?= previewH($document['document_number']) ?></h1>
            <p class="text-muted mb-0"><?= previewH($document['title'] ?: 'Dokument sprzedażowy') ?></p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <?php if (in_array($document['document_type'], ['order', 'annex'], true) && !in_array((string)$document['status'], ['accepted', 'cancelled'], true)): ?>
                <a class="btn btn-primary btn-sm" href="<?= previewH(BASE_URL . '/dokument_generuj_pdf.php?id=' . (int)$document['id']) ?>">Generuj PDF</a>
            <?php endif; ?>
            <?php if (!empty($document['pdf_path'])): ?>
                <?php if ($currentPdfVersion): ?>
                    <a class="btn btn-outline-primary btn-sm" href="<?= previewH(BASE_URL . '/dokument_pdf_pobierz.php?version_id=' . (int)$currentPdfVersion['id']) ?>">Pobierz PDF</a>
                <?php else: ?>
                    <a class="btn btn-outline-primary btn-sm" href="<?= previewH(BASE_URL . '/' . ltrim((string)$document['pdf_path'], '/')) ?>" target="_blank" rel="noopener">Pobierz PDF</a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($canSendDocumentEmail): ?>
                <a class="btn btn-outline-success btn-sm" href="<?= previewH(BASE_URL . '/dokument_wyslij.php?id=' . (int)$document['id']) ?>">Wyślij do klienta</a>
            <?php endif; ?>
            <?php if ($canSendReminder): ?>
                <form method="post" action="<?= previewH(BASE_URL . '/dokument_przypomnienie.php') ?>">
                    <input type="hidden" name="csrf_token" value="<?= previewH($csrfToken) ?>">
                    <input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
                    <button class="btn btn-outline-warning btn-sm" type="submit">Wyślij przypomnienie</button>
                </form>
            <?php endif; ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= previewH(BASE_URL . '/dokumenty.php') ?>">Wróć do listy</a>
        </div>
    </div>

    <?php if (!empty($_GET['created'])): ?>
        <div class="alert alert-success">Dokument zostal utworzony.</div>
    <?php endif; ?>
    <?php if (($_GET['status'] ?? '') === 'updated'): ?>
        <div class="alert alert-success">Status dokumentu zostal zaktualizowany.</div>
    <?php endif; ?>
    <?php if (($_GET['mail'] ?? '') === 'sent'): ?>
        <div class="alert alert-success">Dokument został wysłany do klienta.</div>
    <?php endif; ?>
    <?php if (($_GET['token'] ?? '') === 'revoked'): ?>
        <div class="alert alert-success">Aktywny link akceptacji zostal uniewazniony.</div>
    <?php endif; ?>
    <?php if (($_GET['reminder'] ?? '') === 'sent'): ?>
        <div class="alert alert-success">Przypomnienie zostalo wyslane do klienta.</div>
    <?php endif; ?>
    <?php if ($isRejectedLatest && $lastRejection): ?>
        <div class="alert alert-warning">
            <strong>Klient odrzucil dokument.</strong>
            <?php if (trim((string)($lastRejection['note'] ?? '')) !== ''): ?>
                <br><?= nl2br(previewH($lastRejection['note'])) ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($isRejectedLatest && $lastRejection): ?>
        <section class="card shadow-sm mb-4 border-warning" aria-label="Odrzucenie dokumentu">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                    <div>
                        <h2 class="h5 mb-2">Odrzucenie klienta</h2>
                        <dl class="row mb-0">
                            <dt class="col-sm-4">Data</dt>
                            <dd class="col-sm-8"><?= previewH($lastRejection['created_at'] ?? '-') ?></dd>
                            <dt class="col-sm-4">E-mail</dt>
                            <dd class="col-sm-8"><?= previewH($lastRejection['recipient_email'] ?: '-') ?></dd>
                            <dt class="col-sm-4">IP</dt>
                            <dd class="col-sm-8"><?= previewH($lastRejection['ip_address'] ?: '-') ?></dd>
                            <dt class="col-sm-4">Notatka</dt>
                            <dd class="col-sm-8"><?= nl2br(previewH($lastRejection['note'] ?: '-')) ?></dd>
                        </dl>
                    </div>
                    <?php if ((string)$document['status'] === 'sent'): ?>
                        <form method="post" action="<?= previewH(BASE_URL . '/dokument_odtworz_po_odrzuceniu.php') ?>">
                            <input type="hidden" name="csrf_token" value="<?= previewH($csrfToken) ?>">
                            <input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
                            <button type="submit" class="btn btn-warning btn-sm">Utwórz nową wersję po odrzuceniu</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
    <?php if (!empty($_GET['status_error'])): ?>
        <div class="alert alert-danger"><?= previewH($_GET['status_error']) ?></div>
    <?php endif; ?>
    <?php if (($_GET['pdf'] ?? '') === 'generated'): ?>
        <div class="alert alert-success">PDF został wygenerowany.</div>
    <?php endif; ?>
    <?php if (!empty($_GET['pdf_error'])): ?>
        <div class="alert alert-danger"><?= previewH($_GET['pdf_error']) ?></div>
    <?php endif; ?>
    <?php if (!empty($document['pdf_path'])): ?>
        <div class="alert alert-light border small">Aktualny PDF: <code><?= previewH($document['pdf_path']) ?></code></div>
    <?php endif; ?>
    <?php if ($isDocumentClosed): ?>
        <div class="alert alert-secondary"><?= previewH(documentClosedMessage()) ?></div>
    <?php endif; ?>

    <?php if ($possibleStatusActions): ?>
        <section class="card shadow-sm mb-4" aria-label="Akcje statusu dokumentu">
            <div class="card-body d-flex flex-wrap align-items-center gap-2">
                <span class="small text-muted me-2">Akcje dokumentu:</span>
                <?php foreach ($possibleStatusActions as $nextStatus): ?>
                    <form method="post" action="<?= previewH(BASE_URL . '/dokument_status.php') ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= previewH($csrfToken) ?>">
                        <input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
                        <input type="hidden" name="new_status" value="<?= previewH($nextStatus) ?>">
                        <button type="submit" class="btn btn-sm <?= $nextStatus === 'cancelled' ? 'btn-outline-danger' : 'btn-outline-primary' ?>">
                            <?= previewH($statusActionLabels[$nextStatus] ?? $statuses[$nextStatus]) ?>
                        </button>
                    </form>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-lg-6">
            <section class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Dane dokumentu</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Numer</dt>
                        <dd class="col-sm-7"><?= previewH($document['document_number']) ?></dd>
                        <dt class="col-sm-5">Typ</dt>
                        <dd class="col-sm-7"><?= previewH($types[$document['document_type']] ?? $document['document_type']) ?></dd>
                        <?php if ($document['document_type'] === 'annex'): ?>
                            <dt class="col-sm-5">Zlecenie bazowe</dt>
                            <dd class="col-sm-7"><?= previewH($document['base_document_number'] ?: '-') ?></dd>
                        <?php endif; ?>
                        <dt class="col-sm-5">Status</dt>
                        <dd class="col-sm-7">
                            <span class="badge <?= previewH(documentStatusBadgeClass((string)$document['status'])) ?>">
                                <?= previewH($statuses[$document['status']] ?? $document['status']) ?>
                            </span>
                        </dd>
                        <?php if ($document['status'] === 'accepted'): ?>
                            <dt class="col-sm-5">Zaakceptowano</dt>
                            <dd class="col-sm-7"><?= previewH($document['accepted_at'] ?: '-') ?></dd>
                            <dt class="col-sm-5">Akceptujacy</dt>
                            <dd class="col-sm-7"><?= previewH($document['accepted_by_name'] ?: '-') ?></dd>
                            <dt class="col-sm-5">Email akceptacji</dt>
                            <dd class="col-sm-7"><?= previewH($document['accepted_by_email'] ?: '-') ?></dd>
                        <?php endif; ?>
                        <dt class="col-sm-5">Data wystawienia</dt>
                        <dd class="col-sm-7"><?= previewH($document['issue_date'] ?: '-') ?></dd>
                        <dt class="col-sm-5">Okres emisji</dt>
                        <dd class="col-sm-7"><?= previewH(($document['valid_from'] ?: '-') . ' - ' . ($document['valid_to'] ?: '-')) ?></dd>
                    </dl>
                </div>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Wartość</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Netto</dt>
                        <dd class="col-sm-7"><?= previewH(previewMoney($document['net_value'], (string)$document['currency'])) ?></dd>
                        <dt class="col-sm-5">VAT</dt>
                        <dd class="col-sm-7"><?= previewH((string)$document['vat_rate']) ?>% / <?= previewH(previewMoney($document['vat_value'], (string)$document['currency'])) ?></dd>
                        <dt class="col-sm-5">Brutto</dt>
                        <dd class="col-sm-7 fw-semibold"><?= previewH(previewMoney($document['gross_value'], (string)$document['currency'])) ?></dd>
                    </dl>
                </div>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Klient</h2>
                    <p class="fw-semibold mb-1"><?= previewH($document['client_name'] ?: '-') ?></p>
                    <p class="mb-1">NIP: <?= previewH($document['client_nip'] ?: '-') ?></p>
                    <p class="mb-1"><?= previewH($document['client_address'] ?: '-') ?></p>
                    <p class="mb-0 text-muted"><?= previewH(trim(($document['client_email'] ?: '') . ' ' . ($document['client_phone'] ?: '')) ?: '-') ?></p>
                </div>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Właściciel CRM</h2>
                    <p class="fw-semibold mb-1"><?= previewH($document['owner_company_name'] ?: '-') ?></p>
                    <p class="mb-1">NIP: <?= previewH($document['owner_nip'] ?: '-') ?></p>
                    <p class="mb-1">
                        <?= previewH(trim(($document['owner_address_street'] ?: '') . ' ' . ($document['owner_address_postal_code'] ?: '') . ' ' . ($document['owner_address_city'] ?: '')) ?: '-') ?>
                    </p>
                    <p class="mb-0 text-muted"><?= previewH(trim(($document['owner_email'] ?: '') . ' ' . ($document['owner_phone'] ?: '')) ?: '-') ?></p>
                </div>
            </section>
        </div>
        <?php if ($document['document_type'] === 'order'): ?>
        <div class="col-lg-6">
            <section class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Spot i emisja</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Źródło spotu</dt>
                        <dd class="col-sm-7"><?= previewH($spotSources[$document['spot_source']] ?? ($document['spot_source'] ?: '-')) ?></dd>
                        <dt class="col-sm-5">Termin materiału</dt>
                        <dd class="col-sm-7"><?= previewH($document['material_deadline'] ?: '-') ?></dd>
                        <dt class="col-sm-5">Długość spotu</dt>
                        <dd class="col-sm-7"><?= previewH($document['spot_length_seconds'] ?? '0') ?> s</dd>
                        <dt class="col-sm-5">Liczba emisji</dt>
                        <dd class="col-sm-7"><?= previewH($document['emission_count'] ?? '0') ?></dd>
                    </dl>
                </div>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Uwagi</h2>
                    <p class="mb-3"><strong>Techniczne:</strong><br><?= nl2br(previewH($document['technical_notes'] ?: '-')) ?></p>
                    <p class="mb-0"><strong>Notatki:</strong><br><?= nl2br(previewH($document['notes'] ?: '-')) ?></p>
                </div>
            </section>
        </div>
        <?php elseif ($document['document_type'] === 'annex'): ?>
        <div class="col-lg-6">
            <section class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Zmiana</h2>
                    <p class="mb-0"><?= nl2br(previewH($document['change_description'] ?: '-')) ?></p>
                </div>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Okres: bylo / jest</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Bylo</dt>
                        <dd class="col-sm-7"><?= previewH(($document['old_valid_from'] ?: '-') . ' - ' . ($document['old_valid_to'] ?: '-')) ?></dd>
                        <dt class="col-sm-5">Jest</dt>
                        <dd class="col-sm-7"><?= previewH(($document['new_valid_from'] ?: '-') . ' - ' . ($document['new_valid_to'] ?: '-')) ?></dd>
                    </dl>
                </div>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Wartosci: bylo / jest</h2>
                    <dl class="row mb-0">
                        <dt class="col-sm-5">Netto bylo</dt>
                        <dd class="col-sm-7"><?= previewH(previewMoney($document['old_net_value'], (string)$document['currency'])) ?></dd>
                        <dt class="col-sm-5">Netto jest</dt>
                        <dd class="col-sm-7"><?= previewH(previewMoney($document['new_net_value'], (string)$document['currency'])) ?></dd>
                        <dt class="col-sm-5">Brutto bylo</dt>
                        <dd class="col-sm-7"><?= previewH(previewMoney($document['old_gross_value'], (string)$document['currency'])) ?></dd>
                        <dt class="col-sm-5">Brutto jest</dt>
                        <dd class="col-sm-7 fw-semibold"><?= previewH(previewMoney($document['new_gross_value'], (string)$document['currency'])) ?></dd>
                    </dl>
                </div>
            </section>
        </div>
        <div class="col-lg-6">
            <section class="card shadow-sm h-100">
                <div class="card-body">
                    <h2 class="h5 mb-3">Notatki</h2>
                    <p class="mb-0"><?= nl2br(previewH($document['notes'] ?: '-')) ?></p>
                </div>
            </section>
        </div>
        <?php endif; ?>
    </div>

    <section class="card shadow-sm mt-4" aria-label="Powiazana kampania">
        <div class="card-body">
            <h2 class="h5 mb-3">Powiazana kampania</h2>
            <?php if (!$linkedCampaign): ?>
                <p class="text-muted mb-0">Brak powiazanej kampanii dla tego dokumentu.</p>
            <?php else: ?>
                <div class="row g-3 align-items-start">
                    <div class="col-lg-8">
                        <dl class="row mb-0">
                            <dt class="col-sm-5">Kampania</dt>
                            <dd class="col-sm-7"><?= previewH('#' . $linkedCampaignId . ' - ' . ($linkedCampaign['klient_nazwa'] ?: 'Kampania')) ?></dd>
                            <dt class="col-sm-5">Status kampanii</dt>
                            <dd class="col-sm-7"><?= previewH($linkedCampaign['status'] ?: '-') ?></dd>
                            <dt class="col-sm-5">Status emisji</dt>
                            <dd class="col-sm-7"><?= previewH($linkedCampaign['realization_status'] ?: '-') ?></dd>
                            <dt class="col-sm-5">Liczba spotow</dt>
                            <dd class="col-sm-7"><?= (int)$campaignSummary['spot_count'] ?></dd>
                            <dt class="col-sm-5">Aktywne emisje</dt>
                            <dd class="col-sm-7"><?= (int)$campaignSummary['active_emission_count'] ?></dd>
                        </dl>
                    </div>
                    <div class="col-lg-4 d-flex flex-wrap gap-2 justify-content-lg-end">
                        <a class="btn btn-sm btn-outline-primary" href="<?= previewH(BASE_URL . '/kampania_podglad.php?id=' . $linkedCampaignId) ?>">Otworz kampanie</a>
                        <a class="btn btn-sm btn-outline-secondary" href="<?= previewH(BASE_URL . '/podglad_pasma.php') ?>">Podglad pasma</a>
                    </div>
                </div>
                <?php if ($campaignWarnings): ?>
                    <div class="mt-3">
                        <?php foreach ($campaignWarnings as $warning): ?>
                            <div class="alert alert-warning py-2 mb-2"><?= previewH($warning) ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </section>

    <section class="card shadow-sm mt-4" aria-label="Wersje PDF">
        <div class="card-body">
            <h2 class="h5 mb-3">Wersje PDF</h2>
            <?php if (!$pdfVersions): ?>
                <p class="text-muted mb-0">Brak zapisanych wersji PDF.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Wersja</th>
                                <th>Data</th>
                                <th>Uzytkownik</th>
                                <th>Plik</th>
                                <th>Rozmiar</th>
                                <th>Checksum</th>
                                <th>Aktualna</th>
                                <th class="text-end"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pdfVersions as $version): ?>
                                <?php
                                    $versionUser = trim((string)($version['imie'] ?? '') . ' ' . (string)($version['nazwisko'] ?? ''));
                                    if ($versionUser === '') {
                                        $versionUser = (string)($version['login'] ?? '');
                                    }
                                    $checksum = (string)($version['checksum_sha256'] ?? '');
                                ?>
                                <tr>
                                    <td>v<?= (int)$version['version_number'] ?></td>
                                    <td><?= previewH($version['generated_at'] ?: $version['created_at']) ?></td>
                                    <td><?= previewH($versionUser !== '' ? $versionUser : '-') ?></td>
                                    <td><?= previewH($version['file_name']) ?></td>
                                    <td><?= previewH(previewBytes($version['file_size'] ?? null)) ?></td>
                                    <td><code><?= previewH($checksum !== '' ? substr($checksum, 0, 12) : '-') ?></code></td>
                                    <td><?= (int)$version['is_current'] === 1 ? '<span class="badge text-bg-success">tak</span>' : '<span class="badge text-bg-secondary">nie</span>' ?></td>
                                    <td class="text-end">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= previewH(BASE_URL . '/dokument_pdf_pobierz.php?version_id=' . (int)$version['id']) ?>">Pobierz</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card shadow-sm mt-4" aria-label="Akceptacja online">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
                <h2 class="h5 mb-0">Akceptacja online</h2>
                <?php if ($acceptanceToken && empty($acceptanceToken['used_at']) && empty($acceptanceToken['revoked_at']) && strtotime((string)$acceptanceToken['expires_at']) > time()): ?>
                    <form method="post" action="<?= previewH(BASE_URL . '/dokument_token_uniewaznij.php') ?>">
                        <input type="hidden" name="csrf_token" value="<?= previewH($csrfToken) ?>">
                        <input type="hidden" name="document_id" value="<?= (int)$document['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" type="submit">Uniewaznij aktywny link</button>
                    </form>
                <?php endif; ?>
            </div>

            <?php if (!$acceptanceToken): ?>
                <p class="text-muted mb-3">Nie wygenerowano jeszcze linku akceptacji online.</p>
            <?php else: ?>
                <?php
                    $tokenStatus = 'Aktywny';
                    if (!empty($acceptanceToken['used_at'])) {
                        $tokenStatus = 'Wykorzystany';
                    } elseif (!empty($acceptanceToken['revoked_at'])) {
                        $tokenStatus = 'Uniewazniony';
                    } elseif (strtotime((string)$acceptanceToken['expires_at']) <= time()) {
                        $tokenStatus = 'Wygasl';
                    }
                ?>
                <dl class="row mb-3">
                    <dt class="col-sm-4">Status linku</dt>
                    <dd class="col-sm-8"><?= previewH($tokenStatus) ?></dd>
                    <dt class="col-sm-4">E-mail odbiorcy</dt>
                    <dd class="col-sm-8"><?= previewH($acceptanceToken['recipient_email']) ?></dd>
                    <dt class="col-sm-4">Wygasa</dt>
                    <dd class="col-sm-8"><?= previewH($acceptanceToken['expires_at'] ?: '-') ?></dd>
                    <dt class="col-sm-4">Uzyty</dt>
                    <dd class="col-sm-8"><?= previewH($acceptanceToken['used_at'] ?: '-') ?></dd>
                    <dt class="col-sm-4">Uniewazniony</dt>
                    <dd class="col-sm-8"><?= previewH($acceptanceToken['revoked_at'] ?: '-') ?></dd>
                </dl>
                <div class="alert alert-light border small mb-3">Pełny token nie jest przechowywany ani pokazywany w CRM.</div>
            <?php endif; ?>

            <h3 class="h6 mb-2">Historia akceptacji online</h3>
            <?php if (!$acceptanceLogs): ?>
                <p class="text-muted mb-0">Brak zdarzen akceptacji online.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Status</th>
                                <th>E-mail</th>
                                <th>IP</th>
                                <th>Uwaga</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($acceptanceLogs as $log): ?>
                                <tr>
                                    <td><?= previewH($log['created_at']) ?></td>
                                    <td><?= previewH($acceptanceActionLabels[$log['action']] ?? $log['action']) ?></td>
                                    <td><?= previewH($log['recipient_email'] ?: '-') ?></td>
                                    <td><?= previewH($log['ip_address'] ?: '-') ?></td>
                                    <td><?= previewH($log['note'] ?: '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card shadow-sm mt-4" aria-label="Historia dokumentu">
        <div class="card-body">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                <h2 class="h5 mb-0">Historia dokumentu</h2>
                <a class="btn btn-sm btn-outline-secondary" href="<?= previewH(BASE_URL . '/dokument_historia.php?id=' . (int)$document['id']) ?>">Pokaz pelna historie</a>
            </div>
            <?php if (!$auditLogs): ?>
                <p class="text-muted mb-0">Brak wpisow audytu dla tego dokumentu.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Uzytkownik</th>
                                <th>Typ</th>
                                <th>Opis</th>
                                <th>Stara wartosc</th>
                                <th>Nowa wartosc</th>
                                <th>Metadane</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($auditLogs as $audit): ?>
                                <?php
                                    $auditUser = trim((string)($audit['imie'] ?? '') . ' ' . (string)($audit['nazwisko'] ?? ''));
                                    if ($auditUser === '') {
                                        $auditUser = (string)($audit['login'] ?? '');
                                    }
                                ?>
                                <tr>
                                    <td><?= previewH($audit['created_at']) ?></td>
                                    <td><?= previewH($auditUser !== '' ? $auditUser : '-') ?></td>
                                    <td><?= previewH($audit['event_type']) ?></td>
                                    <td><?= previewH($audit['event_label']) ?></td>
                                    <td><?= previewH($audit['old_value'] ?: '-') ?></td>
                                    <td><?= previewH($audit['new_value'] ?: '-') ?></td>
                                    <td><?= previewH(previewAuditMeta($audit['metadata_json'] ?? null)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section class="card shadow-sm mt-4" aria-label="Historia wysyłek">
        <div class="card-body">
            <h2 class="h5 mb-3">Historia wysyłek</h2>
            <?php if (!$emailLogs): ?>
                <p class="text-muted mb-0">Brak wysyłek dla tego dokumentu.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Odbiorca</th>
                                <th>Temat</th>
                                <th>Status</th>
                                <th>Błąd</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($emailLogs as $log): ?>
                                <tr>
                                    <td><?= previewH($log['sent_at'] ?: $log['created_at']) ?></td>
                                    <td><?= previewH($log['recipient_email']) ?></td>
                                    <td><?= previewH($log['subject']) ?></td>
                                    <td>
                                        <span class="badge <?= $log['status'] === 'sent' ? 'text-bg-success' : 'text-bg-danger' ?>">
                                            <?= previewH($log['status']) ?>
                                        </span>
                                    </td>
                                    <td><?= previewH($log['error_message'] ?: '-') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
