<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/document_status.php';

ensureSalesDocumentsTable($pdo);
ensureDocumentAcceptanceTables($pdo);
ensureDocumentEmailLogTable($pdo);
ensureDocumentCampaignSyncLogTable($pdo);

function docAlertsH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function docAlertsFetch(PDO $pdo, string $sql): array
{
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$alerts = [
    'sent_old' => [
        'title' => 'Wysłane bez akceptacji dłużej niż 3 dni',
        'rows' => docAlertsFetch($pdo, "SELECT d.id, d.document_number, d.status, d.issue_date, k.nazwa_firmy AS client_name, MAX(e.sent_at) AS marker
            FROM documents d
            LEFT JOIN klienci k ON k.id = d.client_id
            LEFT JOIN document_email_log e ON e.document_id = d.id AND e.status = 'sent'
            LEFT JOIN document_acceptance_log la ON la.id = (SELECT id FROM document_acceptance_log WHERE document_id = d.id ORDER BY created_at DESC, id DESC LIMIT 1)
            WHERE d.status = 'sent' AND (la.action IS NULL OR la.action NOT IN ('accepted','rejected'))
            GROUP BY d.id
            HAVING marker IS NOT NULL AND marker < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 3 DAY)
            ORDER BY marker ASC LIMIT 100"),
    ],
    'token_expiring' => [
        'title' => 'Token wygasa w ciągu 2 dni',
        'rows' => docAlertsFetch($pdo, "SELECT d.id, d.document_number, d.status, d.issue_date, k.nazwa_firmy AS client_name, t.expires_at AS marker
            FROM document_acceptance_tokens t
            INNER JOIN documents d ON d.id = t.document_id
            LEFT JOIN klienci k ON k.id = d.client_id
            WHERE d.status = 'sent' AND t.used_at IS NULL AND t.revoked_at IS NULL
              AND t.expires_at BETWEEN CURRENT_TIMESTAMP AND DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 2 DAY)
            ORDER BY t.expires_at ASC LIMIT 100"),
    ],
    'rejected' => [
        'title' => 'Odrzucone przez klienta',
        'rows' => docAlertsFetch($pdo, "SELECT d.id, d.document_number, d.status, d.issue_date, k.nazwa_firmy AS client_name, la.created_at AS marker
            FROM documents d
            LEFT JOIN klienci k ON k.id = d.client_id
            INNER JOIN document_acceptance_log la ON la.id = (SELECT id FROM document_acceptance_log WHERE document_id = d.id ORDER BY created_at DESC, id DESC LIMIT 1)
            WHERE la.action = 'rejected'
            ORDER BY la.created_at DESC LIMIT 100"),
    ],
    'issued_not_sent' => [
        'title' => 'Wystawione bez wysyłki',
        'rows' => docAlertsFetch($pdo, "SELECT d.id, d.document_number, d.status, d.issue_date, k.nazwa_firmy AS client_name, d.updated_at AS marker
            FROM documents d
            LEFT JOIN klienci k ON k.id = d.client_id
            WHERE d.status = 'issued' AND NOT EXISTS (SELECT 1 FROM document_email_log e WHERE e.document_id = d.id AND e.status = 'sent')
            ORDER BY d.updated_at ASC LIMIT 100"),
    ],
    'old_drafts' => [
        'title' => 'Robocze starsze niż 7 dni',
        'rows' => docAlertsFetch($pdo, "SELECT d.id, d.document_number, d.status, d.issue_date, k.nazwa_firmy AS client_name, d.created_at AS marker
            FROM documents d
            LEFT JOIN klienci k ON k.id = d.client_id
            WHERE d.status = 'draft' AND d.created_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 7 DAY)
            ORDER BY d.created_at ASC LIMIT 100"),
    ],
    'accepted_campaign_inactive' => [
        'title' => 'Zaakceptowane bez aktywnej kampanii',
        'rows' => docAlertsFetch($pdo, "SELECT d.id, d.document_number, d.status, d.issue_date, k.nazwa_firmy AS client_name, d.campaign_id AS marker
            FROM documents d
            LEFT JOIN klienci k ON k.id = d.client_id
            LEFT JOIN kampanie c ON c.id = d.campaign_id
            WHERE d.status = 'accepted' AND (d.campaign_id IS NULL OR c.id IS NULL OR COALESCE(c.status, '') NOT IN ('active','Aktywna','aktywny','Aktywny'))
            ORDER BY d.accepted_at DESC LIMIT 100"),
    ],
    'campaign_sync_error' => [
        'title' => 'Błędy synchronizacji kampanii',
        'rows' => docAlertsFetch($pdo, "SELECT d.id, d.document_number, d.status, d.issue_date, k.nazwa_firmy AS client_name, l.created_at AS marker
            FROM document_campaign_sync_log l
            INNER JOIN documents d ON d.id = l.document_id
            LEFT JOIN klienci k ON k.id = d.client_id
            WHERE l.action LIKE '%error%' OR l.action = 'error'
            ORDER BY l.created_at DESC LIMIT 100"),
    ],
];

$pageTitle = 'Alerty dokumentów';
include __DIR__ . '/includes/header.php';
?>

<main class="container py-4">
    <div class="mb-4">
        <p class="text-uppercase text-muted fw-semibold small mb-1">Dokumenty</p>
        <h1 class="h3 mb-2">Alerty dokumentów</h1>
        <p class="text-muted mb-0">Lista dokumentów wymagających reakcji.</p>
    </div>

    <div class="row g-4">
        <?php foreach ($alerts as $alert): ?>
            <div class="col-12">
                <section class="card shadow-sm">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h2 class="h5 mb-0"><?= docAlertsH($alert['title']) ?></h2>
                            <span class="badge text-bg-secondary"><?= count($alert['rows']) ?></span>
                        </div>
                        <?php if (!$alert['rows']): ?>
                            <p class="text-muted mb-0">Brak dokumentów.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle mb-0">
                                    <thead><tr><th>Numer</th><th>Klient</th><th>Status</th><th>Data</th><th>Znacznik</th><th></th></tr></thead>
                                    <tbody>
                                        <?php foreach ($alert['rows'] as $row): ?>
                                            <tr>
                                                <td class="fw-semibold"><?= docAlertsH($row['document_number']) ?></td>
                                                <td><?= docAlertsH($row['client_name'] ?: '-') ?></td>
                                                <td><?= docAlertsH($row['status']) ?></td>
                                                <td><?= docAlertsH($row['issue_date'] ?: '-') ?></td>
                                                <td><?= docAlertsH($row['marker'] ?: '-') ?></td>
                                                <td class="text-end"><a class="btn btn-sm btn-outline-secondary" href="<?= docAlertsH(BASE_URL . '/dokument_podglad.php?id=' . (int)$row['id']) ?>">Podgląd</a></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
