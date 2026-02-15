<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MigrationConstraints.php';

$files = [
    'sql/migrations/10B_dummy.sql',
    'sql/migrations/11C_unique_companies_nip.sql',
    'sql/migrations/12A_another.sql',
];

$filteredWithDuplicates = MigrationConstraints::filterUniqueNipMigration($files, true);
if (in_array('sql/migrations/11C_unique_companies_nip.sql', $filteredWithDuplicates, true)) {
    fwrite(STDERR, 'Expected unique NIP migration to be skipped when duplicates exist.' . PHP_EOL);
    exit(1);
}

$filteredWithoutDuplicates = MigrationConstraints::filterUniqueNipMigration($files, false);
if (!in_array('sql/migrations/11C_unique_companies_nip.sql', $filteredWithoutDuplicates, true)) {
    fwrite(STDERR, 'Expected unique NIP migration to remain when there are no duplicates.' . PHP_EOL);
    exit(1);
}

echo 'Skip unique test OK' . PHP_EOL;
