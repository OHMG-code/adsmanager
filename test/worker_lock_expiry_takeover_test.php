<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/worker_lock.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$lock = new WorkerLock($pdo);

$lock->acquire('gus_worker', 'ownerA', 1);
sleep(2); // let expire
$takeover = $lock->acquire('gus_worker', 'ownerB', 600);
$status = $lock->getStatus('gus_worker');

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected ".var_export($e,true)." got ".var_export($a,true)."\n";}}

assertSameVal(true, $takeover['ok'], 'takeover after expiry ok');
assertSameVal('ownerB', $status['locked_by'] ?? null, 'owner switched');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
