<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_refresh_queue.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureGusRefreshQueue($pdo);

$pdo->exec("INSERT INTO gus_refresh_queue (company_id, status, attempts, max_attempts, next_run_at) VALUES (2,'failed',2,3,datetime('now'))");
$jobId = (int)$pdo->lastInsertId();

gusQueueRequeue($pdo, $jobId);
$row = $pdo->query('SELECT status, attempts FROM gus_refresh_queue WHERE id='.$jobId)->fetch(PDO::FETCH_ASSOC);
$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected".var_export($e,true)." got".var_export($a,true)."\n";}}
assertSameVal('pending', $row['status'], 'requeue status');
assertSameVal(0, (int)$row['attempts'], 'requeue attempts reset');

gusQueueCancel($pdo, $jobId);
$row = $pdo->query('SELECT status FROM gus_refresh_queue WHERE id='.$jobId)->fetch(PDO::FETCH_ASSOC);
assertSameVal('cancelled', $row['status'], 'cancel status');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
