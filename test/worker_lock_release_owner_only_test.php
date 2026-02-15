<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/worker_lock.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$lock = new WorkerLock($pdo);

$lock->acquire('gus_worker', 'ownerA', 300);
$lock->release('gus_worker', 'other');
$status1 = $lock->getStatus('gus_worker');
$lock->release('gus_worker', 'ownerA');
$status2 = $lock->getStatus('gus_worker');

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected ".var_export($e,true)." got ".var_export($a,true)."\n";}}

assertSameVal(true, $status1['locked'], 'still locked after wrong owner release');
assertSameVal(false, $status2['locked'], 'released by owner');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
