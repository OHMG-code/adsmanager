<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/document_acceptance.php';
require_once __DIR__ . '/includes/document_audit.php';
require_once __DIR__ . '/includes/document_pdf_versions.php';
require_once __DIR__ . '/includes/email_templates.php';
require_once __DIR__ . '/includes/mail_service.php';
require_once __DIR__ . '/includes/document_locks.php';

ensureDocumentAcceptanceTables($pdo);
ensureDocumentAuditLogTable($pdo);
ensureDocumentPdfVersionsTable($pdo);
ensureEmailTemplatesTable($pdo);
ensureSystemConfigColumns($pdo);
ensureUserColumns($pdo);

$documentId = (int)($_POST['document_id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $documentId <= 0 || !isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo 'Niepoprawne żądanie.';
    exit;
}

function reminderFail(PDO $pdo, int $documentId, string $message, ?int $userId = null): void
{
    logDocumentAudit($pdo, $documentId, 'document_acceptance_reminder_failed', 'Nieudane przypomnienie o akceptacji', [
        'user_id' => $userId,
        'metadata' => ['error_message' => $message],
    ]);
}

try {
    $stmt = $pdo->prepare("SELECT d.*, k.nazwa_firmy AS client_name, k.email AS client_email, cp.company_name AS owner_company_name
        FROM documents d
        LEFT JOIN klienci k ON k.id = d.client_id
        LEFT JOIN company_profile cp ON cp.id = d.company_profile_id
        WHERE d.id = :id
        LIMIT 1");
    $stmt->execute([':id' => $documentId]);
    $document = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$document) {
        throw new RuntimeException('Nie znaleziono dokumentu.');
    }
    assertDocumentNotClosed($pdo, $documentId, (string)$document['status'], 'send_acceptance_reminder');
    if ((string)$document['status'] !== 'sent') {
        throw new RuntimeException('Przypomnienie można wysłać tylko dla dokumentu wysłanego.');
    }

    $stmt = $pdo->prepare('SELECT action FROM document_acceptance_log WHERE document_id = :document_id ORDER BY created_at DESC, id DESC LIMIT 1');
    $stmt->execute([':document_id' => $documentId]);
    if ((string)($stmt->fetchColumn() ?: '') === 'rejected') {
        throw new RuntimeException('Nie można wysłać przypomnienia dla dokumentu odrzuconego przez klienta.');
    }

    $currentVersion = getCurrentDocumentPdfVersion($pdo, $documentId);
    $pdfPath = $currentVersion ? documentPdfResolvePath($currentVersion['pdf_path'] ?? '') : documentAcceptancePdfPath($document['pdf_path'] ?? null, $documentId);
    $pdfFileName = $currentVersion ? (string)$currentVersion['file_name'] : basename((string)($document['pdf_path'] ?? ''));
    if (!$pdfPath) {
        throw new RuntimeException('Brak pliku PDF dokumentu.');
    }

    $user = fetchCurrentUser($pdo);
    $userId = (int)($user['id'] ?? ($_SESSION['user_id'] ?? 0)) ?: null;
    $recipient = trim((string)($document['client_email'] ?? ''));
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Klient nie ma poprawnego adresu e-mail.');
    }

    $activeToken = getActiveDocumentAcceptanceToken($pdo, $documentId);
    $token = null;
    if ($activeToken) {
        $stmt = $pdo->prepare("SELECT body FROM document_email_log
            WHERE document_id = :document_id AND status = 'sent' AND body LIKE '%akceptacja_dokumentu.php?t=%'
            ORDER BY sent_at DESC, created_at DESC, id DESC
            LIMIT 10");
        $stmt->execute([':document_id' => $documentId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $loggedBody) {
            if (preg_match_all('/[?&]t=([A-Fa-f0-9]{64,})/', (string)$loggedBody, $matches)) {
                foreach ($matches[1] as $candidate) {
                    if (hash_equals((string)$activeToken['token_hash'], documentAcceptanceTokenHash($candidate))) {
                        $token = $candidate;
                        break 2;
                    }
                }
            }
        }
        if ($token === null) {
            throw new RuntimeException('Aktywny token istnieje, ale nie znaleziono linku w historii wysyłek.');
        }
    } else {
        $token = createDocumentAcceptanceToken($pdo, $documentId, $recipient, (int)$userId);
    }
    $acceptanceLink = documentAcceptanceUrl($token);

    $companyName = trim((string)($document['owner_company_name'] ?? '')) ?: 'CRM';
    $vars = [
        'DOCUMENT_NUMBER' => (string)$document['document_number'],
        'CLIENT_NAME' => (string)($document['client_name'] ?? ''),
        'COMPANY_NAME' => $companyName,
        'ACCEPTANCE_LINK' => $acceptanceLink,
        'PDF_FILE_NAME' => $pdfFileName,
    ];
    $template = getActiveEmailTemplate($pdo, 'document_acceptance_reminder');
    $subject = $template ? renderEmailTemplate((string)$template['subject_template'], $vars) : 'Przypomnienie o akceptacji dokumentu ' . $document['document_number'];
    $body = $template ? renderEmailTemplate((string)$template['body_template'], $vars) : "Dzień dobry,\n\nprzypominamy o dokumencie {$document['document_number']} oczekującym na akceptację.\n\n{$acceptanceLink}\n\nPozdrawiamy,\n{$companyName}";

    if (!ensureMailerLoaded()) {
        throw new RuntimeException('Biblioteka PHPMailer nie jest dostępna.');
    }
    $systemCfg = $pdo->query('SELECT * FROM konfiguracja_systemu WHERE id = 1 LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: [];
    $smtpConfig = resolveSmtpConfig($systemCfg, $user ?: []);
    if (trim((string)($smtpConfig['from_email'] ?? '')) === '') {
        $smtpConfig['from_email'] = trim((string)($user['email'] ?? ''));
    }
    if (trim((string)($smtpConfig['from_name'] ?? '')) === '') {
        $smtpConfig['from_name'] = trim((string)($user['login'] ?? $companyName));
    }
    if (trim((string)($smtpConfig['host'] ?? '')) === '' || trim((string)($smtpConfig['from_email'] ?? '')) === '') {
        throw new RuntimeException('Brak konfiguracji SMTP.');
    }

    $result = sendCrmEmail(
        [
            'archive_enabled' => !empty($systemCfg['crm_archive_enabled']),
            'archive_bcc_email' => trim((string)($systemCfg['crm_archive_bcc_email'] ?? '')),
        ],
        $smtpConfig,
        [
            'to' => [$recipient],
            'subject' => $subject,
            'body_html' => nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
            'body_text' => $body,
        ],
        [['path' => $pdfPath, 'filename' => $pdfFileName, 'mime_type' => 'application/pdf']]
    );
    if (empty($result['ok'])) {
        throw new RuntimeException((string)($result['error'] ?? 'Nie udało się wysłać przypomnienia.'));
    }

    logDocumentAudit($pdo, $documentId, 'document_acceptance_reminder_sent', 'Wysłano przypomnienie o akceptacji', [
        'user_id' => $userId,
        'metadata' => ['recipient_email' => $recipient, 'subject' => $subject, 'pdf_file_name' => $pdfFileName],
    ]);
    header('Location: ' . BASE_URL . '/dokument_podglad.php?id=' . $documentId . '&reminder=sent');
    exit;
} catch (Throwable $e) {
    reminderFail($pdo, $documentId, $e->getMessage(), (int)($_SESSION['user_id'] ?? 0) ?: null);
    header('Location: ' . BASE_URL . '/dokument_podglad.php?id=' . $documentId . '&status_error=' . urlencode($e->getMessage()));
    exit;
}
