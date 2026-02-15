<?php
declare(strict_types=1);

function gusEnvValue(string $key): string
{
    $value = getenv($key);
    if ($value === false) {
        $value = $_ENV[$key] ?? '';
    }
    return trim((string)$value);
}

function gusEnvInt(string $key, int $default): int
{
    $value = gusEnvValue($key);
    if ($value === '' || !is_numeric($value)) {
        return $default;
    }
    return (int)$value;
}

function gusResolveRuntimeConfig(): array
{
    $envRaw = strtolower(gusEnvValue('GUS_ENV'));
    $environment = in_array($envRaw, ['test', 'prod'], true) ? $envRaw : 'prod';

    $keyName = $environment === 'test' ? 'GUS_KEY_TEST' : 'GUS_KEY_PROD';
    $wsdlName = $environment === 'test' ? 'GUS_WSDL_TEST' : 'GUS_WSDL_PROD';
    $endpointName = $environment === 'test' ? 'GUS_ENDPOINT_TEST' : 'GUS_ENDPOINT_PROD';

    $apiKey = gusEnvValue($keyName);
    $wsdl = gusEnvValue($wsdlName);
    $endpoint = gusEnvValue($endpointName);

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
