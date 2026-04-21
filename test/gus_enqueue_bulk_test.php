<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_refresh_queue.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE klienci (
    id INTEGER PRIMARY KEY,
    nazwa_firmy TEXT NULL,
    company_id INT NULL
)");
ensureClientLeadColumns($pdo);
ensureCompaniesTable($pdo);
ensureGusRefreshQueue($pdo);

$pdo->exec("INSERT INTO companies (id, name_full, is_active) VALUES (1,'A',1),(2,'B',1)");
$pdo->exec("INSERT INTO klienci (id, nazwa_firmy, company_id) VALUES (10,'A',1),(11,'B',2)");

$res = enqueueForCompanies($pdo, [1,2], 3, 'bulk_ui', 1);
$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected".var_export($e,true)." got".var_export($a,true)."\n";}}

assertSameVal(2, $res['created'], 'two created');

// dedupe update
$res2 = enqueueForCompanies($pdo, [1], 2, 'bulk_ui', 1);
assertSameVal(1, $res2['updated'], 'one updated');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
