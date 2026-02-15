<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/worker_lock.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$lock = new WorkerLock($pdo);

$a = $lock->acquire('gus_worker', 'ownerA');
$b = $lock->acquire('gus_worker', 'ownerB');

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected ".var_export($e,true)." got ".var_export($a,true)."\n";}}

assertSameVal(true, $a['ok'], 'first acquire ok');
assertSameVal(false, $b['ok'], 'second acquire blocked');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
