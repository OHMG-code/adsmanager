<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/integration_circuit_breaker.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureIntegrationCircuitBreaker($pdo);

$state = getCircuitState($pdo, 'gus');
assert($state['state'] === 'ok');

setDegraded($pdo, 'gus', 'auth_spike', 1, 'details');
assert(isDegradedNow($pdo, 'gus') === true);

clearCircuit($pdo, 'gus');
assert(isDegradedNow($pdo, 'gus') === false);

echo "OK\n";
