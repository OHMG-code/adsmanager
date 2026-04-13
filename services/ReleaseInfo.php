<?php

declare(strict_types=1);

final class ReleaseInfo
{
    public const SCHEMA_VERSION = 1;
    public const DEFAULT_CHANNEL = 'stable';

    private string $rootDir;
    private string $path;

    public function __construct(?string $rootDir = null, ?string $path = null)
    {
        $this->rootDir = $rootDir !== null ? rtrim($rootDir, '/') : dirname(__DIR__);
        $this->path = $path ?? ($this->rootDir . '/release.json');
    }

    /**
     * @return array<string,mixed>
     */
    public function load(): array
    {
        $payload = [
            'ok' => false,
            'path' => $this->path,
            'errors' => [],
            'schema_version' => null,
            'product' => '',
            'version' => '',
            'channel' => self::DEFAULT_CHANNEL,
            'published_at' => '',
            'baseline_id' => '',
            'manifest_url' => '',
            'notes_url' => '',
        ];

        if (!is_file($this->path) || !is_readable($this->path)) {
            $payload['errors'][] = 'Brakuje pliku release.json albo nie można go odczytać.';
            return $payload;
        }

        $content = file_get_contents($this->path);
        if ($content === false) {
            $payload['errors'][] = 'Nie udało się odczytać release.json.';
            return $payload;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded)) {
            $payload['errors'][] = 'release.json nie zawiera poprawnego obiektu JSON.';
            return $payload;
        }

        $payload['schema_version'] = isset($decoded['schema_version']) ? (int)$decoded['schema_version'] : null;
        $payload['product'] = trim((string)($decoded['product'] ?? ''));
        $payload['version'] = trim((string)($decoded['version'] ?? ''));
        $payload['channel'] = trim((string)($decoded['channel'] ?? self::DEFAULT_CHANNEL)) ?: self::DEFAULT_CHANNEL;
        $payload['published_at'] = trim((string)($decoded['published_at'] ?? ''));
        $payload['baseline_id'] = trim((string)($decoded['baseline_id'] ?? ''));
        $payload['manifest_url'] = trim((string)($decoded['manifest_url'] ?? ''));
        $payload['notes_url'] = trim((string)($decoded['notes_url'] ?? ''));

        $errors = [];
        if ($payload['schema_version'] !== self::SCHEMA_VERSION) {
            $errors[] = 'release.json ma nieobsługiwaną wersję schematu.';
        }
        if ($payload['product'] !== 'crm') {
            $errors[] = 'release.json ma niepoprawne pole product.';
        }
        if (!self::isValidCalver($payload['version'])) {
            $errors[] = 'Pole version musi być numerycznym CalVer w formacie YYYY.MM.DD.N.';
        }
        if (!preg_match('/^[a-z0-9._-]{2,32}$/i', $payload['channel'])) {
            $errors[] = 'Pole channel ma niepoprawny format.';
        }
        if ($payload['published_at'] !== '' && !self::isValidIsoDatetime($payload['published_at'])) {
            $errors[] = 'Pole published_at musi być poprawną datą ISO-8601.';
        }
        if ($payload['manifest_url'] !== '' && !self::isValidHttpsUrl($payload['manifest_url'])) {
            $errors[] = 'Pole manifest_url musi być poprawnym adresem HTTPS albo pustym stringiem.';
        }
        if ($payload['notes_url'] !== '' && !self::isValidHttpsUrl($payload['notes_url'])) {
            $errors[] = 'Pole notes_url musi być poprawnym adresem HTTPS albo pustym stringiem.';
        }

        if ($errors !== []) {
            $payload['errors'] = $errors;
            return $payload;
        }

        $payload['ok'] = true;
        return $payload;
    }

    public static function isValidCalver(string $version): bool
    {
        return preg_match('/^\d{4}\.\d{2}\.\d{2}\.\d+$/', $version) === 1;
    }

    public static function compareVersions(string $left, string $right): int
    {
        $leftParts = array_map('intval', explode('.', $left));
        $rightParts = array_map('intval', explode('.', $right));
        $max = max(count($leftParts), count($rightParts));

        for ($i = 0; $i < $max; $i++) {
            $leftPart = $leftParts[$i] ?? 0;
            $rightPart = $rightParts[$i] ?? 0;
            if ($leftPart < $rightPart) {
                return -1;
            }
            if ($leftPart > $rightPart) {
                return 1;
            }
        }

        return 0;
    }

    public static function isValidHttpsUrl(string $url): bool
    {
        if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $parts = parse_url($url);
        if (!is_array($parts)) {
            return false;
        }

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        $host = trim((string)($parts['host'] ?? ''));

        return $scheme === 'https' && $host !== '';
    }

    public static function isValidIsoDatetime(string $value): bool
    {
        if ($value === '' || strpos($value, 'T') === false) {
            return false;
        }

        try {
            new DateTimeImmutable($value);
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }
}
