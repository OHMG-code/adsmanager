<?php
require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/lead_pipeline_helpers.php';
require_once __DIR__ . '/includes/ui_helpers.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];
$pageTitle = "Pipeline sprzedaży";
include 'includes/header.php';

ensureLeadColumns($pdo);
ensureLeadActivityTable($pdo);

$role = normalizeRole($currentUser);
$ownerFilter = isset($_GET['owner_id']) && $_GET['owner_id'] !== '' ? (int)$_GET['owner_id'] : null;
$priorityFilter = isset($_GET['priority']) ? trim((string)$_GET['priority']) : '';

$leadCols = getTableColumns($pdo, 'leady');
$nextActionColumn = hasColumn($leadCols, 'next_action_at') ? 'next_action_at' : 'next_action_date';

$where = ['1=1'];
$params = [];

if ($role === 'Handlowiec') {
    $where[] = '(l.owner_user_id = :owner_id OR l.owner_user_id IS NULL OR l.owner_user_id = 0)';
    $params[':owner_id'] = (int)$currentUser['id'];
} elseif ($ownerFilter) {
    $where[] = 'l.owner_user_id = :owner_id';
    $params[':owner_id'] = $ownerFilter;
}

if ($priorityFilter !== '') {
    $where[] = 'l.priority = :priority';
    $params[':priority'] = $priorityFilter;
}

foreach (buildActiveLeadConditions($leadCols, 'l') as $activeCondition) {
    $where[] = $activeCondition;
}

$select = [
    'l.id',
    'l.nazwa_firmy',
    'l.status',
    'l.priority',
    'l.next_action',
    "l.{$nextActionColumn} AS next_action_at",
    'l.owner_user_id',
    'u.login AS owner_login',
    'u.imie AS owner_imie',
    'u.nazwisko AS owner_nazwisko',
];
if (hasColumn($leadCols, 'adres')) {
    $select[] = 'l.adres';
}
if (hasColumn($leadCols, 'miejscowosc')) {
    $select[] = 'l.miejscowosc';
}

$sql = 'SELECT ' . implode(', ', $select) . ' FROM leady l LEFT JOIN uzytkownicy u ON u.id = l.owner_user_id';
$sql .= ' WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$leads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusColumns = leadStandardStatuses();
$columns = [];
foreach ($statusColumns as $status) {
    $columns[$status] = [];
}

foreach ($leads as $lead) {
    $status = normalizeLeadStatus($lead['status'] ?? '');
    $lead['status_normalized'] = $status;
    $columns[$status][] = $lead;
}

foreach ($columns as $status => &$items) {
    usort($items, function(array $a, array $b): int {
        $pa = leadPriorityRank($a['priority'] ?? '');
        $pb = leadPriorityRank($b['priority'] ?? '');
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        $ta = $a['next_action_at'] ?? '';
        $tb = $b['next_action_at'] ?? '';
        return strcmp((string)$ta, (string)$tb);
    });
}
unset($items);

$owners = [];
if ($role !== 'Handlowiec') {
    $owners = $pdo->query("SELECT id, login, imie, nazwisko FROM uzytkownicy ORDER BY login")->fetchAll(PDO::FETCH_ASSOC);
}

$priorityOptions = array_keys(leadPriorityOrder());
?>

<div class="container-fluid">
    <div class="app-header">
        <div>
            <h1 class="app-title">Pipeline sprzedaży</h1>
            <div class="app-subtitle">Przepływ: Nowy -> Kontakt -> Oferta -> Wygrana -> Klient.</div>
        </div>
        <?php if ($role !== 'Handlowiec'): ?>
            <form method="get" class="app-header-actions">
                <div>
                    <label class="form-label">Owner</label>
                    <select name="owner_id" class="form-select form-select-sm">
                        <option value="">— wszyscy —</option>
                        <?php foreach ($owners as $owner): ?>
                            <option value="<?= (int)$owner['id'] ?>" <?= $ownerFilter === (int)$owner['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($owner['login'] ?? trim(($owner['imie'] ?? '') . ' ' . ($owner['nazwisko'] ?? ''))) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="form-label">Priorytet</label>
                    <select name="priority" class="form-select form-select-sm">
                        <option value="">— wszystkie —</option>
                        <?php foreach ($priorityOptions as $option): ?>
                            <option value="<?= htmlspecialchars($option) ?>" <?= $priorityFilter === $option ? 'selected' : '' ?>>
                                <?= htmlspecialchars($option) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn btn-sm btn-ghost">Filtruj</button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <div id="kanban-board" class="kanban">
        <?php foreach ($statusColumns as $status): ?>
            <div class="kanban-col" data-status="<?= htmlspecialchars($status) ?>">
                <div class="kanban-col-header">
                    <strong><?= htmlspecialchars(leadStatusLabel($status)) ?></strong>
                    <span class="badge badge-neutral"><?= count($columns[$status]) ?></span>
                </div>
                <div class="kanban-col-body" data-status="<?= htmlspecialchars($status) ?>">
                    <?php foreach ($columns[$status] as $lead): ?>
                        <?php
                        $ownerLabel = trim(($lead['owner_imie'] ?? '') . ' ' . ($lead['owner_nazwisko'] ?? ''));
                        if ($ownerLabel === '') {
                            $ownerLabel = $lead['owner_login'] ?? '—';
                        }
                        $addressParts = [];
                        if (!empty($lead['miejscowosc'])) {
                            $addressParts[] = $lead['miejscowosc'];
                        }
                        if (!empty($lead['adres'])) {
                            $addressParts[] = $lead['adres'];
                        }
                        $addressLabel = $addressParts ? implode(', ', $addressParts) : '—';
                        $nextLabel = formatNextActionRelative($lead['next_action_at'] ?? null);
                        $priority = $lead['priority'] ?? 'Średni';
                        ?>
                        <div class="kanban-card" draggable="true" data-lead-id="<?= (int)$lead['id'] ?>">
                            <div class="d-flex justify-content-between align-items-start mb-1">
                                <strong class="kanban-title"><?= htmlspecialchars($lead['nazwa_firmy'] ?? '') ?></strong>
                                <?= renderBadge('priority', (string)$priority) ?>
                            </div>
                            <div class="small text-muted mb-1"><?= htmlspecialchars($addressLabel) ?></div>
                            <div class="small mb-1"><strong>Owner:</strong> <?= htmlspecialchars($ownerLabel) ?></div>
                            <div class="small mb-1"><strong>Next:</strong> <?= htmlspecialchars($nextLabel) ?></div>
                            <?php if (!empty($lead['next_action'])): ?>
                                <div class="small text-muted"><?= htmlspecialchars($lead['next_action']) ?></div>
                            <?php endif; ?>
                            <?php if (isLeadStatusWon($status)): ?>
                                <div class="small text-success mt-2">Kolejny krok: zaakceptowany media plan zamień na klienta.</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
(function() {
    const board = document.getElementById('kanban-board');
    let draggingCard = null;
    if (!board) {
        return;
    }

    function postStatusChange(leadId, status) {
        return fetch('api/lead_update_status.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ lead_id: leadId, new_status: status })
        }).then(async r => {
            const text = await r.text();
            let payload = null;
            try {
                payload = text ? JSON.parse(text) : null;
            } catch (e) {
                payload = null;
            }
            if (!r.ok && (!payload || !payload.success)) {
                throw new Error((payload && payload.error) || ('HTTP ' + r.status));
            }
            return payload;
        });
    }

    function readDropStatus(column, target) {
        const fromBody = column && column.dataset ? column.dataset.status : '';
        if (fromBody) {
            return fromBody;
        }
        const bodyFromTarget = target && target.closest ? target.closest('.kanban-col-body') : null;
        if (bodyFromTarget && bodyFromTarget.dataset && bodyFromTarget.dataset.status) {
            return bodyFromTarget.dataset.status;
        }
        const colFromTarget = target && target.closest ? target.closest('.kanban-col') : null;
        if (colFromTarget && colFromTarget.dataset && colFromTarget.dataset.status) {
            return colFromTarget.dataset.status;
        }
        return '';
    }

    function readLeadId(dataTransfer) {
        let leadId = draggingCard && draggingCard.dataset ? draggingCard.dataset.leadId : '';
        if ((!leadId || leadId === '0') && dataTransfer) {
            leadId = dataTransfer.getData('text/plain');
        }
        const parsed = Number.parseInt(String(leadId || ''), 10);
        return Number.isInteger(parsed) && parsed > 0 ? parsed : 0;
    }

    board.addEventListener('dragstart', function(e) {
        const card = e.target && e.target.closest ? e.target.closest('.kanban-card') : null;
        if (!card) return;
        draggingCard = card;
        card.classList.add('dragging');
        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
            const leadId = card.dataset ? card.dataset.leadId : '';
            if (leadId) {
                e.dataTransfer.setData('text/plain', leadId);
            }
        }
    });

    board.addEventListener('dragend', function() {
        if (draggingCard) {
            draggingCard.classList.remove('dragging');
        }
        draggingCard = null;
        document.querySelectorAll('.kanban-col-body.drag-over').forEach(el => el.classList.remove('drag-over'));
    });

    board.querySelectorAll('.kanban-col-body').forEach(column => {
        column.addEventListener('dragover', function(e) {
            e.preventDefault();
            if (e.dataTransfer) {
                e.dataTransfer.dropEffect = 'move';
            }
            column.classList.add('drag-over');
        });
        column.addEventListener('dragleave', function() {
            column.classList.remove('drag-over');
        });
        column.addEventListener('drop', function(e) {
            e.preventDefault();
            column.classList.remove('drag-over');
            const card = draggingCard;
            if (!card) return;
            const status = readDropStatus(column, e.target);
            const leadId = readLeadId(e.dataTransfer || null);
            if (!status || !leadId) {
                alert('Nie udało się odczytać danych leada do przesunięcia. Odśwież stronę i spróbuj ponownie.');
                return;
            }
            postStatusChange(leadId, status)
                .then(payload => {
                    if (!payload || !payload.success) {
                        throw new Error((payload && payload.error) || 'Nie udało się zmienić statusu.');
                    }
                    column.prepend(card);
                })
                .catch(err => {
                    alert(err.message || 'Nie udało się zmienić statusu.');
                });
        });
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
