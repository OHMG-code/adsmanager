<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_refresh_queue.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureGusRefreshQueue($pdo);

$job1 = gusQueueEnqueue($pdo, 1, 'stale', 5);
$job2 = gusQueueEnqueue($pdo, 1, 'stale', 3); // should not duplicate

$tests=0;$fail=0;
function assertTrue($c,$l){global $tests,$fail;$tests++;if(!$c){$fail++;echo"FAIL: $l\n";}}
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected".var_export($e,true)." got".var_export($a,true)."\n";}}

assertSameVal($job1['id'], $job2['id'], 'dedupe same company');

$batch = gusQueueClaimBatch($pdo, 1, 'worker1');
assertSameVal(1, count($batch), 'claim one');
assertSameVal('running', $batch[0]['status'], 'claimed status running');

// simulate failure with retry
$jobId = (int)$batch[0]['id'];
gusQueueMarkFailed($pdo, $jobId, 'ERR', 'err', true, 0, 3);
$stmt = $pdo->query('SELECT status, attempts FROM gus_refresh_queue WHERE id='.$jobId);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
assertSameVal('pending', $row['status'], 'back to pending');
assertSameVal(1, (int)$row['attempts'], 'attempt increment');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
