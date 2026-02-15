<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/company_writer.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureCompaniesTable($pdo);
$pdo->exec("ALTER TABLE companies ADD COLUMN gus_hold_until DATETIME;".
          "ALTER TABLE companies ADD COLUMN gus_hold_reason VARCHAR(20);".
          "ALTER TABLE companies ADD COLUMN gus_not_found TINYINT DEFAULT 0;".
          "ALTER TABLE companies ADD COLUMN gus_not_found_at DATETIME;".
          "ALTER TABLE companies ADD COLUMN gus_fail_streak INT DEFAULT 0;".
          "ALTER TABLE companies ADD COLUMN gus_last_error_class VARCHAR(20);");
$pdo->exec("INSERT INTO companies (id,name_full,is_active) VALUES (1,'A',1)");
$writer = new CompanyWriter($pdo);

// auth error sets hold
$writer->upsertForCompanyId(1, [
    'gus_last_status' => 'ERR',
    'gus_error_class' => 'auth',
    'gus_last_error_message' => 'login failed',
], 0, 'gus');
$row = $pdo->query('SELECT gus_hold_reason, gus_hold_until, gus_fail_streak FROM companies WHERE id=1')->fetch(PDO::FETCH_ASSOC);
assert($row['gus_hold_reason'] === 'auth');
assert((int)$row['gus_fail_streak'] === 1);

// success clears hold
$writer->upsertForCompanyId(1, [
    'gus_last_status' => 'OK',
    'gus_last_refresh_at' => date('Y-m-d H:i:s'),
], 0, 'gus');
$row = $pdo->query('SELECT gus_hold_reason, gus_hold_until, gus_fail_streak FROM companies WHERE id=1')->fetch(PDO::FETCH_ASSOC);
assert($row['gus_hold_reason'] === null);
assert((int)$row['gus_fail_streak'] === 0);

// invalid_request sets very long hold and invalid_identifier reason
$writer->upsertForCompanyId(1, [
    'gus_last_status' => 'ERR',
    'gus_error_class' => 'invalid_request',
    'gus_last_error_message' => 'invalid nip length',
], 0, 'gus');
$row = $pdo->query('SELECT gus_hold_reason, gus_hold_until FROM companies WHERE id=1')->fetch(PDO::FETCH_ASSOC);
assert($row['gus_hold_reason'] === 'invalid_identifier');
assert(strtotime((string)$row['gus_hold_until']) > strtotime('+300 days'));

// not_found sets long hold and flag
$writer->upsertForCompanyId(1, [
    'gus_last_status' => 'ERR',
    'gus_error_class' => 'not_found',
    'gus_last_error_message' => 'brak danych',
], 0, 'gus');
$row = $pdo->query('SELECT gus_not_found, gus_hold_reason FROM companies WHERE id=1')->fetch(PDO::FETCH_ASSOC);
assert((int)$row['gus_not_found'] === 1);
assert($row['gus_hold_reason'] === 'not_found');

echo "OK\n";
