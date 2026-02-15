<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_queue_status.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureCompaniesTable($pdo);
ensureGusRefreshQueue($pdo);
$pdo->exec("INSERT INTO companies (id, name_full, is_active) VALUES (1,'A',1)");

$pdo->exec("INSERT INTO gus_refresh_queue (company_id,status,priority,next_run_at) VALUES (1,'pending',5,datetime('now'))");
$pdo->exec("INSERT INTO gus_refresh_queue (company_id,status,priority,next_run_at) VALUES (1,'running',3,datetime('now'))");
$pdo->exec("INSERT INTO gus_refresh_queue (company_id,status,priority,next_run_at) VALUES (1,'failed',7,datetime('now'))");

$svc = new GusQueueStatus($pdo);
$st = $svc->getForCompanyId(1);
$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected".var_export($e,true)." got".var_export($a,true)."\n";}}

assertSameVal('running', $st['queue_state'] ?? null, 'running wins');

$pdo->exec("DELETE FROM gus_refresh_queue; INSERT INTO gus_refresh_queue (company_id,status,priority,next_run_at) VALUES (1,'failed',5,datetime('now'))");
$st = $svc->getForCompanyId(1);
assertSameVal('failed', $st['queue_state'] ?? null, 'failed when only failed');

$pdo->exec("DELETE FROM gus_refresh_queue");
$st = $svc->getForCompanyId(1);
assertSameVal('none', $st['queue_state'] ?? null, 'none when no jobs');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
