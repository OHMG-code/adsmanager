<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_refresh_queue.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureGusRefreshQueue($pdo);

$pdo->exec("INSERT INTO gus_refresh_queue (company_id,status,priority,next_run_at,updated_at,finished_at,error_class) VALUES (1,'failed',5,datetime('now','+20 minute'),datetime('now'),datetime('now'),'transient')");

// stale enqueue should not override if failed exists and next_run_at future
$res = gusQueueSmartEnqueue($pdo, 1, 7, 'stale', false);
$row = $pdo->query('SELECT COUNT(*) FROM gus_refresh_queue WHERE company_id=1')->fetchColumn();
$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected".var_export($e,true)." got".var_export($a,true)."\n";}}

assertSameVal(1, (int)$row, 'no new job created when failed future next_run');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
