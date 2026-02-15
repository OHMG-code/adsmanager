<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function logActivity(PDO $pdo, string $entityType, int $entityId, string $action, ?string $message, int $userId): bool
{
    ensureActivityLogTable($pdo);

    $entityType = trim($entityType);
    if (!in_array($entityType, ['lead', 'klient', 'task'], true)) {
        return false;
    }
    $action = trim($action);
    if ($action === '' || $entityId <= 0 || $userId <= 0) {
        return false;
    }

    $message = trim((string)$message);
    $message = $message !== '' ? $message : null;

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO activity_log (entity_type, entity_id, action, message, user_id)
             VALUES (:entity_type, :entity_id, :action, :message, :user_id)"
        );
        $stmt->execute([
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':action' => $action,
            ':message' => $message,
            ':user_id' => $userId,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('activity_log: insert failed: ' . $e->getMessage());
        return false;
    }
}

function getActivityHistory(PDO $pdo, string $entityType, int $entityId, int $limit = 20, bool $includeTasks = true): array
{
    ensureActivityLogTable($pdo);
    ensureUserColumns($pdo);

    $entityType = trim($entityType);
    if (!in_array($entityType, ['lead', 'klient'], true) || $entityId <= 0) {
        return [];
    }
    $limit = max(1, min(100, $limit));

    $entries = [];

    try {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.entity_type, a.entity_id, a.action, a.message, a.user_id, a.created_at,
                    u.login, u.imie, u.nazwisko
             FROM activity_log a
             LEFT JOIN uzytkownicy u ON u.id = a.user_id
             WHERE a.entity_type = :entity_type AND a.entity_id = :entity_id
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':entity_type', $entityType);
        $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('activity_log: fetch failed: ' . $e->getMessage());
    }

    if (!$includeTasks) {
        return $entries;
    }

    ensureCrmTasksTable($pdo);
    try {
        $stmt = $pdo->prepare(
            "SELECT a.id, a.entity_type, a.entity_id, a.action, a.message, a.user_id, a.created_at,
                    u.login, u.imie, u.nazwisko
             FROM activity_log a
             INNER JOIN crm_zadania t ON t.id = a.entity_id AND a.entity_type = 'task'
             LEFT JOIN uzytkownicy u ON u.id = a.user_id
             WHERE t.obiekt_typ = :entity_type AND t.obiekt_id = :entity_id
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':entity_type', $entityType);
        $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $taskEntries = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($taskEntries) {
            $entries = array_merge($entries, $taskEntries);
        }
    } catch (Throwable $e) {
        error_log('activity_log: task fetch failed: ' . $e->getMessage());
    }

    if (!$entries) {
        return [];
    }

    usort($entries, function (array $a, array $b): int {
        $aTime = strtotime((string)($a['created_at'] ?? '')) ?: 0;
        $bTime = strtotime((string)($b['created_at'] ?? '')) ?: 0;
        if ($aTime === $bTime) {
            return (int)($b['id'] ?? 0) <=> (int)($a['id'] ?? 0);
        }
        return $bTime <=> $aTime;
    });

    return array_slice($entries, 0, $limit);
}
