<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/worker_lock.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$lock = new WorkerLock($pdo);

$lock->acquire('gus_worker', 'ownerA', 2);
$status1 = $lock->getStatus('gus_worker');
sleep(1);
$lock->heartbeat('gus_worker', 'ownerA', 5);
$status2 = $lock->getStatus('gus_worker');

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected ".var_export($e,true)." got ".var_export($a,true)."\n";}}

$exp1 = strtotime((string)$status1['expires_at']);
$exp2 = strtotime((string)$status2['expires_at']);
assertSameVal(true, $exp2 > $exp1, 'heartbeat extends expiry');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
