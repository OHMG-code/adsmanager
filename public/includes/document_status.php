<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/document_campaign_sync.php';
require_once __DIR__ . '/document_audit.php';
require_once __DIR__ . '/document_locks.php';

function documentStatusLabels(): array
{
    return [
        'draft' => 'Roboczy',
        'issued' => 'Wystawiony',
        'sent' => 'Wysłany',
        'accepted' => 'Zaakceptowany',
        'cancelled' => 'Anulowany',
    ];
}

function documentStatusActionLabels(): array
{
    return [
        'issued' => 'Oznacz jako wystawiony',
        'sent' => 'Oznacz jako wysłany',
        'accepted' => 'Oznacz jako zaakceptowany',
        'cancelled' => 'Anuluj dokument',
    ];
}

function documentStatusBadgeClass(string $status): string
{
    return [
        'draft' => 'text-bg-secondary',
        'issued' => 'text-bg-primary',
        'sent' => 'text-bg-info',
        'accepted' => 'text-bg-success',
        'cancelled' => 'text-bg-danger',
    ][$status] ?? 'text-bg-light';
}

function canTransitionDocumentStatus(string $from, string $to): bool
{
    $transitions = [
        'draft' => ['issued', 'cancelled'],
        'issued' => ['sent', 'cancelled'],
        'sent' => ['accepted', 'cancelled'],
        'accepted' => [],
        'cancelled' => [],
    ];

    return in_array($to, $transitions[$from] ?? [], true);
}

function transitionDocumentStatus(PDO $pdo, int $documentId, string $newStatus, array $context = []): array
{
    $newStatus = trim($newStatus);
    if (!array_key_exists($newStatus, documentStatusLabels())) {
        throw new InvalidArgumentException('Niepoprawny status dokumentu.');
    }
    if ($documentId <= 0) {
        throw new InvalidArgumentException('Niepoprawny identyfikator dokumentu.');
    }

    if (!$pdo->inTransaction()) {
        ensureSalesDocumentsTable($pdo);
        ensureDocumentCampaignSyncLogTable($pdo);
        ensureDocumentAuditLogTable($pdo);
    }

    $startedTransaction = false;
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
        $startedTransaction = true;
    }

    try {
        $lockSql = isSqliteDriver($pdo)
            ? 'SELECT * FROM documents WHERE id = :id LIMIT 1'
            : 'SELECT * FROM documents WHERE id = :id LIMIT 1 FOR UPDATE';
        $stmt = $pdo->prepare($lockSql);
        $stmt->execute([':id' => $documentId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();

        if (!$document) {
            throw new RuntimeException('Nie znaleziono dokumentu.');
        }

        $currentStatus = (string)($document['status'] ?? '');
        if (isDocumentClosedStatus($currentStatus)) {
            logDocumentLockedEditAttempt($pdo, $documentId, 'status_change', [
                'metadata' => ['requested_status' => $newStatus],
                'user_id' => (int)($context['user_id'] ?? ($_SESSION['user_id'] ?? 0)),
            ]);
        }
        if (!canTransitionDocumentStatus($currentStatus, $newStatus)) {
            throw new RuntimeException('Niedozwolona zmiana statusu dokumentu.');
        }

        if ($newStatus === 'accepted') {
            $acceptedByName = trim((string)($context['accepted_by_name'] ?? ''));
            $acceptedByEmail = trim((string)($context['accepted_by_email'] ?? ''));
            $acceptanceIp = trim((string)($context['acceptance_ip'] ?? ''));
            $acceptanceUserAgent = trim((string)($context['acceptance_user_agent'] ?? ''));

            $stmt = $pdo->prepare("UPDATE documents
                SET status = :status,
                    accepted_at = CURRENT_TIMESTAMP,
                    accepted_by_name = :accepted_by_name,
                    accepted_by_email = :accepted_by_email,
                    acceptance_ip = :acceptance_ip,
                    acceptance_user_agent = :acceptance_user_agent,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id");
            $stmt->execute([
                ':status' => $newStatus,
                ':accepted_by_name' => $acceptedByName !== '' ? $acceptedByName : null,
                ':accepted_by_email' => $acceptedByEmail !== '' ? $acceptedByEmail : null,
                ':acceptance_ip' => $acceptanceIp !== '' ? substr($acceptanceIp, 0, 45) : null,
                ':acceptance_user_agent' => $acceptanceUserAgent !== '' ? substr($acceptanceUserAgent, 0, 255) : null,
                ':id' => $documentId,
            ]);
        } else {
            $stmt = $pdo->prepare('UPDATE documents SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
            $stmt->execute([
                ':status' => $newStatus,
                ':id' => $documentId,
            ]);
        }

        logDocumentAudit($pdo, $documentId, 'document_status_changed', 'Zmieniono status dokumentu', [
            'user_id' => (int)($context['user_id'] ?? ($_SESSION['user_id'] ?? 0)),
            'old_value' => $currentStatus,
            'new_value' => $newStatus,
            'metadata' => [
                'document_type' => (string)($document['document_type'] ?? ''),
                'accepted_by_email' => $newStatus === 'accepted' ? ($context['accepted_by_email'] ?? null) : null,
            ],
            'ip_address' => $context['acceptance_ip'] ?? null,
            'user_agent' => $context['acceptance_user_agent'] ?? null,
        ]);

        if (in_array($newStatus, ['accepted', 'cancelled'], true)) {
            syncCampaignWithDocument($pdo, $documentId);
        }

        if ($startedTransaction) {
            $pdo->commit();
        }

        $document['status'] = $newStatus;
        return $document;
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
