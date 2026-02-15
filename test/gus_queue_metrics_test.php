<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_queue_metrics.php';
require_once __DIR__ . '/../services/gus_refresh_queue.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureGusRefreshQueue($pdo);
$now = time();

// seed
$pdo->exec("INSERT INTO gus_refresh_queue (company_id,status,updated_at,next_run_at,created_at,locked_at) VALUES
 (1,'pending',datetime('now','-10 minute'),datetime('now','-10 minute'),datetime('now','-20 minute'),NULL),
 (2,'running',datetime('now','-40 minute'),datetime('now','-40 minute'),datetime('now','-50 minute'),datetime('now','-40 minute')),
 (3,'failed',datetime('now','-30 minute'),datetime('now','-30 minute'),datetime('now','-35 minute'),NULL),
 (4,'done',datetime('now','-30 minute'),datetime('now','-30 minute'),datetime('now','-30 minute'),NULL)
");

$metrics = (new GusQueueMetrics($pdo))->summary();
$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected".var_export($e,true)." got".var_export($a,true)."\n";}}

assertSameVal(1, $metrics['pending_count'], 'pending count');
assertSameVal(1, $metrics['running_count'], 'running count');
assertSameVal(1, $metrics['failed_count'], 'failed count');
assertSameVal(1, $metrics['done_last_24h'], 'done 24h');
assertSameVal(1, $metrics['stuck_count'], 'stuck count');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
