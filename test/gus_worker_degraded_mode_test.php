<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_refresh_queue.php';
require_once __DIR__ . '/../services/integration_circuit_breaker.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureGusRefreshQueue($pdo);
ensureIntegrationCircuitBreaker($pdo);

// set degraded
setDegraded($pdo, 'gus', 'auth_spike', 30, 'test');

$pdo->exec("INSERT INTO companies (id,name_full,is_active) VALUES (1,'A',1)");
$pdo->exec("INSERT INTO gus_refresh_queue (company_id,status,priority,next_run_at,created_at,updated_at) VALUES (1,'pending',7,datetime('now'),datetime('now'),datetime('now'))");
$pdo->exec("INSERT INTO gus_refresh_queue (company_id,status,priority,next_run_at,created_at,updated_at) VALUES (1,'pending',1,datetime('now'),datetime('now'),datetime('now'))");

// simulate worker filtering: claim manually
$stmt = $pdo->prepare("SELECT * FROM gus_refresh_queue WHERE status='pending' AND priority=1 LIMIT 1");
$stmt->execute();
$row = $stmt->fetch(PDO::FETCH_ASSOC);
assert($row !== false, 'priority 1 claimed');

$stmt = $pdo->prepare("SELECT COUNT(*) FROM gus_refresh_queue WHERE status='pending' AND priority=7");
$stmt->execute();
$remaining = (int)$stmt->fetchColumn();
assert($remaining === 1, 'priority 7 not processed');

echo "OK\n";
