<?php
declare(strict_types=1);

require_once __DIR__ . '/../tools/dedupe_companies_by_nip.php';

$tests = 0;
$failures = 0;

function assertSameVal($expected, $actual, string $label): void
{
    global $tests, $failures;
    $tests++;
    if ($expected !== $actual) {
        $failures++;
        echo "FAIL: {$label}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n\n";
    }
}

$rows = [
    ['id' => 10, 'nip' => '123-456-32-18', 'name_full' => 'A', 'city' => 'X'],
    ['id' => 11, 'nip' => '1234563218', 'name_full' => 'B', 'city' => 'X', 'regon' => '123'],
    ['id' => 12, 'nip' => '1234563218', 'name_full' => null, 'city' => null],
];

$plan = buildDedupePlan($rows);
$group = $plan[0] ?? [];

assertSameVal(11, $group['master_id'] ?? null, 'master chosen by filled fields');
assertSameVal([10, 12], $group['duplicate_ids'] ?? [], 'duplicates list ordered by id');
assertSameVal('1234563218', $group['nip'] ?? null, 'normalized nip');

if ($failures > 0) {
    echo "Tests: {$tests}, Failures: {$failures}\n";
    exit(1);
}

echo "Tests: {$tests}, Failures: {$failures}\n";
