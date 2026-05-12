<?php
declare(strict_types=1);

final class InstallationUrl
{
    public static function normalize(?string $url): string
    {
        $url = trim((string)$url);
        if ($url === '') {
            return '';
        }
        return rtrim($url, '/');
    }

    /**
     * @return list<string>
     */
    public static function validate(?string $url, string $appEnv = 'production'): array
    {
        $rawUrl = trim((string)$url);
        $url = self::normalize($rawUrl);
        if ($url === '') {
            return [];
        }

        $errors = [];
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Adres instalacji CRM musi byc poprawnym adresem URL.';
            return $errors;
        }

        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: ''));
        if (strtolower($appEnv) === 'production' && $scheme !== 'https') {
            $errors[] = 'W srodowisku produkcyjnym adres instalacji CRM musi zaczynac sie od https://.';
        }

        if (substr($rawUrl, -1) === '/') {
            $errors[] = 'Adres instalacji CRM nie moze konczyc sie ukosnikiem.';
        }

        $path = trim((string)(parse_url($url, PHP_URL_PATH) ?: ''), '/');
        if ($path !== '' && preg_match('/\.[A-Za-z0-9]{2,8}$/', basename($path))) {
            $errors[] = 'Adres instalacji CRM nie moze wskazywac na konkretny plik.';
        }

        return $errors;
    }

    /**
     * @param array<string,mixed> $server
     * @return array{url:string,source:string,warnings:list<string>,errors:list<string>}
     */
    public static function resolve(?PDO $pdo = null, array $server = []): array
    {
        $warnings = [];
        $configured = self::configValue();
        $source = 'config';

        if ($configured === '' && $pdo instanceof PDO) {
            $configured = self::databaseValue($pdo);
            $source = $configured !== '' ? 'database' : 'autodetect';
        }

        if ($configured === '') {
            $configured = self::autodetect($server);
            $source = 'autodetect';
            $warnings[] = 'Adres instalacji CRM nie zostal ustawiony recznie. Endpoint zostal wykryty automatycznie z aktualnego requestu.';
        }

        $url = self::normalize($configured);
        $errors = self::validate($url, defined('APP_ENV') ? (string)APP_ENV : 'production');
        return [
            'url' => $url,
            'source' => $source,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    public static function endpointUrl(?PDO $pdo = null, array $server = []): array
    {
        $resolved = self::resolve($pdo, $server);
        $resolved['endpoint'] = $resolved['url'] !== ''
            ? $resolved['url'] . '/api/public/lead-form-submit.php'
            : '/api/public/lead-form-submit.php';
        return $resolved;
    }

    private static function configValue(): string
    {
        foreach (['APP_URL', 'CRM_BASE_URL', 'INSTALLATION_URL'] as $constant) {
            if (defined($constant)) {
                $value = self::normalize((string)constant($constant));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        foreach (['APP_URL', 'CRM_BASE_URL', 'INSTALLATION_URL'] as $key) {
            $value = getenv($key);
            if ($value !== false && trim((string)$value) !== '') {
                return self::normalize((string)$value);
            }
        }

        return '';
    }

    private static function databaseValue(PDO $pdo): string
    {
        try {
            $stmt = $pdo->query('SELECT installation_url FROM konfiguracja_systemu WHERE id = 1 LIMIT 1');
            $value = $stmt ? $stmt->fetchColumn() : false;
            return self::normalize($value !== false ? (string)$value : '');
        } catch (Throwable $e) {
            return '';
        }
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function autodetect(array $server): string
    {
        $https = strtolower((string)($server['HTTPS'] ?? ''));
        $forwardedProto = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
        $scheme = ($https !== '' && $https !== 'off') || $forwardedProto === 'https' ? 'https' : 'http';
        $host = trim((string)($server['HTTP_X_FORWARDED_HOST'] ?? $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? ''));
        if ($host === '') {
            return '';
        }

        $base = defined('BASE_URL') ? trim((string)BASE_URL) : '';
        return self::normalize($scheme . '://' . $host . $base);
    }
}
