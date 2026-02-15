<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/integration_alert_throttle.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureIntegrationAlerts($pdo);

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected".var_export($e,true)." got".var_export($a,true)."\n";}}

$r1 = shouldLogAlert($pdo, 'key1', 900, 'p1');
$r2 = shouldLogAlert($pdo, 'key1', 900, 'p2');
assertSameVal(true, $r1, 'first true');
assertSameVal(false, $r2, 'second within ttl false');
// force stale
$pdo->exec("UPDATE integration_alerts SET last_logged_at = datetime('now','-20 minute') WHERE alert_key='key1'");
$r3 = shouldLogAlert($pdo, 'key1', 900, 'p3');
assertSameVal(true, $r3, 'after ttl true');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
