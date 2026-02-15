<?php
require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/lead_pipeline_helpers.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];
$pageTitle = "Do kontaktu dzisiaj";
include 'includes/header.php';

ensureLeadColumns($pdo);
ensureLeadActivityTable($pdo);

$role = normalizeRole($currentUser);
$leadCols = getTableColumns($pdo, 'leady');
$nextActionColumn = hasColumn($leadCols, 'next_action_at') ? 'next_action_at' : 'next_action_date';

$excludeStatuses = [
    'Wygrana',
    'Przegrana',
    'skonwertowany',
    'odrzucony',
    'zakonczony',
];
$placeholders = implode(',', array_fill(0, count($excludeStatuses), '?'));

$where = [];
$params = [];
if ($nextActionColumn === 'next_action_at') {
    $where[] = "{$nextActionColumn} IS NOT NULL AND {$nextActionColumn} <= CONCAT(CURDATE(), ' 23:59:59')";
} else {
    $where[] = "{$nextActionColumn} IS NOT NULL AND {$nextActionColumn} <= CURDATE()";
}
$where[] = "status NOT IN ($placeholders)";
$params = array_merge($params, $excludeStatuses);

if ($role === 'Handlowiec') {
    $where[] = 'owner_user_id = ?';
    $params[] = (int)$currentUser['id'];
}

$sql = "SELECT id, nazwa_firmy, status, priority, next_action, {$nextActionColumn} AS next_action_at
    FROM leady
    WHERE " . implode(' AND ', $where);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

usort($leads, function(array $a, array $b): int {
    $pa = leadPriorityRank($a['priority'] ?? '');
    $pb = leadPriorityRank($b['priority'] ?? '');
    if ($pa !== $pb) {
        return $pa <=> $pb;
    }
    $ta = $a['next_action_at'] ?? '';
    $tb = $b['next_action_at'] ?? '';
    return strcmp((string)$ta, (string)$tb);
});
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Do kontaktu dzisiaj</h1>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <?php if (!$leads): ?>
                <div class="text-muted">Brak leadów do kontaktu na dziś.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead>
                            <tr>
                                <th>Lead</th>
                                <th>Priorytet</th>
                                <th>Follow-up</th>
                                <th>Termin</th>
                                <th>Akcje</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($leads as $lead): ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($lead['nazwa_firmy'] ?? '') ?></strong><br>
                                        <small class="text-muted"><?= htmlspecialchars(normalizeLeadStatus($lead['status'] ?? '')) ?></small>
                                    </td>
                                    <td><span class="badge bg-secondary"><?= htmlspecialchars($lead['priority'] ?? 'Średni') ?></span></td>
                                    <td><?= htmlspecialchars($lead['next_action'] ?? '—') ?></td>
                                    <td><?= htmlspecialchars(formatNextActionRelative($lead['next_action_at'] ?? null)) ?></td>
                                    <td>
                                        <a class="btn btn-sm btn-outline-secondary" href="lead.php?lead_edit_id=<?= (int)$lead['id'] ?>#lead-form">Otwórz</a>
                                        <button class="btn btn-sm btn-outline-success ms-1 followup-done" data-id="<?= (int)$lead['id'] ?>">Oznacz wykonane</button>
                                        <select class="form-select form-select-sm d-inline-block w-auto ms-1 followup-snooze" data-id="<?= (int)$lead['id'] ?>">
                                            <option value="">Przesuń o...</option>
                                            <option value="1">+1 dzień</option>
                                            <option value="2">+2 dni</option>
                                            <option value="3">+3 dni</option>
                                            <option value="7">+7 dni</option>
                                        </select>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    function sendUpdate(payload) {
        return fetch('api/lead_followup_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(r => r.json());
    }

    document.querySelectorAll('.followup-done').forEach(btn => {
        btn.addEventListener('click', () => {
            const leadId = btn.dataset.id;
            sendUpdate({ lead_id: leadId, action: 'done' })
                .then(resp => {
                    if (!resp.success) throw new Error(resp.error || 'Błąd');
                    location.reload();
                })
                .catch(err => alert(err.message || 'Nie udało się zaktualizować.'));
        });
    });

    document.querySelectorAll('.followup-snooze').forEach(select => {
        select.addEventListener('change', () => {
            const days = parseInt(select.value, 10);
            if (!days) return;
            const leadId = select.dataset.id;
            sendUpdate({ lead_id: leadId, action: 'snooze', days: days })
                .then(resp => {
                    if (!resp.success) throw new Error(resp.error || 'Błąd');
                    location.reload();
                })
                .catch(err => alert(err.message || 'Nie udało się zaktualizować.'));
        });
    });
})();
</script>

<?php include 'includes/footer.php'; ?>


