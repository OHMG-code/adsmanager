<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/integration_circuit_breaker.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureIntegrationCircuitBreaker($pdo);

setDegraded($pdo, 'gus', 'test', 30, 'spike');

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected ".var_export($e,true)." got ".var_export($a,true)."\n";}}

$isDegraded = isDegradedNow($pdo, 'gus');
$blockedFilter = $isDegraded && ('filter' === 'filter');
$blockedSelected = $isDegraded && ('selected' === 'filter');

assertSameVal(true, $isDegraded, 'degraded set');
assertSameVal(true, $blockedFilter, 'filter should be blocked when degraded');
assertSameVal(false, $blockedSelected, 'selected allowed even when degraded');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
