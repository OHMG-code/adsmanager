<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/admin_audit_logger.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

adminAuditLog($pdo, 'requeue_filter', 'filter', ['id' => 7, 'login' => 'admin'], ['status' => 'failed'], [1,2,3], 3, ['ip' => '127.0.0.1', 'ua' => 'test']);
$rows = adminAuditLast($pdo, 5);

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected ".var_export($e,true)." got ".var_export($a,true)."\n";}}

$first = $rows[0] ?? [];
assertSameVal('requeue_filter', $first['action'] ?? null, 'action stored');
assertSameVal('filter', $first['scope'] ?? null, 'scope stored');
assertSameVal(3, (int)($first['affected_count'] ?? 0), 'affected stored');
assertSameVal('admin', $first['username'] ?? null, 'username stored');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
