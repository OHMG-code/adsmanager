<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/lead_pipeline_helpers.php';
require_once __DIR__ . '/includes/tasks.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

ensureLeadColumns($pdo);
ensureLeadActivityTable($pdo);

$role = normalizeRole($currentUser);
$pageTitle = 'Do kontaktu i zadania';
$activeTab = strtolower(trim((string)($_GET['tab'] ?? 'contact')));
if (!in_array($activeTab, ['contact', 'tasks'], true)) {
    $activeTab = 'contact';
}

$taskFlash = $_SESSION['task_flash'] ?? null;
unset($_SESSION['task_flash']);

function normalizeTaskDateTimeInput(?string $input): ?string
{
    $input = trim((string)$input);
    if ($input === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d\\TH:i', $input, new DateTimeZone('Europe/Warsaw'));
    if (!$dt) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

function taskFlashTypeForCode(string $code): string
{
    switch ($code) {
        case 'success':
            return 'success';
        case 'forbidden':
            return 'warning';
        case 'already_closed':
            return 'info';
        case 'missing_object':
        case 'missing_task':
            return 'warning';
        case 'auth_required':
        case 'internal_error':
        case 'update_failed':
        case 'exception':
        default:
            return 'danger';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!isCsrfTokenValid($token)) {
        $taskFlash = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } else {
        $action = trim((string)($_POST['task_action'] ?? ''));
        $taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
        if ($taskId <= 0) {
            $taskFlash = ['type' => 'warning', 'msg' => 'Brak identyfikatora zadania.'];
        } else {
            $result = false;
            switch ($action) {
                case 'complete':
                    $result = completeTaskResult($taskId, (int)$currentUser['id']);
                    $taskFlash = [
                        'type' => taskFlashTypeForCode((string)($result['code'] ?? 'exception')),
                        'msg' => (string)($result['message'] ?? 'Nie udało się zakończyć zadania.'),
                    ];
                    break;
                case 'cancel':
                    $result = cancelTask($taskId, (int)$currentUser['id']);
                    $taskFlash = $result
                        ? ['type' => 'success', 'msg' => 'Zadanie anulowane.']
                        : ['type' => 'danger', 'msg' => 'Nie udalo sie anulowac zadania.'];
                    break;
                case 'postpone':
                    $newDueAt = normalizeTaskDateTimeInput($_POST['new_due_at'] ?? null);
                    if (!$newDueAt) {
                        $taskFlash = ['type' => 'warning', 'msg' => 'Podaj poprawny termin.'];
                        break;
                    }
                    $result = postponeTask($taskId, $newDueAt, (int)$currentUser['id']);
                    $taskFlash = $result
                        ? ['type' => 'success', 'msg' => 'Zadanie zostalo przelozone.']
                        : ['type' => 'danger', 'msg' => 'Nie udalo sie przelozyc zadania.'];
                    break;
                default:
                    $taskFlash = ['type' => 'warning', 'msg' => 'Nieznana akcja.'];
            }
        }
    }

    $_SESSION['task_flash'] = $taskFlash;
    header('Location: ' . BASE_URL . '/followup.php?tab=tasks');
    exit;
}

$leadCols = getTableColumns($pdo, 'leady');
$nextActionColumn = hasColumn($leadCols, 'next_action_at') ? 'next_action_at' : 'next_action_date';
$excludeStatuses = leadFollowupExcludedStatuses();
$placeholders = implode(',', array_fill(0, count($excludeStatuses), '?'));
$leadWhere = [];
$leadParams = [];
$leadWhere[] = "{$nextActionColumn} IS NOT NULL";
$leadWhere[] = "DATE({$nextActionColumn}) <= DATE_ADD(CURDATE(), INTERVAL 1 DAY)";
$leadWhere[] = "status NOT IN ({$placeholders})";
$leadParams = array_merge($leadParams, $excludeStatuses);

foreach (buildActiveLeadConditions($leadCols) as $activeCondition) {
    $leadWhere[] = $activeCondition;
}

if ($role === 'Handlowiec') {
    $leadWhere[] = '(owner_user_id = ? OR owner_user_id IS NULL OR owner_user_id = 0)';
    $leadParams[] = (int)$currentUser['id'];
}

$leadSql = "SELECT id, nazwa_firmy, status, priority, next_action, {$nextActionColumn} AS next_action_at,
        CASE
            WHEN DATE({$nextActionColumn}) < CURDATE() THEN 'overdue'
            WHEN DATE({$nextActionColumn}) = CURDATE() THEN 'today'
            WHEN DATE({$nextActionColumn}) = DATE_ADD(CURDATE(), INTERVAL 1 DAY) THEN 'tomorrow'
            ELSE 'other'
        END AS due_bucket
    FROM leady
    WHERE " . implode(' AND ', $leadWhere);
$leadStmt = $pdo->prepare($leadSql);
$leadStmt->execute($leadParams);
$contactLeads = $leadStmt->fetchAll(PDO::FETCH_ASSOC);

usort($contactLeads, function (array $a, array $b): int {
    $pa = leadPriorityRank($a['priority'] ?? '');
    $pb = leadPriorityRank($b['priority'] ?? '');
    if ($pa !== $pb) {
        return $pa <=> $pb;
    }
    $ta = (string)($a['next_action_at'] ?? '');
    $tb = (string)($b['next_action_at'] ?? '');
    return strcmp($ta, $tb);
});

$contactBuckets = [
    'overdue' => [],
    'today' => [],
    'tomorrow' => [],
];
foreach ($contactLeads as $lead) {
    $bucket = (string)($lead['due_bucket'] ?? '');
    if (isset($contactBuckets[$bucket])) {
        $contactBuckets[$bucket][] = $lead;
    }
}

$openTasks = getUserTasks($pdo, (int)$currentUser['id'], $role, 'open', 200);
$overdueTasks = getUserTasks($pdo, (int)$currentUser['id'], $role, 'overdue', 200);
$doneTasks = getUserTasks($pdo, (int)$currentUser['id'], $role, 'done', 200);
$now = new DateTime('now', new DateTimeZone('Europe/Warsaw'));

include __DIR__ . '/includes/header.php';
?>

<main class="content-wide py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">Kontakty i zadania</h1>
            <p class="text-muted mb-0">Jedna lista priorytetow handlowych: follow-up i zadania.</p>
        </div>
    </div>

    <ul class="nav nav-tabs mb-3">
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'contact' ? 'active' : '' ?>" href="followup.php?tab=contact">Do kontaktu</a>
        </li>
        <li class="nav-item">
            <a class="nav-link <?= $activeTab === 'tasks' ? 'active' : '' ?>" href="followup.php?tab=tasks">Zadania</a>
        </li>
    </ul>

    <?php if ($activeTab === 'contact'): ?>
        <?php
        $contactSections = [
            'overdue' => ['title' => 'Przeterminowane', 'empty' => 'Brak przeterminowanych kontaktow.'],
            'today' => ['title' => 'Na dzisiaj', 'empty' => 'Brak kontaktow na dzisiaj.'],
            'tomorrow' => ['title' => 'Na jutro', 'empty' => 'Brak kontaktow na jutro.'],
        ];
        ?>
        <?php foreach ($contactSections as $sectionKey => $sectionMeta): ?>
            <?php $sectionLeads = $contactBuckets[$sectionKey] ?? []; ?>
            <div class="card shadow-sm mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><?= htmlspecialchars($sectionMeta['title']) ?></strong>
                    <span class="badge bg-secondary"><?= count($sectionLeads) ?></span>
                </div>
                <div class="card-body">
                    <?php if (!$sectionLeads): ?>
                        <div class="text-muted"><?= htmlspecialchars($sectionMeta['empty']) ?></div>
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
                                    <?php foreach ($sectionLeads as $lead): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars($lead['nazwa_firmy'] ?? '') ?></strong><br>
                                                <small class="text-muted"><?= htmlspecialchars(leadStatusLabel((string)($lead['status'] ?? ''))) ?></small>
                                            </td>
                                            <td><span class="badge bg-secondary"><?= htmlspecialchars($lead['priority'] ?? 'Sredni') ?></span></td>
                                            <td><?= htmlspecialchars($lead['next_action'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars(formatNextActionRelative($lead['next_action_at'] ?? null)) ?></td>
                                            <td>
                                                <a class="btn btn-sm btn-outline-secondary" href="lead.php?lead_edit_id=<?= (int)$lead['id'] ?>#lead-form">Otworz</a>
                                                <button class="btn btn-sm btn-outline-success ms-1 followup-done" data-id="<?= (int)$lead['id'] ?>">Oznacz wykonane</button>
                                                <select class="form-select form-select-sm d-inline-block w-auto ms-1 followup-snooze" data-id="<?= (int)$lead['id'] ?>">
                                                    <option value="">Przesun o...</option>
                                                    <option value="1">+1 dzien</option>
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
        <?php endforeach; ?>
    <?php else: ?>
        <?php if ($taskFlash): ?>
            <div class="alert alert-<?= htmlspecialchars($taskFlash['type']) ?>"><?= htmlspecialchars($taskFlash['msg']) ?></div>
        <?php endif; ?>

        <?php
        $taskSections = [
            ['title' => 'Otwarte zadania', 'tasks' => $openTasks, 'empty' => 'Brak otwartych zadan.'],
            ['title' => 'Przeterminowane zadania', 'tasks' => $overdueTasks, 'empty' => 'Brak przeterminowanych zadan.'],
            ['title' => 'Zamknięte zadania', 'tasks' => $doneTasks, 'empty' => 'Brak zamknietych zadan.'],
        ];
        ?>
        <?php foreach ($taskSections as $taskSection): ?>
            <?php $tasks = $taskSection['tasks']; ?>
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <strong><?= htmlspecialchars($taskSection['title']) ?></strong>
                    <span class="badge bg-secondary"><?= count($tasks) ?></span>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Termin</th>
                                    <th>Typ</th>
                                    <th>Tytul</th>
                                    <th>Lead/Klient</th>
                                    <th>Odpowiedzialny</th>
                                    <th>Status</th>
                                    <th>Akcje</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($tasks)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted"><?= htmlspecialchars($taskSection['empty']) ?></td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($tasks as $task): ?>
                                    <?php
                                    $dueLabel = formatTaskDueAt($task['due_at'] ?? null);
                                    $typeLabel = taskTypeLabel((string)($task['typ'] ?? ''));
                                    $taskObjectType = (string)($task['obiekt_typ'] ?? '');
                                    if ($taskObjectType === 'lead') {
                                        $objectLabel = $task['lead_name'] ?? ('Lead #' . (int)$task['obiekt_id']);
                                        $objectLink = 'lead_szczegoly.php?id=' . (int)$task['obiekt_id'];
                                    } elseif ($taskObjectType === 'klient') {
                                        $objectLabel = $task['klient_name'] ?? ('Klient #' . (int)$task['obiekt_id']);
                                        $objectLink = 'klient_szczegoly.php?id=' . (int)$task['obiekt_id'];
                                    } else {
                                        $objectLabel = $task['kampania_name'] ?? ('Kampania #' . (int)$task['obiekt_id']);
                                        $objectLink = 'kampania_podglad.php?id=' . (int)$task['obiekt_id'];
                                    }
                                    $ownerLabel = trim((string)($task['owner_imie'] ?? '') . ' ' . (string)($task['owner_nazwisko'] ?? ''));
                                    if ($ownerLabel === '') {
                                        $ownerLabel = (string)($task['owner_login'] ?? ('User #' . (int)$task['owner_user_id']));
                                    }
                                    $status = (string)($task['status'] ?? 'OPEN');
                                    $isOverdue = false;
                                    if ($status === 'OPEN' && !empty($task['due_at'])) {
                                        try {
                                            $dueAt = new DateTime((string)$task['due_at'], new DateTimeZone('Europe/Warsaw'));
                                            $isOverdue = $dueAt < $now;
                                        } catch (Throwable $e) {
                                            $isOverdue = false;
                                        }
                                    }
                                    $statusClass = $status === 'DONE'
                                        ? 'bg-success'
                                        : ($status === 'CANCELLED' ? 'bg-secondary' : ($isOverdue ? 'bg-danger' : 'bg-warning text-dark'));
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($dueLabel ?: '-') ?></td>
                                        <td><?= htmlspecialchars($typeLabel) ?></td>
                                        <td><?= htmlspecialchars($task['tytul'] ?? '') ?></td>
                                        <td>
                                            <a href="<?= htmlspecialchars($objectLink) ?>">
                                                <?= htmlspecialchars($objectLabel) ?>
                                            </a>
                                        </td>
                                        <td><?= htmlspecialchars($ownerLabel) ?></td>
                                        <td>
                                            <span class="badge <?= htmlspecialchars($statusClass) ?>">
                                                <?= htmlspecialchars($status === 'OPEN' ? ($isOverdue ? 'OVERDUE' : 'OPEN') : $status) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($status === 'OPEN'): ?>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                    <input type="hidden" name="task_action" value="complete">
                                                    <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-success">Wykonane</button>
                                                </form>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                    <input type="hidden" name="task_action" value="cancel">
                                                    <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Anuluj</button>
                                                </form>
                                                <form method="post" class="d-inline">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                    <input type="hidden" name="task_action" value="postpone">
                                                    <input type="hidden" name="task_id" value="<?= (int)$task['id'] ?>">
                                                    <input type="datetime-local" name="new_due_at" class="form-control form-control-sm d-inline-block" style="width: 190px" required>
                                                    <button type="submit" class="btn btn-sm btn-outline-primary">Przeloz</button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php if (!empty($task['opis'])): ?>
                                        <tr>
                                            <td colspan="7" class="text-muted small">
                                                Opis: <?= htmlspecialchars($task['opis']) ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</main>

<script>
(function () {
    function sendUpdate(payload) {
        return fetch('api/lead_followup_update.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json();
        });
    }

    document.querySelectorAll('.followup-done').forEach(function (button) {
        button.addEventListener('click', function () {
            var leadId = button.dataset.id;
            sendUpdate({ lead_id: leadId, action: 'done' })
                .then(function (resp) {
                    if (!resp.success) {
                        throw new Error(resp.error || 'Blad');
                    }
                    location.reload();
                })
                .catch(function (err) {
                    alert(err.message || 'Nie udalo sie zaktualizowac.');
                });
        });
    });

    document.querySelectorAll('.followup-snooze').forEach(function (select) {
        select.addEventListener('change', function () {
            var days = parseInt(select.value, 10);
            if (!days) {
                return;
            }
            var leadId = select.dataset.id;
            sendUpdate({ lead_id: leadId, action: 'snooze', days: days })
                .then(function (resp) {
                    if (!resp.success) {
                        throw new Error(resp.error || 'Blad');
                    }
                    location.reload();
                })
                .catch(function (err) {
                    alert(err.message || 'Nie udalo sie zaktualizowac.');
                });
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
