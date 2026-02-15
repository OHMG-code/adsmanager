<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/db_schema.php';

function ensureGusRateLimit(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS gus_rate_limits (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        limit_key VARCHAR(100) NOT NULL UNIQUE,
        window_start DATETIME NOT NULL,
        count INT NOT NULL DEFAULT 0
    );");
}

function gusRateAllow(PDO $pdo, string $key, int $limit, int $windowSeconds): bool
{
    ensureGusRateLimit($pdo);
    $nowTs = time();
    $windowStart = date('Y-m-d H:i:s', $nowTs - $windowSeconds);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM gus_rate_limits WHERE limit_key = :k');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        if (!$row) {
            $stmt = $pdo->prepare('INSERT INTO gus_rate_limits (limit_key, window_start, count) VALUES (:k, :ws, 1)');
            $stmt->execute([':k' => $key, ':ws' => date('Y-m-d H:i:s', $nowTs)]);
            $pdo->commit();
            return true;
        }
        $currentStart = strtotime((string)$row['window_start']);
        $count = (int)$row['count'];
        if ($currentStart < ($nowTs - $windowSeconds)) {
            $stmt = $pdo->prepare('UPDATE gus_rate_limits SET window_start = :ws, count = 1 WHERE limit_key = :k');
            $stmt->execute([':ws' => date('Y-m-d H:i:s', $nowTs), ':k' => $key]);
            $pdo->commit();
            return true;
        }
        if ($count >= $limit) {
            $pdo->commit();
            return false;
        }
        $stmt = $pdo->prepare('UPDATE gus_rate_limits SET count = count + 1 WHERE limit_key = :k');
        $stmt->execute([':k' => $key]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}
