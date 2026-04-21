<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/db_schema.php';

function integrationCircuitDriver(PDO $pdo): string
{
    try {
        return strtolower((string)$pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    } catch (Throwable $e) {
        return '';
    }
}

function ensureIntegrationCircuitBreaker(PDO $pdo): void
{
    if (integrationCircuitDriver($pdo) === 'sqlite') {
        $pdo->exec("CREATE TABLE IF NOT EXISTS integration_circuit_breaker (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            integration VARCHAR(30) NOT NULL UNIQUE,
            state VARCHAR(20) NOT NULL,
            reason VARCHAR(50) NULL,
            details TEXT NULL,
            degraded_until DATETIME NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );");
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS integration_circuit_breaker (
        id INT AUTO_INCREMENT PRIMARY KEY,
        integration VARCHAR(30) NOT NULL UNIQUE,
        state VARCHAR(20) NOT NULL,
        reason VARCHAR(50) NULL,
        details TEXT NULL,
        degraded_until DATETIME NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
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
    if (integrationCircuitDriver($pdo) === 'sqlite') {
        $update = $pdo->prepare("UPDATE integration_circuit_breaker
            SET state = 'degraded',
                reason = :r,
                details = :d,
                degraded_until = :u,
                updated_at = CURRENT_TIMESTAMP
            WHERE integration = :i");
        $update->execute([
            ':i' => $integration,
            ':r' => $reason,
            ':d' => $details,
            ':u' => $until,
        ]);
        if ($update->rowCount() > 0) {
            return;
        }
        $insert = $pdo->prepare("INSERT INTO integration_circuit_breaker
            (integration, state, reason, details, degraded_until, updated_at)
            VALUES
            (:i, 'degraded', :r, :d, :u, CURRENT_TIMESTAMP)");
        $insert->execute([
            ':i' => $integration,
            ':r' => $reason,
            ':d' => $details,
            ':u' => $until,
        ]);
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO integration_circuit_breaker
        (integration, state, reason, details, degraded_until, updated_at)
     VALUES
        (:i, 'degraded', :r, :d, :u, CURRENT_TIMESTAMP)
     ON DUPLICATE KEY UPDATE
        state = 'degraded',
        reason = VALUES(reason),
        details = VALUES(details),
        degraded_until = VALUES(degraded_until),
        updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([
        ':i' => $integration,
        ':r' => $reason,
        ':d' => $details,
        ':u' => $until,
    ]);
}

function clearCircuit(PDO $pdo, string $integration): void
{
    ensureIntegrationCircuitBreaker($pdo);
    if (integrationCircuitDriver($pdo) === 'sqlite') {
        $update = $pdo->prepare("UPDATE integration_circuit_breaker
            SET state = 'ok',
                reason = NULL,
                details = NULL,
                degraded_until = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE integration = :i");
        $update->execute([':i' => $integration]);
        if ($update->rowCount() > 0) {
            return;
        }
        $insert = $pdo->prepare("INSERT INTO integration_circuit_breaker
            (integration, state, reason, details, degraded_until, updated_at)
         VALUES
            (:i, 'ok', NULL, NULL, NULL, CURRENT_TIMESTAMP)");
        $insert->execute([':i' => $integration]);
        return;
    }

    $stmt = $pdo->prepare("INSERT INTO integration_circuit_breaker
        (integration, state, reason, details, degraded_until, updated_at)
     VALUES
        (:i, 'ok', NULL, NULL, NULL, CURRENT_TIMESTAMP)
     ON DUPLICATE KEY UPDATE
        state = 'ok',
        reason = NULL,
        details = NULL,
        degraded_until = NULL,
        updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([':i' => $integration]);
}
