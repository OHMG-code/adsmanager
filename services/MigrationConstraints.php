<?php

declare(strict_types=1);

final class MigrationConstraints
{
    public const UNIQUE_COMPANIES_IDENTIFIER = '11C_unique_companies_nip';

    /**
     * Remove the unique-NIP migration when duplicates exist.
     *
     * @param string[] $paths
     * @return string[]
     */
    public static function filterUniqueNipMigration(array $paths, bool $hasDuplicateNip): array
    {
        if (!$hasDuplicateNip) {
            return $paths;
        }

        return array_values(array_filter($paths, static fn (string $path): bool => !self::isUniqueNipMigration($path)));
    }

    public static function isUniqueNipMigration(string $path): bool
    {
        $name = strtolower(pathinfo($path, PATHINFO_FILENAME));
        return str_contains($name, strtolower(self::UNIQUE_COMPANIES_IDENTIFIER));
    }
}
