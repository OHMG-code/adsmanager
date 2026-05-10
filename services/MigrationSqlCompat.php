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

        if (stripos($normalized, ' IF NOT EXISTS ') !== false
            && preg_match('/^ALTER\s+TABLE\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/i', $normalized, $m) === 1
        ) {
            $table = $m[1];
            $clauses = self::splitAlterClauses($m[2]);
            $rewritten = [];
            $changed = false;

            foreach ($clauses as $clause) {
                $clause = trim($clause);
                if ($clause === '') {
                    continue;
                }

                if (preg_match('/^ADD\s+COLUMN\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s+(.+)$/i', $clause, $columnMatch) === 1) {
                    $changed = true;
                    $column = $columnMatch[1];
                    if ($columnExists($table, $column)) {
                        self::log($logger, sprintf('Skipping existing column %s.%s.', $table, $column));
                        continue;
                    }
                    self::log($logger, sprintf('Rewriting portable ADD COLUMN for %s.%s.', $table, $column));
                    $rewritten[] = 'ADD COLUMN ' . self::quoteIdentifier($column) . ' ' . $columnMatch[2];
                    continue;
                }

                if (preg_match('/^ADD\s+(UNIQUE\s+)?(?:INDEX|KEY)\s+IF\s+NOT\s+EXISTS\s+`?([A-Za-z0-9_]+)`?\s*(.+)$/i', $clause, $indexMatch) === 1) {
                    $changed = true;
                    $unique = trim((string)($indexMatch[1] ?? ''));
                    $index = $indexMatch[2];
                    if ($indexExists($table, $index)) {
                        self::log($logger, sprintf('Skipping existing index %s.%s.', $table, $index));
                        continue;
                    }
                    self::log($logger, sprintf('Rewriting portable ADD INDEX for %s.%s.', $table, $index));
                    $rewritten[] = 'ADD ' . ($unique !== '' ? 'UNIQUE ' : '') . 'INDEX ' . self::quoteIdentifier($index) . ' ' . $indexMatch[3];
                    continue;
                }

                $rewritten[] = $clause;
            }

            if ($changed) {
                if ($rewritten === []) {
                    return '';
                }
                return 'ALTER TABLE ' . self::quoteIdentifier($table) . ' ' . implode(', ', $rewritten);
            }
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

    /**
     * @return list<string>
     */
    private static function splitAlterClauses(string $clauses): array
    {
        $length = strlen($clauses);
        $parts = [];
        $buffer = '';
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $inBacktick = false;
        $escape = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $clauses[$i];

            if ($char === '\\' && !$escape) {
                $escape = true;
                $buffer .= $char;
                continue;
            }

            if ($char === "'" && !$inDouble && !$inBacktick && !$escape) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle && !$inBacktick && !$escape) {
                $inDouble = !$inDouble;
            } elseif ($char === '`' && !$inSingle && !$inDouble && !$escape) {
                $inBacktick = !$inBacktick;
            } elseif (!$inSingle && !$inDouble && !$inBacktick) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')' && $depth > 0) {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $part = trim($buffer);
                    if ($part !== '') {
                        $parts[] = $part;
                    }
                    $buffer = '';
                    $escape = false;
                    continue;
                }
            }

            $escape = false;
            $buffer .= $char;
        }

        $part = trim($buffer);
        if ($part !== '') {
            $parts[] = $part;
        }

        return $parts;
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
