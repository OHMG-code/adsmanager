<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_refresh_queue.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureGusRefreshQueue($pdo);

$first = gusQueueSmartEnqueue($pdo, 1, 5, 'bulk', false);
$second = gusQueueSmartEnqueue($pdo, 1, 3, 'bulk', false);

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected".var_export($e,true)." got".var_export($a,true)."\n";}}

assertSameVal(true, $first['created'], 'first created');
assertSameVal(true, $second['updated'], 'second updated not create');

// manual bump next_run
$pdo->exec("UPDATE gus_refresh_queue SET next_run_at = datetime('now','+30 minute') WHERE company_id=1");
$third = gusQueueSmartEnqueue($pdo, 1, 1, 'manual', true);
$row = $pdo->query('SELECT next_run_at, priority FROM gus_refresh_queue WHERE company_id=1')->fetch(PDO::FETCH_ASSOC);
assertSameVal(true, strtotime($row['next_run_at']) <= time(), 'force now brings next_run_at to now');
assertSameVal(1, (int)$row['priority'], 'priority bumped to 1');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
