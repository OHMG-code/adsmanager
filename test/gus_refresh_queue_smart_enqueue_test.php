<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_refresh_queue.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureGusRefreshQueue($pdo);

$res1 = enqueueForCompanies($pdo, [1,2], 5, 'bulk', 1);
$res2 = enqueueForCompanies($pdo, [2,3], 3, 'bulk', 1);

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected".var_export($e,true)." got".var_export($a,true)."\n";}}

assertSameVal(2, $res1['created'], 'first created 2');
assertSameVal(1, $res2['updated'], 'second updated existing');
assertSameVal(1, $res2['created'], 'second created one');

$row = $pdo->query("SELECT priority FROM gus_refresh_queue WHERE company_id=2" )->fetchColumn();
assertSameVal(3, (int)$row, 'priority bumped to min');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
