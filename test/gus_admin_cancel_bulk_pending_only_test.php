<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_refresh_queue.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureGusRefreshQueue($pdo);

$pdo->exec("INSERT INTO gus_refresh_queue (company_id, status, next_run_at) VALUES (1,'pending',datetime('now'))");
$pendingId = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO gus_refresh_queue (company_id, status, next_run_at) VALUES (2,'running',datetime('now'))");
$runningId = (int)$pdo->lastInsertId();

$res = cancelByIds($pdo, [$pendingId, $runningId], 200);

$pendingStatus = $pdo->query("SELECT status FROM gus_refresh_queue WHERE id={$pendingId}")->fetchColumn();
$runningStatus = $pdo->query("SELECT status FROM gus_refresh_queue WHERE id={$runningId}")->fetchColumn();

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected ".var_export($e,true)." got ".var_export($a,true)."\n";}}

assertSameVal('cancelled', $pendingStatus, 'pending cancelled');
assertSameVal('running', $runningStatus, 'running untouched');
assertSameVal(1, $res['affected'], 'affected only pending');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
