<?php
declare(strict_types=1);

function tableExists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }
    try {
        $driver = strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
            $stmt->execute([':table' => $table]);
            $exists = (bool)$stmt->fetchColumn();
            $stmt->closeCursor();
            return $exists;
        }

        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        $exists = (bool)($stmt && $stmt->fetchColumn());
        if ($stmt) {
            $stmt->closeCursor();
        }
        return $exists;
    } catch (Throwable $e) {
        return false;
    }
}
