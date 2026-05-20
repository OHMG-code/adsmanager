<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/document_acceptance.php';
require_once __DIR__ . '/includes/document_audit.php';

ensureDocumentAcceptanceTables($pdo);
ensureDocumentAuditLogTable($pdo);

$documentId = (int)($_POST['document_id'] ?? 0);
if ($documentId <= 0) {
    http_response_code(400);
    echo 'Niepoprawny dokument.';
    exit;
}

if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    http_response_code(400);
    echo 'Niepoprawny token CSRF.';
    exit;
}

$stmt = $pdo->prepare('UPDATE document_acceptance_tokens
    SET revoked_at = CURRENT_TIMESTAMP
    WHERE document_id = :document_id
      AND used_at IS NULL
      AND revoked_at IS NULL
      AND expires_at > CURRENT_TIMESTAMP
    ORDER BY created_at DESC, id DESC
    LIMIT 1');
$stmt->execute([':document_id' => $documentId]);
logDocumentAudit($pdo, $documentId, 'document_token_revoked', 'Uniewazniono link akceptacji online', [
    'user_id' => (int)($_SESSION['user_id'] ?? 0),
    'metadata' => [
        'affected_rows' => $stmt->rowCount(),
    ],
]);

header('Location: ' . BASE_URL . '/dokument_podglad.php?id=' . $documentId . '&token=revoked');
exit;
