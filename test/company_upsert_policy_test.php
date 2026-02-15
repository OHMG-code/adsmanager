<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/company_upsert_policy.php';

$tests = 0;
$failures = 0;

function assertSame($expected, $actual, string $label): void
{
    global $tests, $failures;
    $tests++;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n\n";
    }
}

// canUpdate helpers
assertSame(true, canUpdateName(['lock_name' => 0]), 'canUpdateName when lock_name=0');
assertSame(false, canUpdateName(['lock_name' => 1]), 'canUpdateName when lock_name=1');
assertSame(true, canUpdateAddress(['lock_address' => 0]), 'canUpdateAddress when lock_address=0');
assertSame(false, canUpdateAddress(['lock_address' => 1]), 'canUpdateAddress when lock_address=1');
assertSame(true, canUpdateIdentifiers(['lock_identifiers' => 0]), 'canUpdateIdentifiers when lock_identifiers=0');
assertSame(false, canUpdateIdentifiers(['lock_identifiers' => 1]), 'canUpdateIdentifiers when lock_identifiers=1');

// mergeScalar
assertSame('A', mergeScalar('A', null, true), 'mergeScalar keeps current when incoming null');
assertSame('A', mergeScalar('A', '', true), 'mergeScalar keeps current when incoming empty string');
assertSame('A', mergeScalar('A', 'B', false), 'mergeScalar keeps current when locked');
assertSame('B', mergeScalar('A', 'B', true), 'mergeScalar updates when allowed');

// filterInputByLocks
$input = [
    'name_full' => 'Firma A',
    'name_short' => 'FA',
    'street' => 'Ulica 1',
    'building_no' => '1',
    'apartment_no' => '2',
    'postal_code' => '00-000',
    'city' => 'Miasto',
    'gmina' => 'Gmina',
    'powiat' => 'Powiat',
    'wojewodztwo' => 'Woj',
    'country' => 'PL',
    'nip' => '123',
    'regon' => '456',
    'krs' => '789',
    'status' => 'AKTYWNY',
];

$locked = filterInputByLocks(['lock_name' => 1, 'lock_address' => 1, 'lock_identifiers' => 1], $input);
assertSame(false, array_key_exists('name_full', $locked), 'filterInputByLocks removes name fields when locked');
assertSame(false, array_key_exists('street', $locked), 'filterInputByLocks removes address fields when locked');
assertSame(false, array_key_exists('nip', $locked), 'filterInputByLocks removes identifier fields when locked');
assertSame(true, array_key_exists('status', $locked), 'filterInputByLocks keeps status');

$unlocked = filterInputByLocks(['lock_name' => 0, 'lock_address' => 0, 'lock_identifiers' => 0], $input);
assertSame(true, array_key_exists('name_full', $unlocked), 'filterInputByLocks keeps name fields when unlocked');
assertSame(true, array_key_exists('street', $unlocked), 'filterInputByLocks keeps address fields when unlocked');
assertSame(true, array_key_exists('nip', $unlocked), 'filterInputByLocks keeps identifier fields when unlocked');

if ($failures > 0) {
    echo "Tests: {$tests}, Failures: {$failures}\n";
    exit(1);
}

echo "Tests: {$tests}, Failures: {$failures}\n";
