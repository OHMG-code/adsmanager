<?php

declare(strict_types=1);

require_once __DIR__ . '/ReleaseInfo.php';

final class RemoteReleaseManifestClient
{
    private int $connectTimeoutSeconds;
    private int $timeoutSeconds;
    private int $maxBytes;
    /** @var callable|null */
    private $transport;

    /**
     * @param array{connect_timeout?:int,timeout?:int,max_bytes?:int,transport?:callable|null} $options
     */
    public function __construct(array $options = [])
    {
        $this->connectTimeoutSeconds = max(1, (int)($options['connect_timeout'] ?? 3));
        $this->timeoutSeconds = max($this->connectTimeoutSeconds, (int)($options['timeout'] ?? 5));
        $this->maxBytes = max(1024, (int)($options['max_bytes'] ?? 262144));
        $this->transport = $options['transport'] ?? null;
    }

    /**
     * @param array<string,string> $expected
     * @return array<string,mixed>
     */
    public function fetch(string $url, array $expected = []): array
    {
        $url = trim($url);
        if ($url === '') {
            return $this->errorResult('manifest_not_configured', 'Brakuje adresu manifestu aktualizacji.');
        }
        if (!ReleaseInfo::isValidHttpsUrl($url)) {
            return $this->errorResult('invalid_manifest_url', 'Adres manifestu musi wskazywać na zasób HTTPS.');
        }

        $response = $this->download($url);
        if (!$response['ok']) {
            return $this->errorResult((string)$response['status'], (string)$response['error']);
        }

        $body = (string)$response['body'];
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return $this->errorResult('invalid_manifest_json', 'Zdalny manifest nie zawiera poprawnego JSON-a.');
        }

        $validation = $this->validateManifest($decoded, $expected);
        if (!$validation['ok']) {
            return $validation;
        }

        return [
            'ok' => true,
            'status' => 'success',
            'manifest' => $validation['manifest'],
            'fetched_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,string> $expected
     * @return array<string,mixed>
     */
    private function validateManifest(array $manifest, array $expected): array
    {
        if (array_key_exists('version', $manifest) && array_key_exists('download_url', $manifest)) {
            return $this->validateSimpleManifest($manifest, $expected);
        }

        $schemaVersion = isset($manifest['schema_version']) ? (int)$manifest['schema_version'] : null;
        $product = trim((string)($manifest['product'] ?? ''));
        $channel = trim((string)($manifest['channel'] ?? ''));
        $generatedAt = trim((string)($manifest['generated_at'] ?? ''));
        $latestVersion = trim((string)($manifest['latest_version'] ?? ''));
        $releases = $manifest['releases'] ?? null;

        if ($schemaVersion !== ReleaseInfo::SCHEMA_VERSION) {
            return $this->errorResult('invalid_manifest_schema', 'Manifest ma nieobsługiwaną wersję schematu.');
        }
        if ($product !== 'crm') {
            return $this->errorResult('invalid_manifest_product', 'Manifest dotyczy innego produktu niż CRM.');
        }
        if (($expected['product'] ?? 'crm') !== '' && $product !== (string)$expected['product']) {
            return $this->errorResult('invalid_manifest_product', 'Manifest nie zgadza się z lokalnym produktem.');
        }
        if (($expected['channel'] ?? '') !== '' && $channel !== (string)$expected['channel']) {
            return $this->errorResult('invalid_manifest_channel', 'Manifest nie zgadza się z lokalnym kanałem wydań.');
        }
        if ($generatedAt !== '' && !ReleaseInfo::isValidIsoDatetime($generatedAt)) {
            return $this->errorResult('invalid_manifest_generated_at', 'Manifest ma niepoprawne generated_at.');
        }
        if (!ReleaseInfo::isValidCalver($latestVersion)) {
            return $this->errorResult('invalid_manifest_latest_version', 'Manifest ma niepoprawne latest_version.');
        }
        if (!is_array($releases) || $releases === []) {
            return $this->errorResult('invalid_manifest_releases', 'Manifest nie zawiera listy wydań.');
        }

        $normalizedReleases = [];
        $seenVersions = [];
        foreach ($releases as $entry) {
            if (!is_array($entry)) {
                return $this->errorResult('invalid_manifest_release_entry', 'Jedno z wydań manifestu ma niepoprawny format.');
            }

            $version = trim((string)($entry['version'] ?? ''));
            $publishedAt = trim((string)($entry['published_at'] ?? ''));
            $notesUrl = trim((string)($entry['notes_url'] ?? ''));
            $downloadUrl = trim((string)($entry['download_url'] ?? ''));
            $changelog = $entry['changelog'] ?? [];
            $migrationHints = $entry['migration_hints'] ?? [];

            if (!ReleaseInfo::isValidCalver($version)) {
                return $this->errorResult('invalid_manifest_release_version', 'Jedno z wydań ma niepoprawny numer wersji.');
            }
            if (isset($seenVersions[$version])) {
                return $this->errorResult('duplicate_manifest_release_version', 'Manifest zawiera duplikat numeru wersji.');
            }
            $seenVersions[$version] = true;

            if ($publishedAt !== '' && !ReleaseInfo::isValidIsoDatetime($publishedAt)) {
                return $this->errorResult('invalid_manifest_release_published_at', 'Jedno z wydań ma niepoprawne published_at.');
            }
            if ($notesUrl !== '' && !ReleaseInfo::isValidHttpsUrl($notesUrl)) {
                return $this->errorResult('invalid_manifest_notes_url', 'Jedno z wydań ma niepoprawny notes_url.');
            }
            if ($downloadUrl === '' || !ReleaseInfo::isValidHttpsUrl($downloadUrl)) {
                return $this->errorResult('invalid_manifest_download_url', 'Jedno z wydań ma niepoprawny download_url.');
            }
            if (!is_array($changelog)) {
                return $this->errorResult('invalid_manifest_changelog', 'Pole changelog musi być listą krótkich wpisów.');
            }

            $normalizedChangelog = [];
            foreach ($changelog as $line) {
                $line = trim((string)$line);
                if ($line !== '') {
                    $normalizedChangelog[] = $line;
                }
            }

            $filenames = [];
            if (is_array($migrationHints) && isset($migrationHints['filenames']) && is_array($migrationHints['filenames'])) {
                foreach ($migrationHints['filenames'] as $filename) {
                    $filename = trim((string)$filename);
                    if ($filename !== '') {
                        $filenames[] = $filename;
                    }
                }
            }

            $normalizedReleases[] = [
                'version' => $version,
                'published_at' => $publishedAt,
                'notes_url' => $notesUrl,
                'download_url' => $downloadUrl,
                'changelog' => $normalizedChangelog,
                'migration_hints' => [
                    'has_migrations' => !empty($migrationHints['has_migrations']),
                    'filenames' => $filenames,
                ],
            ];
        }

        usort(
            $normalizedReleases,
            static fn (array $left, array $right): int => ReleaseInfo::compareVersions($right['version'], $left['version'])
        );

        $latestRelease = null;
        foreach ($normalizedReleases as $entry) {
            if ($entry['version'] === $latestVersion) {
                $latestRelease = $entry;
                break;
            }
        }

        if ($latestRelease === null) {
            return $this->errorResult('invalid_manifest_latest_release_missing', 'latest_version nie ma odpowiadającego wpisu na liście wydań.');
        }

        return [
            'ok' => true,
            'status' => 'success',
            'manifest' => [
                'schema_version' => $schemaVersion,
                'product' => $product,
                'channel' => $channel,
                'generated_at' => $generatedAt,
                'latest_version' => $latestVersion,
                'latest_release' => $latestRelease,
                'releases' => $normalizedReleases,
            ],
        ];
    }

    /**
     * @param array<string,mixed> $manifest
     * @param array<string,string> $expected
     * @return array<string,mixed>
     */
    private function validateSimpleManifest(array $manifest, array $expected): array
    {
        $schemaVersion = isset($manifest['schema_version']) ? (int)$manifest['schema_version'] : ReleaseInfo::SCHEMA_VERSION;
        $expectedProduct = (string)($expected['product'] ?? 'crm');
        $expectedChannel = (string)($expected['channel'] ?? ReleaseInfo::DEFAULT_CHANNEL);

        $product = trim((string)($manifest['product'] ?? $expectedProduct));
        $channel = trim((string)($manifest['channel'] ?? $expectedChannel));
        $version = trim((string)($manifest['version'] ?? ''));
        $downloadUrl = trim((string)($manifest['download_url'] ?? ''));
        $publishedAt = trim((string)($manifest['published_at'] ?? ''));
        $generatedAt = trim((string)($manifest['generated_at'] ?? $publishedAt));
        $notesUrl = trim((string)($manifest['notes_url'] ?? ''));
        $changelog = $manifest['changelog'] ?? [];
        $migrationHints = $manifest['migration_hints'] ?? [];

        if ($schemaVersion !== ReleaseInfo::SCHEMA_VERSION) {
            return $this->errorResult('invalid_manifest_schema', 'Manifest ma nieobsługiwaną wersję schematu.');
        }
        if ($product !== $expectedProduct) {
            return $this->errorResult('invalid_manifest_product', 'Manifest dotyczy innego produktu niż CRM.');
        }
        if ($channel !== $expectedChannel) {
            return $this->errorResult('invalid_manifest_channel', 'Manifest nie zgadza się z lokalnym kanałem wydań.');
        }
        if (!ReleaseInfo::isValidCalver($version)) {
            return $this->errorResult('invalid_manifest_release_version', 'Manifest ma niepoprawny numer wersji.');
        }
        if ($downloadUrl === '' || !ReleaseInfo::isValidHttpsUrl($downloadUrl)) {
            return $this->errorResult('invalid_manifest_download_url', 'Manifest ma niepoprawny download_url.');
        }
        if ($generatedAt !== '' && !ReleaseInfo::isValidIsoDatetime($generatedAt)) {
            return $this->errorResult('invalid_manifest_generated_at', 'Manifest ma niepoprawne generated_at.');
        }
        if ($publishedAt !== '' && !ReleaseInfo::isValidIsoDatetime($publishedAt)) {
            return $this->errorResult('invalid_manifest_release_published_at', 'Manifest ma niepoprawne published_at.');
        }
        if ($notesUrl !== '' && !ReleaseInfo::isValidHttpsUrl($notesUrl)) {
            return $this->errorResult('invalid_manifest_notes_url', 'Manifest ma niepoprawny notes_url.');
        }

        if (is_string($changelog)) {
            $changelog = preg_split('/\r\n|\r|\n/', $changelog) ?: [];
        }
        if (!is_array($changelog)) {
            return $this->errorResult('invalid_manifest_changelog', 'Pole changelog musi być listą krótkich wpisów.');
        }

        $normalizedChangelog = [];
        foreach ($changelog as $line) {
            $line = trim((string)$line);
            if ($line !== '') {
                $normalizedChangelog[] = $line;
            }
        }

        $filenames = [];
        if (is_array($migrationHints) && isset($migrationHints['filenames']) && is_array($migrationHints['filenames'])) {
            foreach ($migrationHints['filenames'] as $filename) {
                $filename = trim((string)$filename);
                if ($filename !== '') {
                    $filenames[] = $filename;
                }
            }
        }

        $release = [
            'version' => $version,
            'published_at' => $publishedAt,
            'notes_url' => $notesUrl,
            'download_url' => $downloadUrl,
            'changelog' => $normalizedChangelog,
            'migration_hints' => [
                'has_migrations' => !empty($migrationHints['has_migrations']),
                'filenames' => $filenames,
            ],
        ];

        return [
            'ok' => true,
            'status' => 'success',
            'manifest' => [
                'schema_version' => $schemaVersion,
                'product' => $product,
                'channel' => $channel,
                'generated_at' => $generatedAt,
                'latest_version' => $version,
                'latest_release' => $release,
                'releases' => [$release],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function download(string $url): array
    {
        if (is_callable($this->transport)) {
            $response = call_user_func($this->transport, $url, [
                'connect_timeout' => $this->connectTimeoutSeconds,
                'timeout' => $this->timeoutSeconds,
                'max_bytes' => $this->maxBytes,
            ]);
            if (!is_array($response)) {
                return ['ok' => false, 'status' => 'transport_error', 'error' => 'Transport testowy zwrócił niepoprawny wynik.'];
            }
            return $response;
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 'curl_unavailable', 'error' => 'Brakuje rozszerzenia cURL.'];
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 'curl_init_failed', 'error' => 'Nie udało się zainicjalizować połączenia cURL.'];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $body = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($body)) {
            return ['ok' => false, 'status' => 'network_error', 'error' => $error !== '' ? $error : 'Nie udało się pobrać manifestu.'];
        }
        if (strlen($body) > $this->maxBytes) {
            return ['ok' => false, 'status' => 'manifest_too_large', 'error' => 'Manifest przekracza bezpieczny limit rozmiaru.'];
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            return ['ok' => false, 'status' => 'http_error', 'error' => 'Serwer manifestu zwrócił HTTP ' . $statusCode . '.'];
        }

        return ['ok' => true, 'status' => 'success', 'body' => $body];
    }

    /**
     * @return array<string,mixed>
     */
    private function errorResult(string $status, string $message): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'error' => $message,
        ];
    }
}
