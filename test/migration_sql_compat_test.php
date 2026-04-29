<?php

declare(strict_types=1);

require_once __DIR__ . '/../services/MigrationSqlCompat.php';

function compatAssertSame(string $expected, string $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message . PHP_EOL . 'Expected: ' . $expected . PHP_EOL . 'Actual:   ' . $actual . PHP_EOL);
        exit(1);
    }
}

$missingColumn = MigrationSqlCompat::rewritePortableDdl(
    'ALTER TABLE sms_messages ADD COLUMN IF NOT EXISTS related_type VARCHAR(40) NULL AFTER id',
    static fn (string $table, string $column): bool => false,
    static fn (string $table, string $index): bool => false
);
compatAssertSame(
    'ALTER TABLE `sms_messages` ADD COLUMN `related_type` VARCHAR(40) NULL AFTER id',
    $missingColumn,
    'ADD COLUMN IF NOT EXISTS should be rewritten for older MySQL/MariaDB.'
);

$existingColumn = MigrationSqlCompat::rewritePortableDdl(
    'ALTER TABLE sms_messages ADD COLUMN IF NOT EXISTS related_type VARCHAR(40) NULL AFTER id',
    static fn (string $table, string $column): bool => true,
    static fn (string $table, string $index): bool => false
);
compatAssertSame('', $existingColumn, 'Existing ADD COLUMN IF NOT EXISTS should be skipped.');

$missingIndex = MigrationSqlCompat::rewritePortableDdl(
    'CREATE INDEX IF NOT EXISTS idx_sms_messages_related_created ON sms_messages(related_type, related_id, created_at)',
    static fn (string $table, string $column): bool => false,
    static fn (string $table, string $index): bool => false
);
compatAssertSame(
    'CREATE INDEX `idx_sms_messages_related_created` ON `sms_messages` (related_type, related_id, created_at)',
    $missingIndex,
    'CREATE INDEX IF NOT EXISTS should be rewritten for older MySQL/MariaDB.'
);

$existingIndex = MigrationSqlCompat::rewritePortableDdl(
    'CREATE INDEX IF NOT EXISTS idx_sms_messages_related_created ON sms_messages(related_type, related_id, created_at)',
    static fn (string $table, string $column): bool => false,
    static fn (string $table, string $index): bool => true
);
compatAssertSame('', $existingIndex, 'Existing CREATE INDEX IF NOT EXISTS should be skipped.');

$plainStatement = 'UPDATE sms_messages SET related_type = entity_type WHERE related_type IS NULL';
compatAssertSame(
    $plainStatement,
    MigrationSqlCompat::rewritePortableDdl(
        $plainStatement,
        static fn (string $table, string $column): bool => false,
        static fn (string $table, string $index): bool => false
    ),
    'Unrelated statements must not be changed.'
);

echo 'Migration SQL compatibility test OK' . PHP_EOL;
