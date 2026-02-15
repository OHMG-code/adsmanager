<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_refresh_queue.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureGusRefreshQueue($pdo);

// seed 201 failed jobs
for ($i = 0; $i < 201; $i++) {
    $stmt = $pdo->prepare("INSERT INTO gus_refresh_queue (company_id, status, next_run_at) VALUES (:cid, 'failed', datetime('now'))");
    $stmt->execute([':cid' => $i + 1]);
}

$idsProbe = findJobIdsByFilter($pdo, ['status' => 'failed'], 201);

$res = requeueByIds($pdo, $idsProbe, 200);

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected ".var_export($e,true)." got ".var_export($a,true)."\n";}}

assertSameVal(201, count($idsProbe), 'probe returns 201 ids');
assertSameVal(200, $res['affected'], 'affected capped at 200');
assertSameVal(1, count($idsProbe) - $res['affected'], 'one skipped beyond limit');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
