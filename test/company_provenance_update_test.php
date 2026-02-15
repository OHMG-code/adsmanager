<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/company_writer.php';

function setupDb(): PDO {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureCompaniesTable($pdo);
    $pdo->exec("ALTER TABLE companies ADD COLUMN name_source VARCHAR(10)");
    $pdo->exec("ALTER TABLE companies ADD COLUMN address_source VARCHAR(10)");
    $pdo->exec("ALTER TABLE companies ADD COLUMN identifiers_source VARCHAR(10)");
    $pdo->exec("ALTER TABLE companies ADD COLUMN name_updated_at DATETIME");
    $pdo->exec("ALTER TABLE companies ADD COLUMN address_updated_at DATETIME");
    $pdo->exec("ALTER TABLE companies ADD COLUMN identifiers_updated_at DATETIME");
    $pdo->exec("ALTER TABLE companies ADD COLUMN last_manual_update_at DATETIME");
    $pdo->exec("ALTER TABLE companies ADD COLUMN last_gus_update_at DATETIME");
    return $pdo;
}

function fetchCompany(PDO $pdo, int $id): array {
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = :id');
    $stmt->execute([':id' => $id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$tests = 0; $failures = 0;
function assertTrue($cond, string $label): void {global $tests,$failures;$tests++;if(!$cond){$failures++;echo"FAIL: $label\n";}}
function assertSameVal($exp,$act,string $label): void {global $tests,$failures;$tests++;if($exp!==$act){$failures++;echo"FAIL: $label\nExpected:".var_export($exp,true)."\nActual:".var_export($act,true)."\n";}}

// Scenario A: manual update sets sources and timestamps
$pdo = setupDb();
$pdo->exec("INSERT INTO companies (name_full, is_active) VALUES ('Old',1)");
$writer = new CompanyWriter($pdo);
$writer->upsertByCompanyId(1, [
    'name_full' => 'New Name',
    'street' => 'X',
    'city' => 'Y',
], 1, 'manual');
$row = fetchCompany($pdo,1);
assertSameVal('manual', $row['name_source'] ?? null, 'A name_source');
assertSameVal('manual', $row['address_source'] ?? null, 'A address_source');
assertTrue(!empty($row['name_updated_at']), 'A name_updated_at set');
assertTrue(!empty($row['address_updated_at']), 'A address_updated_at set');
assertTrue(!empty($row['last_manual_update_at']), 'A last_manual_update_at set');

// Scenario B: gus with lock_address keeps address source null but updates status
$pdo = setupDb();
$pdo->exec("INSERT INTO companies (name_full, street, city, lock_address, status, is_active) VALUES ('Cmp','Old','OldCity',1,'OLD',1)");
$writer = new CompanyWriter($pdo);
$writer->upsertByCompanyId(1, [
    'street' => 'NewStreet',
    'city' => 'NewCity',
    'status' => 'NEW',
], 1, 'gus');
$row = fetchCompany($pdo,1);
assertSameVal('NEW', $row['status'] ?? null, 'B status updated');
assertTrue(empty($row['name_source']), 'B name_source untouched');
assertTrue(empty($row['address_source']), 'B address_source unchanged due lock');
assertTrue(empty($row['address_updated_at']), 'B address_updated_at unchanged');
assertTrue(!empty($row['last_gus_update_at']), 'B last_gus_update_at set');

// Scenario C: gus without lock updates address provenance
$pdo = setupDb();
$pdo->exec("INSERT INTO companies (name_full, street, city, status, is_active) VALUES ('Cmp','Old','OldCity','OLD',1)");
$writer = new CompanyWriter($pdo);
$writer->upsertByCompanyId(1, [
    'street' => 'NewStreet',
    'city' => 'NewCity',
], 1, 'gus');
$row = fetchCompany($pdo,1);
assertSameVal('gus', $row['address_source'] ?? null, 'C address_source gus');
assertTrue(!empty($row['address_updated_at']), 'C address_updated_at set');
assertTrue(!empty($row['last_gus_update_at']), 'C last_gus_update_at set');

if($failures>0){echo"Tests: $tests, Failures: $failures\n";exit(1);}echo"Tests: $tests, Failures: $failures\n";
