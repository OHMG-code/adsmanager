<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/document_status.php';
require_once __DIR__ . '/includes/document_acceptance.php';
require_once __DIR__ . '/includes/document_audit.php';
require_once __DIR__ . '/includes/document_pdf_versions.php';
require_once __DIR__ . '/includes/email_templates.php';
require_once __DIR__ . '/includes/mail_service.php';
require_once __DIR__ . '/includes/document_locks.php';

ensureDocumentEmailLogTable($pdo);
ensureDocumentAcceptanceTables($pdo);
ensureDocumentAuditLogTable($pdo);
ensureDocumentPdfVersionsTable($pdo);
ensureEmailTemplatesTable($pdo);
ensureSystemConfigColumns($pdo);
ensureUserColumns($pdo);

$id = (int)($_GET['id'] ?? $_POST['document_id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo 'Nie znaleziono dokumentu.';
    exit;
}

function documentSendH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function documentSendLoad(PDO $pdo, int $documentId): ?array
{
    $stmt = $pdo->prepare("SELECT
            d.*,
            k.nazwa_firmy AS client_name,
            k.email AS client_email,
            cp.company_name AS owner_company_name
        FROM documents d
        LEFT JOIN klienci k ON k.id = d.client_id
        LEFT JOIN company_profile cp ON cp.id = d.company_profile_id
        WHERE d.id = :id
        LIMIT 1");
    $stmt->execute([':id' => $documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt->closeCursor();
    return $document;
}

function documentSendPdfPath(array $document): array
{
    global $pdo;
    $currentVersion = getCurrentDocumentPdfVersion($pdo, (int)($document['id'] ?? 0));
    if ($currentVersion) {
        $fullPath = documentPdfResolvePath($currentVersion['pdf_path'] ?? '');
        if ($fullPath) {
            return [(string)$currentVersion['pdf_path'], $fullPath, (string)$currentVersion['file_name']];
        }
    }

    $relativePath = trim((string)($document['pdf_path'] ?? ''));
    if ($relativePath === '') {
        return ['', '', ''];
    }

    $publicDir = realpath(__DIR__);
    $fullPath = realpath(__DIR__ . '/' . ltrim($relativePath, '/'));
    if (!$publicDir || !$fullPath || strpos($fullPath, $publicDir) !== 0 || !is_file($fullPath)) {
        return [$relativePath, '', basename($relativePath)];
    }

    return [$relativePath, $fullPath, basename($fullPath)];
}

function documentSendLog(PDO $pdo, int $documentId, string $recipientEmail, string $subject, string $body, ?string $attachmentPath, ?int $sentBy, string $status, ?string $errorMessage = null): void
{
    $stmt = $pdo->prepare("INSERT INTO document_email_log
        (document_id, recipient_email, subject, body, attachment_path, sent_by, sent_at, status, error_message)
        VALUES (:document_id, :recipient_email, :subject, :body, :attachment_path, :sent_by, :sent_at, :status, :error_message)");
    $stmt->execute([
        ':document_id' => $documentId,
        ':recipient_email' => $recipientEmail,
        ':subject' => $subject,
        ':body' => $body,
        ':attachment_path' => $attachmentPath,
        ':sent_by' => $sentBy,
        ':sent_at' => $status === 'sent' ? date('Y-m-d H:i:s') : null,
        ':status' => $status,
        ':error_message' => $errorMessage,
    ]);
}

$document = documentSendLoad($pdo, $id);
if (!$document) {
    http_response_code(404);
    echo 'Nie znaleziono dokumentu.';
    exit;
}
if (isDocumentClosedStatus((string)$document['status'])) {
    logDocumentLockedEditAttempt($pdo, $id, 'send_document');
}

[$pdfRelativePath, $pdfFullPath, $pdfFilename] = documentSendPdfPath($document);
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

$documentType = (string)$document['document_type'];
$documentNumber = (string)$document['document_number'];
$clientEmail = trim((string)($document['client_email'] ?? ''));
$companyName = trim((string)($document['owner_company_name'] ?? '')) ?: 'CRM';
$defaultSubject = $documentType === 'annex'
    ? 'Aneks do zlecenia ' . $documentNumber
    : 'Zlecenie emisji reklamy ' . $documentNumber;
$defaultBody = "Dzień dobry,\n\n"
    . "w załączeniu przesyłamy dokument " . $documentNumber . " dotyczący emisji reklamy.\n"
    . "Prosimy o zapoznanie się z dokumentem i potwierdzenie akceptacji.\n\n"
    . "Link do pobrania i akceptacji dokumentu:\n{ACCEPTANCE_LINK}\n\n"
    . "Pozdrawiamy,\n"
    . $companyName;
$templateKey = $documentType === 'annex' ? 'document_annex_send' : 'document_order_send';
$documentTypeLabel = $documentType === 'annex' ? 'Aneks' : 'Zlecenie';
$templateVars = [
    'DOCUMENT_NUMBER' => $documentNumber,
    'DOCUMENT_TYPE' => $documentTypeLabel,
    'CLIENT_NAME' => (string)($document['client_name'] ?? ''),
    'COMPANY_NAME' => $companyName,
    'GROSS_VALUE' => number_format((float)($document['gross_value'] ?? 0), 2, ',', ' '),
    'CURRENCY' => (string)($document['currency'] ?? 'PLN'),
    'ACCEPTANCE_LINK' => '{ACCEPTANCE_LINK}',
    'PDF_FILE_NAME' => $pdfFilename,
];
$emailTemplate = getActiveEmailTemplate($pdo, $templateKey);
if ($emailTemplate) {
    $defaultSubject = renderEmailTemplate((string)$emailTemplate['subject_template'], $templateVars);
    $defaultBody = renderEmailTemplate((string)$emailTemplate['body_template'], $templateVars);
}

$toEmail = (string)($_POST['to_email'] ?? $clientEmail);
$subject = (string)($_POST['subject'] ?? $defaultSubject);
$body = (string)($_POST['body'] ?? $defaultBody);
$includePdf = $_SERVER['REQUEST_METHOD'] !== 'POST' || !empty($_POST['include_pdf']);
$errors = [];
$successMessage = '';
$mailerAvailable = ensureMailerLoaded();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $toEmail = trim($toEmail);
    $subject = trim($subject);
    $body = trim($body);
    $attachmentForLog = $includePdf ? $pdfRelativePath : null;
    $sentBy = (int)($currentUser['id'] ?? 0) ?: null;

    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Niepoprawny token CSRF.';
    }
    if ((string)$document['status'] !== 'issued') {
        $errors[] = 'Dokument można wysłać tylko w statusie Wystawiony.';
    }
    if (in_array((string)$document['status'], ['accepted', 'cancelled'], true)) {
        $errors[] = documentClosedMessage();
    }
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Podaj poprawny adres e-mail odbiorcy.';
    }
    if ($subject === '') {
        $errors[] = 'Temat wiadomości jest wymagany.';
    }
    if ($body === '') {
        $errors[] = 'Treść wiadomości jest wymagana.';
    }
    if ($pdfRelativePath === '' || $pdfFullPath === '') {
        $errors[] = 'Brak pliku PDF na dysku. Wygeneruj PDF ponownie przed wysyłką.';
    }
    if (!$mailerAvailable) {
        $errors[] = 'Biblioteka PHPMailer nie jest dostępna.';
    }

    if (!$errors) {
        try {
            $acceptanceToken = '';
            $systemCfg = $pdo->query('SELECT * FROM konfiguracja_systemu WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
            $smtpConfig = resolveSmtpConfig($systemCfg, $currentUser);

            if (trim((string)($smtpConfig['from_email'] ?? '')) === '') {
                $smtpConfig['from_email'] = trim((string)($currentUser['email'] ?? ''));
            }
            if (trim((string)($smtpConfig['from_name'] ?? '')) === '') {
                $userName = trim((string)($currentUser['imie'] ?? '') . ' ' . (string)($currentUser['nazwisko'] ?? ''));
                $smtpConfig['from_name'] = $userName !== '' ? $userName : (string)($currentUser['login'] ?? $companyName);
            }
            if (trim((string)($smtpConfig['host'] ?? '')) === '') {
                throw new RuntimeException('Brak hosta SMTP w konfiguracji.');
            }
            if (trim((string)($smtpConfig['from_email'] ?? '')) === '') {
                throw new RuntimeException('Brak adresu nadawcy.');
            }

            $attachments = [];
            if ($includePdf) {
                $attachments[] = [
                    'path' => $pdfFullPath,
                    'filename' => $pdfFilename !== '' ? $pdfFilename : ('dokument_' . $documentNumber . '.pdf'),
                    'mime_type' => 'application/pdf',
                ];
            }

            $acceptanceToken = createDocumentAcceptanceToken($pdo, $id, $toEmail, (int)($currentUser['id'] ?? 0));
            $acceptanceLink = documentAcceptanceUrl($acceptanceToken);
            $bodyToSend = str_replace('{ACCEPTANCE_LINK}', $acceptanceLink, $body);
            if ($bodyToSend === $body && strpos($body, $acceptanceLink) === false) {
                $bodyToSend = rtrim($body) . "\n\nLink do pobrania i akceptacji dokumentu:\n" . $acceptanceLink;
            }

            $sendResult = sendCrmEmail(
                [
                    'archive_enabled' => !empty($systemCfg['crm_archive_enabled']),
                    'archive_bcc_email' => trim((string)($systemCfg['crm_archive_bcc_email'] ?? '')),
                ],
                $smtpConfig,
                [
                    'to' => [$toEmail],
                    'subject' => $subject,
                    'body_html' => nl2br(documentSendH($bodyToSend)),
                    'body_text' => $bodyToSend,
                ],
                $attachments
            );

            if (empty($sendResult['ok'])) {
                throw new RuntimeException((string)($sendResult['error'] ?? 'Nie udało się wysłać wiadomości.'));
            }

            transitionDocumentStatus($pdo, $id, 'sent', [
                'accepted_by_name' => '',
                'accepted_by_email' => '',
                'user_id' => $sentBy,
            ]);
            documentSendLog($pdo, $id, $toEmail, $subject, $bodyToSend, $attachmentForLog, $sentBy, 'sent');
            logDocumentAudit($pdo, $id, 'document_email_sent', 'Wyslano dokument e-mailem', [
                'user_id' => $sentBy,
                'metadata' => [
                    'recipient_email' => $toEmail,
                    'subject' => $subject,
                    'attachment_path' => $attachmentForLog,
                ],
            ]);
            header('Location: ' . BASE_URL . '/dokument_podglad.php?id=' . $id . '&mail=sent');
            exit;
        } catch (Throwable $e) {
            if (!empty($acceptanceToken)) {
                try {
                    $pdo->prepare('UPDATE document_acceptance_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE token_hash = :token_hash AND used_at IS NULL AND revoked_at IS NULL')
                        ->execute([':token_hash' => documentAcceptanceTokenHash($acceptanceToken)]);
                } catch (Throwable $revokeError) {
                    error_log('dokument_wyslij.php: cannot revoke failed-send token: ' . $revokeError->getMessage());
                }
            }
            $message = $e->getMessage() !== '' ? $e->getMessage() : 'Nie udało się wysłać wiadomości.';
            $errors[] = $message;
            documentSendLog($pdo, $id, $toEmail, $subject, $body, $attachmentForLog, $sentBy, 'failed', $message);
            logDocumentAudit($pdo, $id, 'document_email_failed', 'Nieudana wysylka dokumentu e-mailem', [
                'user_id' => $sentBy,
                'metadata' => [
                    'recipient_email' => $toEmail,
                    'subject' => $subject,
                    'attachment_path' => $attachmentForLog,
                    'error_message' => $message,
                ],
            ]);
        }
    } else {
        documentSendLog($pdo, $id, $toEmail, $subject, $body, $attachmentForLog, $sentBy, 'failed', implode(' ', $errors));
        logDocumentAudit($pdo, $id, 'document_email_failed', 'Nieudana wysylka dokumentu e-mailem', [
            'user_id' => $sentBy,
            'metadata' => [
                'recipient_email' => $toEmail,
                'subject' => $subject,
                'attachment_path' => $attachmentForLog,
                'error_message' => implode(' ', $errors),
            ],
        ]);
    }
}

$pageTitle = 'Wyślij dokument do klienta';
$csrfToken = getCsrfToken();
include __DIR__ . '/includes/header.php';
?>

<main class="container py-4" role="main" aria-labelledby="send-document-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Dokumenty</p>
            <h1 id="send-document-heading" class="h3 mb-2">Wyślij dokument do klienta</h1>
            <p class="text-muted mb-0"><?= documentSendH($documentNumber) ?> · <?= documentSendH($document['client_name'] ?: '-') ?></p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= documentSendH(BASE_URL . '/dokument_podglad.php?id=' . $id) ?>">Wróć do podglądu</a>
    </div>

    <?php if ((string)$document['status'] !== 'issued'): ?>
        <div class="alert alert-warning">Dokument można wysłać tylko w statusie Wystawiony.</div>
    <?php endif; ?>
    <?php if ($clientEmail === '' || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)): ?>
        <div class="alert alert-warning">Klient nie ma poprawnego adresu e-mail w kartotece.</div>
    <?php endif; ?>
    <?php if ($pdfRelativePath === '' || $pdfFullPath === ''): ?>
        <div class="alert alert-danger">Brak pliku PDF na dysku. Wygeneruj PDF przed wysyłką.</div>
    <?php endif; ?>
    <?php if (!$mailerAvailable): ?>
        <div class="alert alert-warning">Biblioteka PHPMailer nie jest dostępna.</div>
    <?php endif; ?>
    <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= documentSendH($error) ?></div>
    <?php endforeach; ?>

    <form method="post" class="card shadow-sm">
        <input type="hidden" name="csrf_token" value="<?= documentSendH($csrfToken) ?>">
        <input type="hidden" name="document_id" value="<?= (int)$id ?>">
        <div class="card-body">
            <div class="mb-3">
                <label class="form-label" for="to_email">Adres e-mail klienta</label>
                <input class="form-control" type="email" id="to_email" name="to_email" required value="<?= documentSendH($toEmail) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="subject">Temat wiadomości</label>
                <input class="form-control" type="text" id="subject" name="subject" required value="<?= documentSendH($subject) ?>">
            </div>
            <div class="mb-3">
                <label class="form-label" for="body">Treść wiadomości</label>
                <textarea class="form-control" id="body" name="body" rows="8" required><?= documentSendH($body) ?></textarea>
            </div>
            <div class="form-check mb-2">
                <input class="form-check-input" type="checkbox" name="include_pdf" id="include_pdf" value="1" <?= $includePdf ? 'checked' : '' ?>>
                <label class="form-check-label" for="include_pdf">Dołącz PDF dokumentu</label>
            </div>
            <div class="alert alert-light border mb-0">
                Plik PDF: <code><?= documentSendH($pdfFilename ?: ($pdfRelativePath ?: '-')) ?></code>
            </div>
        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a class="btn btn-outline-secondary" href="<?= documentSendH(BASE_URL . '/dokument_podglad.php?id=' . $id) ?>">Anuluj</a>
            <button class="btn btn-primary" type="submit" <?= ((string)$document['status'] !== 'issued' || $pdfFullPath === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL) || !$mailerAvailable) ? 'disabled' : '' ?>>Wyślij</button>
        </div>
    </form>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
