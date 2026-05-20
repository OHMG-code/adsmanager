<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/document_audit.php';

ensureDocumentAuditLogTable($pdo);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    echo 'Nie znaleziono dokumentu.';
    exit;
}

$stmt = $pdo->prepare('SELECT id, document_number, title FROM documents WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $id]);
$document = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
$stmt->closeCursor();
if (!$document) {
    http_response_code(404);
    echo 'Nie znaleziono dokumentu.';
    exit;
}

$stmt = $pdo->prepare("SELECT a.*, u.login, u.imie, u.nazwisko
    FROM document_audit_log a
    LEFT JOIN uzytkownicy u ON u.id = a.user_id
    WHERE a.document_id = :document_id
    ORDER BY a.created_at DESC, a.id DESC");
$stmt->execute([':document_id' => $id]);
$auditLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt->closeCursor();

function historyH($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function historyMeta(?string $json): string
{
    $json = trim((string)$json);
    if ($json === '') {
        return '-';
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $json;
    }
    return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?: '-';
}

$pageTitle = 'Historia dokumentu';
include __DIR__ . '/includes/header.php';
?>

<main class="container py-4" role="main" aria-labelledby="document-history-heading">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Dokumenty</p>
            <h1 id="document-history-heading" class="h3 mb-2">Historia dokumentu</h1>
            <p class="text-muted mb-0"><?= historyH($document['document_number']) ?> · <?= historyH($document['title'] ?: '-') ?></p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= historyH(BASE_URL . '/dokument_podglad.php?id=' . $id) ?>">Wroc do dokumentu</a>
    </div>

    <section class="card shadow-sm">
        <div class="card-body">
            <?php if (!$auditLogs): ?>
                <p class="text-muted mb-0">Brak wpisow audytu dla tego dokumentu.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Uzytkownik</th>
                                <th>Typ</th>
                                <th>Opis</th>
                                <th>Stara wartosc</th>
                                <th>Nowa wartosc</th>
                                <th>IP</th>
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
                                    <td><?= historyH($audit['created_at']) ?></td>
                                    <td><?= historyH($auditUser !== '' ? $auditUser : '-') ?></td>
                                    <td><?= historyH($audit['event_type']) ?></td>
                                    <td><?= historyH($audit['event_label']) ?></td>
                                    <td><?= historyH($audit['old_value'] ?: '-') ?></td>
                                    <td><?= historyH($audit['new_value'] ?: '-') ?></td>
                                    <td><?= historyH($audit['ip_address'] ?: '-') ?></td>
                                    <td><pre class="small mb-0" style="white-space:pre-wrap;max-width:420px;"><?= historyH(historyMeta($audit['metadata_json'] ?? null)) ?></pre></td>
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
