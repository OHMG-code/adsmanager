<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/crm_activity.php';
require_once __DIR__ . '/activity_log.php';

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
    ensureCrmTasksTable($pdo);

    $obiektTyp = trim($obiektTyp);
    if (!in_array($obiektTyp, ['lead', 'klient'], true)) {
        return false;
    }
    $typ = trim($typ);
    if (!in_array($typ, ['telefon', 'email', 'sms', 'spotkanie', 'inne'], true)) {
        return false;
    }
    $tytul = trim($tytul);
    if ($tytul === '' || $obiektId <= 0 || $ownerUserId <= 0 || $userId <= 0) {
        return false;
    }

    $currentUser = fetchCurrentUser($pdo);
    if (!$currentUser) {
        return false;
    }
    $role = normalizeRole($currentUser);
    if ($role === 'Handlowiec' && (int)$currentUser['id'] !== $userId) {
        return false;
    }
    if (!canAccessCrmObject($obiektTyp, $obiektId, $currentUser)) {
        return false;
    }
    if ($role === 'Handlowiec' && $ownerUserId !== (int)$currentUser['id']) {
        return false;
    }

    $dueAt = trim($dueAt);
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dueAt, new DateTimeZone('Europe/Warsaw'));
    if (!$dt) {
        return false;
    }
    $opis = trim((string)$opis);
    $opis = $opis !== '' ? $opis : null;

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO crm_zadania (obiekt_typ, obiekt_id, owner_user_id, typ, tytul, opis, due_at, status)
             VALUES (:obiekt_typ, :obiekt_id, :owner_user_id, :typ, :tytul, :opis, :due_at, 'OPEN')"
        );
        $stmt->execute([
            ':obiekt_typ' => $obiektTyp,
            ':obiekt_id' => $obiektId,
            ':owner_user_id' => $ownerUserId,
            ':typ' => $typ,
            ':tytul' => $tytul,
            ':opis' => $opis,
            ':due_at' => $dueAt,
        ]);
        $taskId = (int)$pdo->lastInsertId();
        $summary = buildTaskSummary([
            'typ' => $typ,
            'tytul' => $tytul,
            'opis' => $opis,
            'due_at' => $dueAt,
        ]);
        addActivity($obiektTyp, $obiektId, 'system', $userId, $summary, null, 'Zaplanowano dzialanie');
        if ($taskId > 0) {
            logActivity($pdo, 'task', $taskId, 'created', $summary, $userId);
        }
        return true;
    } catch (Throwable $e) {
        error_log('tasks: addTask failed: ' . $e->getMessage());
        return false;
    }
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

function completeTask(int $taskId, int $userId): bool
{
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return false;
    }
    $task = fetchTaskById($taskId);
    if (!$task) {
        return false;
    }
    if (($task['status'] ?? '') !== 'OPEN') {
        return false;
    }
    $currentUser = fetchCurrentUser($pdo);
    if (!$currentUser) {
        return false;
    }
    if (!canManageTask($task, $currentUser)) {
        return false;
    }
    if (!canAccessCrmObject((string)$task['obiekt_typ'], (int)$task['obiekt_id'], $currentUser)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("UPDATE crm_zadania SET status = 'DONE', done_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $taskId]);
        $summary = buildTaskSummary($task);
        addActivity((string)$task['obiekt_typ'], (int)$task['obiekt_id'], 'system', $userId, $summary, null, 'Zakonczono dzialanie');
        logActivity($pdo, 'task', $taskId, 'completed', $summary, $userId);
        return true;
    } catch (Throwable $e) {
        error_log('tasks: completeTask failed: ' . $e->getMessage());
        return false;
    }
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
    if (($task['status'] ?? '') !== 'OPEN') {
        return false;
    }
    $currentUser = fetchCurrentUser($pdo);
    if (!$currentUser) {
        return false;
    }
    if (!canManageTask($task, $currentUser)) {
        return false;
    }
    if (!canAccessCrmObject((string)$task['obiekt_typ'], (int)$task['obiekt_id'], $currentUser)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare("UPDATE crm_zadania SET status = 'CANCELLED', done_at = NOW() WHERE id = :id");
        $stmt->execute([':id' => $taskId]);
        $summary = buildTaskSummary($task);
        addActivity((string)$task['obiekt_typ'], (int)$task['obiekt_id'], 'system', $userId, $summary, null, 'Anulowano dzialanie');
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
    if (($task['status'] ?? '') !== 'OPEN') {
        return false;
    }
    $currentUser = fetchCurrentUser($pdo);
    if (!$currentUser) {
        return false;
    }
    if (!canManageTask($task, $currentUser)) {
        return false;
    }
    if (!canAccessCrmObject((string)$task['obiekt_typ'], (int)$task['obiekt_id'], $currentUser)) {
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
                k.nazwa_firmy AS klient_name
            FROM crm_zadania t
            LEFT JOIN uzytkownicy u ON u.id = t.owner_user_id
            LEFT JOIN leady l ON t.obiekt_typ = 'lead' AND l.id = t.obiekt_id
            LEFT JOIN klienci k ON t.obiekt_typ = 'klient' AND k.id = t.obiekt_id
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
