<?php

declare(strict_types=1);

final class MigrationPlan
{
    /**
     * @param string[] $paths absolute or relative file paths to SQL migrations
     * @param array{priority?: string[], bootstrap?: string[]} $options
     * @return string[] ordered paths
     */
    public static function build(array $paths, array $options = []): array
    {
        $priorityOrder = $options['priority'] ?? [];
        $bootstrapOrder = $options['bootstrap'] ?? [];

        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path) || trim($path) === '') {
                continue;
            }
            $filename = basename($path);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $normalized[$path] = [
                'path' => $path,
                'filename' => $filename,
                'name' => $name,
            ];
        }

        $ordered = [];

        foreach ($bootstrapOrder as $entry) {
            $matches = self::takeFirstMatch($normalized, $entry);
            foreach ($matches as $match) {
                $ordered[] = $match['path'];
            }
        }

        foreach ($priorityOrder as $entry) {
            $matches = self::takeFirstMatch($normalized, $entry);
            foreach ($matches as $match) {
                $ordered[] = $match['path'];
            }
        }

        $remaining = array_values($normalized);
        usort($remaining, static fn (array $a, array $b): int => strcasecmp($a['filename'], $b['filename']));
        foreach ($remaining as $entry) {
            $ordered[] = $entry['path'];
        }

        return $ordered;
    }

    /**
     * @param array<array<string,mixed>> $source
     * @return array<int,array<string,mixed>>
     */
    private static function takeFirstMatch(array &$source, string $entry): array
    {
        $matches = [];
        $normalizedEntry = self::normalizeEntry($entry);
        foreach ($source as $path => $meta) {
            if (self::matchesEntry($meta, $normalizedEntry)) {
                $matches[] = $meta;
                unset($source[$path]);
                break;
            }
        }
        return $matches;
    }

    private static function normalizeEntry(string $entry): string
    {
        $entry = trim($entry);
        if (str_ends_with($entry, '.sql')) {
            $entry = substr($entry, 0, -4);
        }
        return strtolower($entry);
    }

    private static function matchesEntry(array $meta, string $normalizedEntry): bool
    {
        $metaName = strtolower($meta['name'] ?? '');
        $metaFilename = strtolower(pathinfo($meta['filename'] ?? '', PATHINFO_FILENAME));
        if ($metaName === $normalizedEntry || $metaFilename === $normalizedEntry) {
            return true;
        }
        return stripos($metaName, $normalizedEntry) !== false || stripos($metaFilename, $normalizedEntry) !== false;
    }
}
