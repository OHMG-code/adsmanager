<?php
declare(strict_types=1);

function gusEnvValue(string $key): string
{
    $value = getenv($key);
    if ($value === false) {
        $value = $_ENV[$key] ?? '';
    }
    $value = trim((string)$value);
    $len = strlen($value);
    if ($len >= 2) {
        $first = $value[0];
        $last = $value[$len - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $value = substr($value, 1, -1);
        }
    }
    return trim($value);
}

function gusEnvInt(string $key, int $default): int
{
    $value = gusEnvValue($key);
    if ($value === '' || !is_numeric($value)) {
        return $default;
    }
    return (int)$value;
}

function gusNormalizeEnvironment(string $raw): string
{
    $value = strtolower(trim($raw));
    return in_array($value, ['test', 'prod'], true) ? $value : 'prod';
}

function gusDefaultWsdl(string $environment): string
{
    return $environment === 'test'
        ? 'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/wsdl/UslugaBIRzewnPubl-ver11-test.wsdl'
        : 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/wsdl/UslugaBIRzewnPubl-ver11-prod.wsdl';
}

function gusDefaultEndpoint(string $environment): string
{
    return $environment === 'test'
        ? 'https://wyszukiwarkaregontest.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc'
        : 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';
}

function gusReadDbConfigFallback(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $cached = [];
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        return $cached;
    }

    try {
        $stmt = $pdo->query('SELECT gus_environment, gus_api_key FROM konfiguracja_systemu WHERE id = 1 LIMIT 1');
        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        if ($row) {
            $cached = [
                'environment' => trim((string)($row['gus_environment'] ?? '')),
                'api_key' => trim((string)($row['gus_api_key'] ?? '')),
            ];
        }
    } catch (Throwable $e) {
        $cached = [];
    }

    return $cached;
}

function gusResolveRuntimeConfig(): array
{
    $dbFallback = gusReadDbConfigFallback();

    $envRaw = gusEnvValue('GUS_ENV');
    if ($envRaw === '') {
        $envRaw = gusEnvValue('GUS_ENVIRONMENT');
    }
    if ($envRaw === '' && !empty($dbFallback['environment'])) {
        $envRaw = (string)$dbFallback['environment'];
    }
    $environment = gusNormalizeEnvironment($envRaw);

    $keyName = $environment === 'test' ? 'GUS_KEY_TEST' : 'GUS_KEY_PROD';
    $wsdlName = $environment === 'test' ? 'GUS_WSDL_TEST' : 'GUS_WSDL_PROD';
    $endpointName = $environment === 'test' ? 'GUS_ENDPOINT_TEST' : 'GUS_ENDPOINT_PROD';

    $apiKey = gusEnvValue($keyName);
    if ($apiKey === '') {
        $apiKey = gusEnvValue('GUS_API_KEY');
    }
    if ($apiKey === '' && !empty($dbFallback['api_key'])) {
        $apiKey = (string)$dbFallback['api_key'];
    }

    $wsdl = gusEnvValue($wsdlName);
    if ($wsdl === '') {
        $wsdl = gusEnvValue('GUS_WSDL');
    }
    if ($wsdl === '') {
        $wsdl = gusDefaultWsdl($environment);
    }

    $endpoint = gusEnvValue($endpointName);
    if ($endpoint === '') {
        $endpoint = gusEnvValue('GUS_ENDPOINT');
    }
    if ($endpoint === '') {
        $endpoint = gusDefaultEndpoint($environment);
    }

    $missing = [];
    if ($apiKey === '') {
        $missing[] = $keyName;
    }
    if ($wsdl === '') {
        $missing[] = $wsdlName;
    }
    if ($endpoint === '') {
        $missing[] = $endpointName;
    }

    return [
        'environment' => $environment,
        'api_key' => $apiKey,
        'wsdl' => $wsdl,
        'endpoint' => $endpoint,
        'missing' => $missing,
        'ok' => $missing === [],
    ];
}

function gusResolveLogPath(): string
{
    $storageDir = dirname(__DIR__, 2) . '/storage/logs';
    if (is_dir($storageDir) && is_writable($storageDir)) {
        return $storageDir . '/gus_health.log';
    }

    $tmpDir = sys_get_temp_dir();
    if ($tmpDir !== '' && is_writable($tmpDir)) {
        return rtrim($tmpDir, '/\\') . '/gus_health.log';
    }

    return '/tmp/gus_health.log';
}

function gusLogInfo(string $message, array $context = []): void
{
    $payload = [
        'ts' => date('c'),
        'level' => 'INFO',
        'message' => $message,
        'context' => $context,
    ];

    $line = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return;
    }

    $path = gusResolveLogPath();
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    if (is_dir($dir) && is_writable($dir)) {
        @file_put_contents($path, $line . PHP_EOL, FILE_APPEND);
        return;
    }

    error_log('gus_health: ' . $line);
}

function gusLogSelftest(array $config, array $selftest): void
{
    $context = [
        'env' => (string)($config['environment'] ?? ''),
        'wsdl' => (string)($config['wsdl'] ?? ''),
        'endpoint' => (string)($config['endpoint'] ?? ''),
        'selftest_ok' => !empty($selftest['ok']),
    ];
    if (!empty($selftest['error'])) {
        $context['error'] = (string)$selftest['error'];
    }

    gusLogInfo('gus selftest', $context);
}
