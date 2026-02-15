<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/db_schema.php';

function ensureIntegrationAlerts(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_alerts (
        alert_key VARCHAR(80) PRIMARY KEY,
        last_logged_at DATETIME NOT NULL,
        last_payload TEXT NULL
    );");
}

function shouldLogAlert(PDO $pdo, string $key, int $ttlSeconds = 900, ?string $payload = null): bool
{
    ensureIntegrationAlerts($pdo);
    $now = time();
    $stmt = $pdo->prepare('SELECT last_logged_at FROM integration_alerts WHERE alert_key = :k');
    $stmt->execute([':k' => $key]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    $stmt->closeCursor();
    if (!$row) {
        $stmtIns = $pdo->prepare('INSERT INTO integration_alerts (alert_key, last_logged_at, last_payload) VALUES (:k, :ts, :p)');
        $stmtIns->execute([':k' => $key, ':ts' => date('Y-m-d H:i:s', $now), ':p' => $payload]);
        return true;
    }
    $last = strtotime((string)$row['last_logged_at']);
    if ($last && ($now - $last) < $ttlSeconds) {
        return false;
    }
    $stmtUpd = $pdo->prepare('UPDATE integration_alerts SET last_logged_at = :ts, last_payload = :p WHERE alert_key = :k');
    $stmtUpd->execute([':ts' => date('Y-m-d H:i:s', $now), ':p' => $payload, ':k' => $key]);
    return true;
}
