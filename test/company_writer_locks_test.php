<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/company_writer.php';

$tests = 0;
$failures = 0;

function assertTrue($cond, string $label): void
{
    global $tests, $failures;
    $tests++;
    if (!$cond) {
        $failures++;
        echo "FAIL: {$label}\n";
    }
}

function assertSame($expected, $actual, string $label): void
{
    global $tests, $failures;
    $tests++;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n\n";
    }
}

$input = [
    'name_full' => 'Firma A',
    'street' => 'Ulica 1',
    'city' => 'Miasto',
    'nip' => '1234567890',
    'status' => 'AKTYWNY',
];

$lockName = CompanyWriter::filterInputWithLocks(['lock_name' => 1, 'lock_address' => 0, 'lock_identifiers' => 0], $input);
assertTrue(!array_key_exists('name_full', $lockName['filtered']), 'lock_name blocks name_full');
assertSame(['name_full'], $lockName['skipped_fields_by_lock']['name'], 'skipped_fields_by_lock includes name_full');

$lockAddress = CompanyWriter::filterInputWithLocks(['lock_name' => 0, 'lock_address' => 1, 'lock_identifiers' => 0], $input);
assertTrue(!array_key_exists('street', $lockAddress['filtered']), 'lock_address blocks street');
assertTrue(!array_key_exists('city', $lockAddress['filtered']), 'lock_address blocks city');
assertSame(['street', 'city'], $lockAddress['skipped_fields_by_lock']['address'], 'skipped_fields_by_lock includes address fields');

$lockIdentifiers = CompanyWriter::filterInputWithLocks(['lock_name' => 0, 'lock_address' => 0, 'lock_identifiers' => 1], $input);
assertTrue(!array_key_exists('nip', $lockIdentifiers['filtered']), 'lock_identifiers blocks nip');
assertSame(['nip'], $lockIdentifiers['skipped_fields_by_lock']['identifiers'], 'skipped_fields_by_lock includes nip');

$allLocked = CompanyWriter::filterInputWithLocks(['lock_name' => 1, 'lock_address' => 1, 'lock_identifiers' => 1], $input);
assertTrue(array_key_exists('status', $allLocked['filtered']), 'status remains even when locks are set');

if ($failures > 0) {
    echo "Tests: {$tests}, Failures: {$failures}\n";
    exit(1);
}

echo "Tests: {$tests}, Failures: {$failures}\n";
