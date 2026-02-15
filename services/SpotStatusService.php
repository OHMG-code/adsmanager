<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/db_schema.php';

final class SpotStatusService
{
    public static function autoDeactivateExpired(PDO $pdo): int
    {
        try {
            ensureSpotColumns($pdo);
        } catch (Throwable $e) {
            error_log('spot_status: ensureSpotColumns failed: ' . $e->getMessage());
        }

        $cols = getTableColumns($pdo, 'spoty');
        if (!hasColumn($cols, 'data_koniec')) {
            return 0;
        }

        $updates = [];
        $changedConditions = [];

        if (hasColumn($cols, 'status')) {
            $updates[] = "status = 'Nieaktywny'";
            $changedConditions[] = "COALESCE(status, 'Aktywny') <> 'Nieaktywny'";
        }
        if (hasColumn($cols, 'aktywny')) {
            $updates[] = 'aktywny = 0';
            $changedConditions[] = '(aktywny IS NULL OR aktywny <> 0)';
        }

        if (!$updates || !$changedConditions) {
            return 0;
        }

        $sql = 'UPDATE spoty SET ' . implode(', ', $updates) .
            ' WHERE data_koniec IS NOT NULL AND data_koniec < CURDATE()' .
            ' AND (' . implode(' OR ', $changedConditions) . ')';

        return (int)$pdo->exec($sql);
    }

    public static function setSpotActive(PDO $pdo, int $spotId, bool $active): bool
    {
        if ($spotId <= 0) {
            return false;
        }

        try {
            ensureSpotColumns($pdo);
        } catch (Throwable $e) {
            error_log('spot_status: ensureSpotColumns failed: ' . $e->getMessage());
        }

        $cols = getTableColumns($pdo, 'spoty');
        $updates = [];
        $params = [];

        if (hasColumn($cols, 'aktywny')) {
            $updates[] = 'aktywny = ?';
            $params[] = $active ? 1 : 0;
        }
        if (hasColumn($cols, 'status')) {
            $updates[] = 'status = ?';
            $params[] = $active ? 'Aktywny' : 'Nieaktywny';
        }

        if (!$updates) {
            return false;
        }

        $params[] = $spotId;
        $stmt = $pdo->prepare('UPDATE spoty SET ' . implode(', ', $updates) . ' WHERE id = ?');
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }
}
