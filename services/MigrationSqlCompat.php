<?php

declare(strict_types=1);

final class MigrationSqlCompat
{
    /**
     * @param callable(string,string):bool $columnExists
     * @param callable(string,string):bool $indexExists
     * @param callable(string):void|null $logger
     */
    public static function rewritePortableDdl(
        string $statement,
        callable $columnExists,
        callable $indexExists,
        ?callable $logger = null
    ): string {
        $normalized = preg_replace('/\s+/', ' ', trim($statement));
        if (!is_string($normalized) || $normalized === '') {
            return $statement;
        }

        if (preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/i', $normalized, $m) === 1) {
            $table = $m[1];
            $column = $m[2];
            if ($columnExists($table, $column)) {
                self::log($logger, sprintf('Skipping existing column %s.%s.', $table, $column));
                return '';
            }
            self::log($logger, sprintf('Rewriting portable ADD COLUMN for %s.%s.', $table, $column));
            return 'ALTER TABLE ' . self::quoteIdentifier($table) . ' ADD COLUMN ' . self::quoteIdentifier($column) . ' ' . $m[3];
        }

        if (preg_match('/^CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s+ON\s+`?([A-Za-z0-9_]+)`?\s*(.+)$/i', $normalized, $m) === 1) {
            $unique = trim((string)($m[1] ?? ''));
            $index = $m[2];
            $table = $m[3];
            if ($indexExists($table, $index)) {
                self::log($logger, sprintf('Skipping existing index %s.%s.', $table, $index));
                return '';
            }
            self::log($logger, sprintf('Rewriting portable CREATE INDEX for %s.%s.', $table, $index));
            return 'CREATE ' . ($unique !== '' ? 'UNIQUE ' : '') . 'INDEX ' . self::quoteIdentifier($index) . ' ON ' . self::quoteIdentifier($table) . ' ' . $m[4];
        }

        return $statement;
    }

    private static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private static function log(?callable $logger, string $message): void
    {
        if ($logger !== null) {
            $logger($message);
        }
    }
}
