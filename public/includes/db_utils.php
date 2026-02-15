<?php
declare(strict_types=1);

function tableExists(PDO $pdo, string $table): bool
{
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
        return false;
    }
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
        $val = (bool)($stmt && $stmt->fetchColumn());
        if ($stmt) {
            $stmt->closeCursor();
        }
        return $val;
    } catch (Throwable $e) {
        return false;
    }
}
