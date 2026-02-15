<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MigrationPlan.php';

$priority = [
    '11B_klienci_legacy_nullable',
    '10B_canonical_schema',
    '11D_companies_provenance',
    '12A_gus_refresh_queue',
    '12F_queue_error_fields',
    '12H_company_gus_hold',
    '12I_integration_circuit_breaker',
    '12J_admin_actions_audit',
    '12D_integration_alerts',
    '12L_worker_locks',
    '11C_unique_companies_nip',
];

$files = [
    'sql/migrations/2026_01_27_00_create_companies.sql',
    'sql/migrations/random.sql',
    'sql/migrations/12J_admin_actions_audit.sql',
    'sql/migrations/2026_01_03_01_stage3_mail_sms.sql',
    'sql/migrations/11B_klienci_legacy_nullable.sql',
    'sql/migrations/11C_unique_companies_nip.sql',
    'sql/migrations/10B_canonical_schema.sql',
    'sql/migrations/12A_gus_refresh_queue.sql',
];

$ordered = MigrationPlan::build($files, [
    'priority' => $priority,
    'bootstrap' => ['2026_01_27_00_create_companies.sql'],
]);

$expected = [
    'sql/migrations/2026_01_27_00_create_companies.sql',
    'sql/migrations/11B_klienci_legacy_nullable.sql',
    'sql/migrations/10B_canonical_schema.sql',
    'sql/migrations/12A_gus_refresh_queue.sql',
    'sql/migrations/12J_admin_actions_audit.sql',
    'sql/migrations/11C_unique_companies_nip.sql',
    'sql/migrations/2026_01_03_01_stage3_mail_sms.sql',
    'sql/migrations/random.sql',
];

if ($ordered !== $expected) {
    fwrite(STDERR, 'Sorting test failed. Expected:' . PHP_EOL . var_export($expected, true) . PHP_EOL . 'Got:' . PHP_EOL . var_export($ordered, true) . PHP_EOL);
    exit(1);
}

echo 'Sorting test OK' . PHP_EOL;
