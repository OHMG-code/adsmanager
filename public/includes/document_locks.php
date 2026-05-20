<?php
declare(strict_types=1);

require_once __DIR__ . '/document_audit.php';

function isDocumentClosedStatus(string $status): bool
{
    return in_array($status, ['accepted', 'cancelled'], true);
}

function documentClosedMessage(): string
{
    return 'Dokument jest zamknięty i nie może być edytowany.';
}

function logDocumentLockedEditAttempt(PDO $pdo, int $documentId, string $action, array $context = []): void
{
    if ($documentId <= 0) {
        return;
    }
    logDocumentAudit($pdo, $documentId, 'document_locked_edit_attempt', documentClosedMessage(), [
        'user_id' => (int)($context['user_id'] ?? ($_SESSION['user_id'] ?? 0)),
        'metadata' => ['action' => $action] + (array)($context['metadata'] ?? []),
        'ip_address' => $context['ip_address'] ?? ($_SERVER['REMOTE_ADDR'] ?? null),
        'user_agent' => $context['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? null),
    ]);
}

function assertDocumentNotClosed(PDO $pdo, int $documentId, string $status, string $action): void
{
    if (isDocumentClosedStatus($status)) {
        logDocumentLockedEditAttempt($pdo, $documentId, $action);
        throw new RuntimeException(documentClosedMessage());
    }
}
