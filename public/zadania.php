<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/tasks.php';
require_once __DIR__ . '/../config/config.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}
$role = normalizeRole($currentUser);

$pageTitle = 'Zadania';
$filter = strtolower(trim((string)($_GET['filter'] ?? 'overdue')));
$allowedFilters = ['overdue', 'today', 'upcoming', 'done'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'overdue';
}

$flash = $_SESSION['task_flash'] ?? null;
unset($_SESSION['task_flash']);

function normalizeTaskDateTime(?string $input): ?string
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!isCsrfTokenValid($token)) {
        $flash = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } else {
        $action = trim((string)($_POST['task_action'] ?? ''));
        $taskId = isset($_POST['task_id']) ? (int)$_POST['task_id'] : 0;
        if ($taskId <= 0) {
            $flash = ['type' => 'warning', 'msg' => 'Brak identyfikatora zadania.'];
        } else {
            $result = false;
            switch ($action) {
                case 'complete':
                    $result = completeTask($taskId, (int)$currentUser['id']);
                    $flash = $result
                        ? ['type' => 'success', 'msg' => 'Zadanie oznaczone jako wykonane.']
                        : ['type' => 'danger', 'msg' => 'Nie udalo sie zakonczyc zadania.'];
                    break;
                case 'cancel':
                    $result = cancelTask($taskId, (int)$currentUser['id']);
                    $flash = $result
                        ? ['type' => 'success', 'msg' => 'Zadanie anulowane.']
                        : ['type' => 'danger', 'msg' => 'Nie udalo sie anulowac zadania.'];
                    break;
                case 'postpone':
                    $newDueRaw = trim((string)($_POST['new_due_at'] ?? ''));
                    $newDueAt = normalizeTaskDateTime($newDueRaw);
                    if (!$newDueAt) {
                        $flash = ['type' => 'warning', 'msg' => 'Podaj poprawny termin.'];
                        break;
                    }
                    $result = postponeTask($taskId, $newDueAt, (int)$currentUser['id']);
                    $flash = $result
                        ? ['type' => 'success', 'msg' => 'Zadanie zostalo przelozone.']
                        : ['type' => 'danger', 'msg' => 'Nie udalo sie przelozyc zadania.'];
                    break;
                default:
                    $flash = ['type' => 'warning', 'msg' => 'Nieznana akcja.'];
            }
        }
    }

    $_SESSION['task_flash'] = $flash;
    header('Location: ' . BASE_URL . '/zadania.php?filter=' . urlencode($filter));
    exit;
}

$tasks = getTasks($filter, (int)$currentUser['id'], $role);
$now = new DateTime('now', new DateTimeZone('Europe/Warsaw'));

require_once __DIR__ . '/includes/header.php';
?>

<main class="content-wide py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">Zadania</h1>
            <p class="text-muted mb-0">Planowane dzialania dla leadow i klientow.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="zadania.php?filter=overdue" class="btn btn-sm btn-outline-secondary">Zalegle</a>
            <a href="zadania.php?filter=today" class="btn btn-sm btn-outline-secondary">Dzisiaj</a>
            <a href="zadania.php?filter=upcoming" class="btn btn-sm btn-outline-secondary">Nadchodzace</a>
            <a href="zadania.php?filter=done" class="btn btn-sm btn-outline-secondary">Zakonczone</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <div class="card">
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
                            <td colspan="7" class="text-center text-muted">Brak zadan do wyswietlenia.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tasks as $task): ?>
                            <?php
                            $dueLabel = formatTaskDueAt($task['due_at'] ?? null);
                            $typeLabel = taskTypeLabel((string)($task['typ'] ?? ''));
                            $objectLabel = $task['obiekt_typ'] === 'lead'
                                ? ($task['lead_name'] ?? ('Lead #' . (int)$task['obiekt_id']))
                                : ($task['klient_name'] ?? ('Klient #' . (int)$task['obiekt_id']));
                            $objectLink = $task['obiekt_typ'] === 'lead'
                                ? 'lead_szczegoly.php?id=' . (int)$task['obiekt_id']
                                : 'klient_szczegoly.php?id=' . (int)$task['obiekt_id'];
                            $ownerLabel = trim((string)($task['owner_imie'] ?? '') . ' ' . (string)($task['owner_nazwisko'] ?? ''));
                            if ($ownerLabel === '') {
                                $ownerLabel = (string)($task['owner_login'] ?? ('User #' . (int)$task['owner_user_id']));
                            }
                            $status = (string)($task['status'] ?? 'OPEN');
                            $isOverdue = false;
                            if ($status === 'OPEN' && !empty($task['due_at'])) {
                                try {
                                    $dueAt = new DateTime($task['due_at'], new DateTimeZone('Europe/Warsaw'));
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
                                        <span class="text-muted">—</span>
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
</main>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
