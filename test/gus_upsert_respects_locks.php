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

$company = [
    'lock_name' => 0,
    'lock_address' => 1,
    'lock_identifiers' => 0,
];

$input = [
    'street' => 'Nowa 1',
    'postal_code' => '00-100',
    'city' => 'Warszawa',
    'status' => 'nieaktywny',
];

$filtered = CompanyWriter::filterInputWithLocks($company, $input);

assertTrue(!array_key_exists('street', $filtered['filtered']), 'street skipped when lock_address=1');
assertTrue(!array_key_exists('postal_code', $filtered['filtered']), 'postal_code skipped when lock_address=1');
assertTrue(!array_key_exists('city', $filtered['filtered']), 'city skipped when lock_address=1');
assertSame('nieaktywny', $filtered['filtered']['status'] ?? null, 'status remains even with address lock');
assertTrue(in_array('street', $filtered['skipped_fields_by_lock']['address'] ?? [], true), 'skipped list contains street');
assertTrue(in_array('postal_code', $filtered['skipped_fields_by_lock']['address'] ?? [], true), 'skipped list contains postal_code');
assertTrue(in_array('city', $filtered['skipped_fields_by_lock']['address'] ?? [], true), 'skipped list contains city');

if ($failures > 0) {
    echo "Tests: {$tests}, Failures: {$failures}\n";
    exit(1);
}

echo "Tests: {$tests}, Failures: {$failures}\n";
