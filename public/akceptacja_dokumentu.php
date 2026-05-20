<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/document_acceptance.php';

ensureDocumentAcceptanceTables($pdo);

function acceptH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$message = '';
$messageType = 'info';
$document = null;

if ($token === '') {
    logDocumentAcceptanceEvent($pdo, 0, null, 'invalid', ['note' => 'Brak tokenu.']);
    $message = 'Link jest nieprawidłowy.';
    $messageType = 'danger';
} else {
    $tokenRow = documentAcceptanceLoadTokenRow($pdo, $token, false);
    if (!$tokenRow) {
        logDocumentAcceptanceEvent($pdo, 0, null, 'invalid', ['note' => 'Nieznany token.']);
        $message = 'Link jest nieprawidłowy.';
        $messageType = 'danger';
    } elseif (!empty($tokenRow['used_at'])) {
        $message = 'Ten link został już wykorzystany.';
        $messageType = 'warning';
    } elseif (!empty($tokenRow['revoked_at'])) {
        $message = 'Ten link został unieważniony.';
        $messageType = 'warning';
    } elseif (strtotime((string)$tokenRow['expires_at']) <= time()) {
        logDocumentAcceptanceEvent($pdo, (int)$tokenRow['document_id'], (int)$tokenRow['id'], 'expired', [
            'recipient_email' => (string)$tokenRow['recipient_email'],
        ]);
        $message = 'Ten link wygasł.';
        $messageType = 'warning';
    } else {
        $document = findValidDocumentByAcceptanceToken($pdo, $token);
    }
}

if ($document && isset($_GET['download'])) {
    $pdfPath = documentAcceptancePdfPath($document['pdf_path'] ?? null, (int)$document['document_id']);
    if (!$pdfPath) {
        http_response_code(404);
        echo 'Brak pliku PDF.';
        exit;
    }
    logDocumentAcceptanceEvent($pdo, (int)$document['document_id'], (int)$document['token_id'], 'downloaded_pdf', [
        'recipient_email' => (string)$document['recipient_email'],
    ]);
    $currentVersion = getCurrentDocumentPdfVersion($pdo, (int)$document['document_id']);
    if ($currentVersion) {
        logDocumentAudit($pdo, (int)$document['document_id'], 'document_pdf_version_downloaded', 'Pobrano aktualna wersje PDF online', [
            'metadata' => [
                'version_id' => (int)$currentVersion['id'],
                'version_number' => (int)$currentVersion['version_number'],
                'source' => 'online_acceptance',
                'recipient_email' => (string)$document['recipient_email'],
            ],
        ]);
    }
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . basename($pdfPath) . '"');
    header('Content-Length: ' . filesize($pdfPath));
    readfile($pdfPath);
    exit;
}

if ($document && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'accept') {
        $ok = acceptDocumentByToken($pdo, $token, [
            'accepted_by_name' => trim((string)($_POST['accepted_by_name'] ?? '')),
            'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
        $document = null;
        $message = $ok
            ? 'Dokument został zaakceptowany. Dziękujemy.'
            : 'Nie można wykorzystać tego linku. Dokument mógł zostać już obsłużony.';
        $messageType = $ok ? 'success' : 'warning';
    } elseif ($action === 'reject') {
        $ok = rejectDocumentByToken($pdo, $token, [
            'note' => trim((string)($_POST['note'] ?? '')),
            'ip_address' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
            'user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
        ]);
        $document = null;
        $message = $ok
            ? 'Informacja o braku akceptacji została przekazana.'
            : 'Nie można wykorzystać tego linku. Dokument mógł zostać już obsłużony.';
        $messageType = $ok ? 'success' : 'warning';
    }
}

if ($document && $_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['download'])) {
    logDocumentAcceptanceEvent($pdo, (int)$document['document_id'], (int)$document['token_id'], 'viewed', [
        'recipient_email' => (string)$document['recipient_email'],
    ]);
}

$typeLabels = ['order' => 'Zlecenie', 'annex' => 'Aneks'];
?>
<!doctype html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Akceptacja dokumentu</title>
    <style>
        body{margin:0;background:#f4f6f8;color:#1f2937;font-family:Arial,sans-serif}
        .container{max-width:860px;margin:0 auto;padding:48px 16px}
        .card{background:#fff;border:1px solid #d9e1ea;border-radius:8px;box-shadow:0 8px 24px rgba(15,23,42,.08)}
        .card-body{padding:28px}.h3{font-size:26px;margin:0 0 16px}.text-muted{color:#64748b}.mb-3{margin-bottom:16px}.mb-4{margin-bottom:24px}
        .row{display:grid;grid-template-columns:minmax(160px,1fr) 2fr;gap:8px 16px}.fw-semibold{font-weight:700}
        .alert{padding:12px 14px;border-radius:6px;margin-bottom:16px}.alert-success{background:#eaf7ef;color:#146c2e}.alert-warning{background:#fff8e1;color:#7a5600}.alert-danger{background:#fdecec;color:#9b1c1c}.alert-info{background:#eaf2ff;color:#174a8b}
        .btn{display:inline-block;border-radius:6px;padding:10px 14px;text-decoration:none;border:1px solid transparent;cursor:pointer;font-size:15px}
        .btn-success{background:#198754;color:#fff}.btn-outline-danger{background:#fff;color:#b42318;border-color:#b42318}.btn-outline-primary{background:#fff;color:#0d6efd;border-color:#0d6efd}
        .form-label{display:block;margin-bottom:6px;font-weight:600}.form-control{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:10px;font:inherit}
        .gap-2{gap:8px}.d-flex{display:flex}.flex-wrap{flex-wrap:wrap}
        @media (max-width:640px){.row{grid-template-columns:1fr}.card-body{padding:20px}}
    </style>
</head>
<body class="bg-light">
<main class="container py-5" style="max-width: 860px;">
    <div class="card shadow-sm">
        <div class="card-body p-4">
            <h1 class="h3 mb-3">Akceptacja dokumentu</h1>
            <?php if ($message !== ''): ?>
                <div class="alert alert-<?= acceptH($messageType) ?>"><?= acceptH($message) ?></div>
            <?php endif; ?>

            <?php if ($document): ?>
                <p class="text-muted mb-4"><?= acceptH($document['owner_company_name'] ?: 'CRM') ?></p>
                <dl class="row">
                    <dt class="col-sm-4">Numer dokumentu</dt>
                    <dd class="col-sm-8"><?= acceptH($document['document_number']) ?></dd>
                    <dt class="col-sm-4">Typ</dt>
                    <dd class="col-sm-8"><?= acceptH($typeLabels[$document['document_type']] ?? $document['document_type']) ?></dd>
                    <dt class="col-sm-4">Data wystawienia</dt>
                    <dd class="col-sm-8"><?= acceptH($document['issue_date'] ?: '-') ?></dd>
                    <dt class="col-sm-4">Klient</dt>
                    <dd class="col-sm-8"><?= acceptH($document['client_name'] ?: '-') ?></dd>
                    <dt class="col-sm-4">Wartość brutto</dt>
                    <dd class="col-sm-8 fw-semibold"><?= acceptH(number_format((float)$document['gross_value'], 2, ',', ' ') . ' ' . $document['currency']) ?></dd>
                </dl>

                <div class="d-flex flex-wrap gap-2 mb-4">
                    <a class="btn btn-outline-primary" href="<?= acceptH(BASE_URL . '/akceptacja_dokumentu.php?t=' . rawurlencode($token) . '&download=1') ?>">Pobierz PDF</a>
                </div>

                <?php if ((string)$document['status'] !== 'sent'): ?>
                    <div class="alert alert-warning">Ten dokument nie jest obecnie dostępny do akceptacji.</div>
                <?php elseif (!documentAcceptancePdfPath($document['pdf_path'] ?? null, (int)$document['document_id'])): ?>
                    <div class="alert alert-warning">Nie można zaakceptować dokumentu, ponieważ plik PDF nie jest dostępny.</div>
                <?php else: ?>
                    <form method="post" class="mb-3">
                        <input type="hidden" name="t" value="<?= acceptH($token) ?>">
                        <input type="hidden" name="action" value="accept">
                        <div class="mb-3">
                            <label class="form-label" for="accepted_by_name">Imię i nazwisko osoby akceptującej</label>
                            <input class="form-control" type="text" id="accepted_by_name" name="accepted_by_name">
                        </div>
                        <button class="btn btn-success" type="submit">Akceptuję dokument</button>
                    </form>
                    <form method="post">
                        <input type="hidden" name="t" value="<?= acceptH($token) ?>">
                        <input type="hidden" name="action" value="reject">
                        <div class="mb-3">
                            <label class="form-label" for="note">Uwaga do odrzucenia</label>
                            <textarea class="form-control" id="note" name="note" rows="3"></textarea>
                        </div>
                        <button class="btn btn-outline-danger" type="submit">Nie akceptuję</button>
                    </form>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
