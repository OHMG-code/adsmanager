<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/crm_activity.php';
require_once __DIR__ . '/activity_log.php';
require_once __DIR__ . '/documents.php';

function taskStatusDefinitions(): array
{
    return [
        'OPEN' => 'OPEN',
        'DONE' => 'DONE',
        'CANCELLED' => 'CANCELLED',
    ];
}

function normalizeTaskStatus(string $status): string
{
    $status = strtoupper(trim($status));
    $allowed = array_keys(taskStatusDefinitions());
    return in_array($status, $allowed, true) ? $status : 'OPEN';
}

function taskTypeLabel(string $type): string
{
    $map = [
        'telefon' => 'Telefon',
        'email' => 'Email',
        'sms' => 'SMS',
        'spotkanie' => 'Spotkanie',
        'inne' => 'Inne',
    ];
    return $map[$type] ?? ucfirst($type);
}

function formatTaskDueAt(?string $dueAt): string
{
    if (!$dueAt) {
        return '';
    }
    try {
        $dt = new DateTime($dueAt, new DateTimeZone('Europe/Warsaw'));
        return $dt->format('d.m.Y H:i');
    } catch (Throwable $e) {
        return (string)$dueAt;
    }
}

function buildTaskSummary(array $task): string
{
    $label = taskTypeLabel((string)($task['typ'] ?? ''));
    $due = formatTaskDueAt($task['due_at'] ?? null);
    $title = trim((string)($task['tytul'] ?? ''));
    $summary = $label;
    if ($due !== '') {
        $summary .= ', termin: ' . $due;
    }
    if ($title !== '') {
        $summary .= ', tytul: ' . $title;
    }
    $opis = trim((string)($task['opis'] ?? ''));
    if ($opis !== '') {
        $summary .= ', opis: ' . $opis;
    }
    return $summary;
}

function buildTaskAccessClause(string $role, int $userId, array &$params): string
{
    if (in_array($role, ['Administrator', 'Manager'], true)) {
        return '';
    }
    $params[':task_owner_id'] = $userId;
    return ' AND t.owner_user_id = :task_owner_id';
}

function canManageTask(array $task, array $currentUser): bool
{
    $role = normalizeRole($currentUser);
    if (in_array($role, ['Administrator', 'Manager'], true)) {
        return true;
    }
    return (int)($task['owner_user_id'] ?? 0) === (int)($currentUser['id'] ?? 0);
}

function taskCanAccessObject(string $obiektTyp, int $obiektId, array $user, PDO $pdo): bool
{
    $obiektTyp = trim($obiektTyp);
    if ($obiektId <= 0) {
        return false;
    }
    if (in_array($obiektTyp, ['lead', 'klient'], true)) {
        return canAccessCrmObject($obiektTyp, $obiektId, $user);
    }
    if ($obiektTyp === 'kampania') {
        return canUserAccessKampania($pdo, $user, $obiektId);
    }
    return false;
}

function taskFindOpenByExternalKey(PDO $pdo, string $externalKey): ?array
{
    ensureCrmTasksTable($pdo);
    $externalKey = trim($externalKey);
    if ($externalKey === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM crm_zadania WHERE external_key = :k AND status = 'OPEN' LIMIT 1");
        $stmt->execute([':k' => $externalKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('tasks: taskFindOpenByExternalKey failed: ' . $e->getMessage());
        return null;
    }
}

function taskFindAnyByExternalKey(PDO $pdo, string $externalKey): ?array
{
    ensureCrmTasksTable($pdo);
    $externalKey = trim($externalKey);
    if ($externalKey === '') {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT * FROM crm_zadania WHERE external_key = :k ORDER BY id DESC LIMIT 1");
        $stmt->execute([':k' => $externalKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('tasks: taskFindAnyByExternalKey failed: ' . $e->getMessage());
        return null;
    }
}

function createTask(PDO $pdo, array $payload): array
{
    ensureCrmTasksTable($pdo);
    ensureUserColumns($pdo);

    $relatedType = trim((string)($payload['related_type'] ?? ''));
    $relatedId = (int)($payload['related_id'] ?? 0);
    $assignedUserId = (int)($payload['assigned_user_id'] ?? 0);
    $type = trim((string)($payload['type'] ?? 'inne'));
    $title = trim((string)($payload['title'] ?? ''));
    $description = trim((string)($payload['description'] ?? ''));
    $dueAt = trim((string)($payload['due_at'] ?? ''));
    $status = normalizeTaskStatus((string)($payload['status'] ?? 'OPEN'));
    $externalKey = trim((string)($payload['external_key'] ?? ''));
    $requireExternalKey = !empty($payload['require_external_key']);
    $skipIfExternalExists = !empty($payload['skip_if_external_exists']);
    $createdByUserId = !empty($payload['created_by_user_id']) ? (int)$payload['created_by_user_id'] : null;
    $logActivity = !array_key_exists('log_activity', $payload) || !empty($payload['log_activity']);

    if (!in_array($relatedType, ['lead', 'klient', 'kampania'], true)) {
        return ['ok' => false, 'error' => 'Nieprawidlowy related_type.'];
    }
    if ($relatedId <= 0 || $assignedUserId <= 0 || $title === '') {
        return ['ok' => false, 'error' => 'Brak wymaganych danych taska.'];
    }
    if (!in_array($type, ['telefon', 'email', 'sms', 'spotkanie', 'inne'], true)) {
        $type = 'inne';
    }
    if ($requireExternalKey && $externalKey === '') {
        return ['ok' => false, 'error' => 'Brak external_key dla automatycznego taska.'];
    }
    if ($externalKey !== '') {
        if ($skipIfExternalExists) {
            $existingAny = taskFindAnyByExternalKey($pdo, $externalKey);
            if ($existingAny) {
                return [
                    'ok' => true,
                    'duplicate' => true,
                    'id' => (int)($existingAny['id'] ?? 0),
                    'row' => $existingAny,
                ];
            }
        }
        $existing = taskFindOpenByExternalKey($pdo, $externalKey);
        if ($existing) {
            return [
                'ok' => true,
                'duplicate' => true,
                'id' => (int)($existing['id'] ?? 0),
                'row' => $existing,
            ];
        }
    }

    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dueAt, new DateTimeZone('Europe/Warsaw'));
    if (!$dt) {
        return ['ok' => false, 'error' => 'Nieprawidlowe due_at.'];
    }

    $currentUser = fetchCurrentUser($pdo);
    if ($currentUser) {
        if (!taskCanAccessObject($relatedType, $relatedId, $currentUser, $pdo)) {
            return ['ok' => false, 'error' => 'Brak dostepu do obiektu taska.'];
        }
        $role = normalizeRole($currentUser);
        if ($role === 'Handlowiec' && (int)$currentUser['id'] !== $assignedUserId) {
            return ['ok' => false, 'error' => 'Brak uprawnien do przypisania taska.'];
        }
        if ($createdByUserId === null) {
            $createdByUserId = (int)$currentUser['id'];
        }
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO crm_zadania (obiekt_typ, obiekt_id, owner_user_id, typ, tytul, opis, due_at, status, external_key)
             VALUES (:obiekt_typ, :obiekt_id, :owner_user_id, :typ, :tytul, :opis, :due_at, :status, :external_key)"
        );
        $stmt->execute([
            ':obiekt_typ' => $relatedType,
            ':obiekt_id' => $relatedId,
            ':owner_user_id' => $assignedUserId,
            ':typ' => $type,
            ':tytul' => $title,
            ':opis' => $description !== '' ? $description : null,
            ':due_at' => $dueAt,
            ':status' => $status,
            ':external_key' => $externalKey !== '' ? substr($externalKey, 0, 191) : null,
        ]);
        $taskId = (int)$pdo->lastInsertId();

        if ($logActivity && $taskId > 0 && $createdByUserId !== null) {
            $summary = buildTaskSummary([
                'typ' => $type,
                'tytul' => $title,
                'opis' => $description,
                'due_at' => $dueAt,
            ]);
            if (in_array($relatedType, ['lead', 'klient'], true)) {
                addActivity($relatedType, $relatedId, 'system', $createdByUserId, $summary, null, 'Zaplanowano dzialanie');
            }
            logActivity($pdo, 'task', $taskId, 'created', $summary, $createdByUserId);
        }

        return ['ok' => true, 'duplicate' => false, 'id' => $taskId];
    } catch (Throwable $e) {
        error_log('tasks: createTask failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function getUserTasks(PDO $pdo, int $userId, string $role, string $filter = 'open', int $limit = 100): array
{
    ensureCrmTasksTable($pdo);
    ensureUserColumns($pdo);
    ensureLeadColumns($pdo);
    ensureClientLeadColumns($pdo);
    ensureKampanieOwnershipColumns($pdo);

    $filter = strtolower(trim($filter));
    if (!in_array($filter, ['open', 'overdue', 'done'], true)) {
        $filter = 'open';
    }
    $limit = max(1, min(500, $limit));

    $params = [];
    $whereParts = [];
    $orderSql = 't.due_at ASC';
    if ($filter === 'done') {
        $whereParts[] = "t.status IN ('DONE','CANCELLED')";
        $orderSql = 't.done_at DESC, t.due_at DESC';
    } elseif ($filter === 'overdue') {
        $whereParts[] = "t.status = 'OPEN'";
        $whereParts[] = 't.due_at < NOW()';
        $orderSql = 't.due_at ASC';
    } else {
        $whereParts[] = "t.status = 'OPEN'";
        $orderSql = 't.due_at ASC';
    }

    $where = 'WHERE ' . implode(' AND ', $whereParts);
    $where .= buildTaskAccessClause($role, $userId, $params);

    $sql = "SELECT t.*,
                u.login AS owner_login, u.imie AS owner_imie, u.nazwisko AS owner_nazwisko,
                l.nazwa_firmy AS lead_name,
                k.nazwa_firmy AS klient_name,
                kp.klient_nazwa AS kampania_name
            FROM crm_zadania t
            LEFT JOIN uzytkownicy u ON u.id = t.owner_user_id
            LEFT JOIN leady l ON t.obiekt_typ = 'lead' AND l.id = t.obiekt_id
            LEFT JOIN klienci k ON t.obiekt_typ = 'klient' AND k.id = t.obiekt_id
            LEFT JOIN kampanie kp ON t.obiekt_typ = 'kampania' AND kp.id = t.obiekt_id
            {$where}
            ORDER BY {$orderSql}
            LIMIT {$limit}";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('tasks: getUserTasks failed: ' . $e->getMessage());
        return [];
    }
}

function markTaskDone(PDO $pdo, int $taskId, int $userId): bool
{
    return completeTask($taskId, $userId);
}

function taskActionResult(bool $ok, string $code, string $message, array $extra = []): array
{
    return array_merge([
        'ok' => $ok,
        'code' => $code,
        'message' => $message,
    ], $extra);
}

function addTask(
    string $obiektTyp,
    int $obiektId,
    string $typ,
    string $tytul,
    string $dueAt,
    int $ownerUserId,
    int $userId,
    ?string $opis = null
): bool {
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return false;
    }
    $result = createTask($pdo, [
        'type' => $typ,
        'title' => $tytul,
        'related_type' => $obiektTyp,
        'related_id' => $obiektId,
        'assigned_user_id' => $ownerUserId,
        'due_at' => $dueAt,
        'status' => 'OPEN',
        'description' => $opis,
        'created_by_user_id' => $userId,
        'require_external_key' => false,
        'log_activity' => true,
    ]);
    return !empty($result['ok']);
}

function fetchTaskById(int $taskId): ?array
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return null;
    }
    ensureCrmTasksTable($pdo);
    $stmt = $pdo->prepare('SELECT * FROM crm_zadania WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $taskId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function taskObjectExists(string $obiektTyp, int $obiektId): bool
{
    global $pdo;
    if (!($pdo instanceof PDO) || $obiektId <= 0) {
        return false;
    }

    $obiektTyp = trim($obiektTyp);
    try {
        switch ($obiektTyp) {
            case 'lead':
                if (!$pdo->inTransaction()) {
                    ensureLeadColumns($pdo);
                }
                $stmt = $pdo->prepare('SELECT 1 FROM leady WHERE id = :id LIMIT 1');
                break;
            case 'klient':
                if (!$pdo->inTransaction()) {
                    ensureClientLeadColumns($pdo);
                }
                $stmt = $pdo->prepare('SELECT 1 FROM klienci WHERE id = :id LIMIT 1');
                break;
            case 'kampania':
                if (!$pdo->inTransaction()) {
                    ensureKampanieOwnershipColumns($pdo);
                }
                if (!tableExists($pdo, 'kampanie')) {
                    return false;
                }
                $stmt = $pdo->prepare('SELECT 1 FROM kampanie WHERE id = :id LIMIT 1');
                break;
            default:
                return false;
        }

        $stmt->execute([':id' => $obiektId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('tasks: taskObjectExists failed: ' . $e->getMessage());
        return false;
    }
}

function taskClosedMessage(array $task): string
{
    $status = normalizeTaskStatus((string)($task['status'] ?? ''));
    if ($status === 'DONE') {
        return 'To zadanie jest już wykonane.';
    }
    if ($status === 'CANCELLED') {
        return 'To zadanie jest już anulowane.';
    }
    return 'To zadanie jest już zamknięte.';
}

function taskPrepareMutation(int $taskId): array
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return taskActionResult(false, 'internal_error', 'Brak połączenia z bazą danych.');
    }

    $task = fetchTaskById($taskId);
    if (!$task) {
        return taskActionResult(false, 'missing_task', 'To zadanie już nie istnieje.');
    }

    $currentUser = fetchCurrentUser($pdo);
    if (!$currentUser) {
        return taskActionResult(false, 'auth_required', 'Sesja wygasła. Zaloguj się ponownie.');
    }

    if (!canManageTask($task, $currentUser)) {
        return taskActionResult(false, 'forbidden', 'Nie masz uprawnień do zmiany tego zadania.');
    }

    if (normalizeTaskStatus((string)($task['status'] ?? '')) !== 'OPEN') {
        return taskActionResult(false, 'already_closed', taskClosedMessage($task), [
            'task' => $task,
            'current_user' => $currentUser,
        ]);
    }

    if (!taskObjectExists((string)($task['obiekt_typ'] ?? ''), (int)($task['obiekt_id'] ?? 0))) {
        return taskActionResult(false, 'missing_object', 'Nie można zmienić zadania, bo powiązany obiekt już nie istnieje.', [
            'task' => $task,
            'current_user' => $currentUser,
        ]);
    }

    return taskActionResult(true, 'ready', '', [
        'task' => $task,
        'current_user' => $currentUser,
    ]);
}

function completeTaskResult(int $taskId, int $userId): array
{
    global $pdo;
    $context = taskPrepareMutation($taskId);
    if (empty($context['ok'])) {
        return $context;
    }

    $task = $context['task'] ?? [];
    try {
        $stmt = $pdo->prepare("UPDATE crm_zadania SET status = 'DONE', done_at = NOW() WHERE id = :id AND status = 'OPEN'");
        $stmt->execute([':id' => $taskId]);
        if ($stmt->rowCount() < 1) {
            $freshTask = fetchTaskById($taskId);
            if ($freshTask && normalizeTaskStatus((string)($freshTask['status'] ?? '')) !== 'OPEN') {
                return taskActionResult(false, 'already_closed', taskClosedMessage($freshTask), ['task' => $freshTask]);
            }
            return taskActionResult(false, 'update_failed', 'Nie udało się zapisać zmiany statusu zadania.');
        }

        $summary = buildTaskSummary($task);
        if (in_array((string)$task['obiekt_typ'], ['lead', 'klient'], true)) {
            addActivity((string)$task['obiekt_typ'], (int)$task['obiekt_id'], 'system', $userId, $summary, null, 'Zakonczono dzialanie');
        }
        logActivity($pdo, 'task', $taskId, 'completed', $summary, $userId);
        return taskActionResult(true, 'success', 'Zadanie oznaczone jako wykonane.');
    } catch (Throwable $e) {
        error_log('tasks: completeTask failed: ' . $e->getMessage());
        return taskActionResult(false, 'exception', 'Wystąpił błąd podczas zamykania zadania.');
    }
}

function completeTask(int $taskId, int $userId): bool
{
    return !empty(completeTaskResult($taskId, $userId)['ok']);
}

function cancelTask(int $taskId, int $userId): bool
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return false;
    }
    $task = fetchTaskById($taskId);
    if (!$task) {
        return false;
    }
    if (normalizeTaskStatus((string)($task['status'] ?? '')) !== 'OPEN') {
        return false;
    }
    $currentUser = fetchCurrentUser($pdo);
    if (!$currentUser) {
        return false;
    }
    if (!canManageTask($task, $currentUser)) {
        return false;
    }
    if (!taskCanAccessObject((string)$task['obiekt_typ'], (int)$task['obiekt_id'], $currentUser, $pdo)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("UPDATE crm_zadania SET status = 'CANCELLED', done_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $taskId]);
        $summary = buildTaskSummary($task);
        if (in_array((string)$task['obiekt_typ'], ['lead', 'klient'], true)) {
            addActivity((string)$task['obiekt_typ'], (int)$task['obiekt_id'], 'system', $userId, $summary, null, 'Anulowano dzialanie');
        }
        logActivity($pdo, 'task', $taskId, 'canceled', $summary, $userId);
        return true;
    } catch (Throwable $e) {
        error_log('tasks: cancelTask failed: ' . $e->getMessage());
        return false;
    }
}

function postponeTask(int $taskId, string $newDueAt, int $userId): bool
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return false;
    }
    $task = fetchTaskById($taskId);
    if (!$task) {
        return false;
    }
    if (normalizeTaskStatus((string)($task['status'] ?? '')) !== 'OPEN') {
        return false;
    }
    $currentUser = fetchCurrentUser($pdo);
    if (!$currentUser) {
        return false;
    }
    if (!canManageTask($task, $currentUser)) {
        return false;
    }
    if (!taskCanAccessObject((string)$task['obiekt_typ'], (int)$task['obiekt_id'], $currentUser, $pdo)) {
        return false;
    }

    $newDueAt = trim($newDueAt);
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $newDueAt, new DateTimeZone('Europe/Warsaw'));
    if (!$dt) {
        return false;
    }

    try {
        $stmt = $pdo->prepare('UPDATE crm_zadania SET due_at = :due_at WHERE id = :id');
        $stmt->execute([':due_at' => $newDueAt, ':id' => $taskId]);
        $oldDue = formatTaskDueAt($task['due_at'] ?? null);
        $newDue = formatTaskDueAt($newDueAt);
        $message = 'Przelozono z ' . ($oldDue !== '' ? $oldDue : '-') . ' na ' . ($newDue !== '' ? $newDue : '-');
        logActivity($pdo, 'task', $taskId, 'rescheduled', $message, $userId);
        return true;
    } catch (Throwable $e) {
        error_log('tasks: postponeTask failed: ' . $e->getMessage());
        return false;
    }
}

function countOverdueTasks(int $userId, string $role): int
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return 0;
    }
    ensureCrmTasksTable($pdo);
    ensureLeadColumns($pdo);
    ensureClientLeadColumns($pdo);

    $params = [];
    $where = "WHERE t.status = 'OPEN' AND t.due_at < NOW()";
    $where .= buildTaskAccessClause($role, $userId, $params);

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM crm_zadania t {$where}");
        $stmt->execute($params);
        return (int)($stmt->fetchColumn() ?? 0);
    } catch (Throwable $e) {
        error_log('tasks: countOverdueTasks failed: ' . $e->getMessage());
        return 0;
    }
}

function getTasks(string $filter, int $userId, string $role): array
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return [];
    }
    ensureCrmTasksTable($pdo);
    ensureLeadColumns($pdo);
    ensureClientLeadColumns($pdo);
    ensureUserColumns($pdo);

    $filter = strtolower(trim($filter));
    $params = [];
    $whereParts = [];
    $orderSql = 't.due_at ASC';

    switch ($filter) {
        case 'today':
            $whereParts[] = "t.status = 'OPEN'";
            $whereParts[] = 'DATE(t.due_at) = CURDATE()';
            $orderSql = 't.due_at ASC';
            break;
        case 'upcoming':
            $whereParts[] = "t.status = 'OPEN'";
            $whereParts[] = 't.due_at > NOW()';
            $orderSql = 't.due_at ASC';
            break;
        case 'done':
            $whereParts[] = "t.status IN ('DONE','CANCELLED')";
            $orderSql = 't.done_at DESC, t.due_at DESC';
            break;
        case 'overdue':
        default:
            $filter = 'overdue';
            $whereParts[] = "t.status = 'OPEN'";
            $whereParts[] = 't.due_at < NOW()';
            $orderSql = 't.due_at ASC';
            break;
    }

    $where = $whereParts ? 'WHERE ' . implode(' AND ', $whereParts) : '';
    $where .= buildTaskAccessClause($role, $userId, $params);

    $sql = "SELECT t.*, 
                u.login AS owner_login, u.imie AS owner_imie, u.nazwisko AS owner_nazwisko,
                l.nazwa_firmy AS lead_name,
                k.nazwa_firmy AS klient_name,
                kp.klient_nazwa AS kampania_name
            FROM crm_zadania t
            LEFT JOIN uzytkownicy u ON u.id = t.owner_user_id
            LEFT JOIN leady l ON t.obiekt_typ = 'lead' AND l.id = t.obiekt_id
            LEFT JOIN klienci k ON t.obiekt_typ = 'klient' AND k.id = t.obiekt_id
            LEFT JOIN kampanie kp ON t.obiekt_typ = 'kampania' AND kp.id = t.obiekt_id
            {$where}
            ORDER BY {$orderSql}";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('tasks: getTasks failed: ' . $e->getMessage());
        return [];
    }
}
