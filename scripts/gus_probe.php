#!/usr/bin/env php
<?php
declare(strict_types=1);

function stderr(string $message): void
{
    fwrite(STDERR, $message . PHP_EOL);
}

$baseUrl = rtrim((string)(getenv('GUS_PROBE_BASE') ?: 'http://localhost:8080'), '/');
$params = [
    'debug' => '1',
];

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--nip=')) {
        $params['nip'] = preg_replace('/\D+/', '', substr($arg, 6)) ?? '';
        continue;
    }
    if (str_starts_with($arg, '--regon=')) {
        $params['regon'] = preg_replace('/\D+/', '', substr($arg, 8)) ?? '';
        continue;
    }
    if (str_starts_with($arg, '--krs=')) {
        $params['krs'] = preg_replace('/\D+/', '', substr($arg, 6)) ?? '';
        continue;
    }
    if ($arg === '--selftest') {
        $params['selftest'] = '1';
        continue;
    }
}

if (
    empty($params['selftest'])
    && empty($params['nip'])
    && empty($params['regon'])
    && empty($params['krs'])
) {
    stderr('Usage: php scripts/gus_probe.php --nip=1234567890 [--regon=...] [--krs=...] [--selftest]');
    exit(1);
}

$url = $baseUrl . '/gus_lookup.php?' . http_build_query($params);
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'timeout' => 30,
        'ignore_errors' => true,
        'header' => "Accept: application/json\r\n",
    ],
]);

$body = @file_get_contents($url, false, $context);
$statusLine = '';
if (isset($http_response_header[0])) {
    $statusLine = (string)$http_response_header[0];
}

echo 'URL: ' . $url . PHP_EOL;
echo 'STATUS: ' . ($statusLine !== '' ? $statusLine : 'n/a') . PHP_EOL;
echo "RAW_RESPONSE_BEGIN\n";
echo $body !== false ? $body : '';
echo "\nRAW_RESPONSE_END\n";

exit($body === false ? 2 : 0);
