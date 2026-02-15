<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_refresh_queue.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureGusRefreshQueue($pdo);

$pdo->exec("INSERT INTO gus_refresh_queue (company_id, status, locked_at, locked_by, next_run_at) VALUES (1,'running',datetime('now','-1 hour'),'w1',datetime('now'))");

$released = gusQueueReleaseStuck($pdo, 30);
$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected".var_export($e,true)." got".var_export($a,true)."\n";}}
assertSameVal(1, $released, 'one released');
$row = $pdo->query('SELECT status, locked_at FROM gus_refresh_queue WHERE company_id=1')->fetch(PDO::FETCH_ASSOC);
assertSameVal('pending', $row['status'], 'status pending');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
