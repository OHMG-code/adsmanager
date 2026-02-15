<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/db_schema.php';

function ensureIntegrationCircuitBreaker(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_circuit_breaker (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        integration VARCHAR(30) NOT NULL UNIQUE,
        state VARCHAR(20) NOT NULL,
        reason VARCHAR(50) NULL,
        details TEXT NULL,
        degraded_until DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );");
}

function getCircuitState(PDO $pdo, string $integration): array
{
    ensureIntegrationCircuitBreaker($pdo);
    $stmt = $pdo->prepare('SELECT * FROM integration_circuit_breaker WHERE integration = :i LIMIT 1');
    $stmt->execute([':i' => $integration]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return ['state' => 'ok', 'reason' => null, 'details' => null, 'degraded_until' => null];
    }
    return [
        'state' => $row['state'] ?? 'ok',
        'reason' => $row['reason'] ?? null,
        'details' => $row['details'] ?? null,
        'degraded_until' => $row['degraded_until'] ?? null,
    ];
}

function isDegradedNow(PDO $pdo, string $integration): bool
{
    $state = getCircuitState($pdo, $integration);
    if (($state['state'] ?? 'ok') !== 'degraded') {
        return false;
    }
    if (!empty($state['degraded_until']) && strtotime((string)$state['degraded_until']) < time()) {
        return false;
    }
    return true;
}

function setDegraded(PDO $pdo, string $integration, string $reason, int $minutes, string $details): void
{
    ensureIntegrationCircuitBreaker($pdo);
    $until = date('Y-m-d H:i:s', time() + ($minutes * 60));
    $stmt = $pdo->prepare('INSERT INTO integration_circuit_breaker (integration, state, reason, details, degraded_until) VALUES (:i, "degraded", :r, :d, :u)
        ON CONFLICT(integration) DO UPDATE SET state="degraded", reason=:r2, details=:d2, degraded_until=:u2, updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([
        ':i' => $integration,
        ':r' => $reason,
        ':d' => $details,
        ':u' => $until,
        ':r2' => $reason,
        ':d2' => $details,
        ':u2' => $until,
    ]);
}

function clearCircuit(PDO $pdo, string $integration): void
{
    ensureIntegrationCircuitBreaker($pdo);
    $stmt = $pdo->prepare('INSERT INTO integration_circuit_breaker (integration, state, reason, details, degraded_until) VALUES (:i, "ok", NULL, NULL, NULL)
        ON CONFLICT(integration) DO UPDATE SET state="ok", reason=NULL, details=NULL, degraded_until=NULL, updated_at=CURRENT_TIMESTAMP');
    $stmt->execute([':i' => $integration]);
}
