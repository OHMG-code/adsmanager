<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
ob_start();

$GLOBALS['gus_api_request_id'] = uniqid('gus_api_', true);
$GLOBALS['gus_api_response_sent'] = false;

register_shutdown_function(static function (): void {
    if (!empty($GLOBALS['gus_api_response_sent'])) {
        return;
    }

    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $isFatal = is_array($error) && in_array((int)($error['type'] ?? 0), $fatalTypes, true);

    $rawOutput = '';
    while (ob_get_level() > 0) {
        $chunk = (string)ob_get_contents();
        ob_end_clean();
        $rawOutput = $chunk . $rawOutput;
    }

    $rawSnippet = gusApiTrimSnippet($rawOutput);
    if ($isFatal && isset($error['message'])) {
        $rawSnippet = gusApiTrimSnippet(trim($rawSnippet . ' ' . (string)$error['message']));
    }

    if (!$isFatal && $rawSnippet === '') {
        return;
    }

    $requestId = (string)($GLOBALS['gus_api_request_id'] ?? uniqid('gus_api_', true));
    $code = $isFatal ? 'GUS_FATAL' : 'NON_JSON_RESPONSE';
    $message = $isFatal ? 'Błąd krytyczny backendu GUS.' : 'Nieprawidłowa odpowiedź backendu GUS.';
    gusApiLogFailure($requestId, $code, $message, $rawSnippet);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode([
        'ok' => false,
        'success' => false,
        'message' => $message,
        'data' => (object)[],
        'error' => [
            'code' => $code,
            'message' => $message,
        ],
        'rawSnippet' => $rawSnippet !== '' ? $rawSnippet : null,
        'requestId' => $requestId,
    ], JSON_UNESCAPED_UNICODE);

    $GLOBALS['gus_api_response_sent'] = true;
});

require_once __DIR__ . '/includes/gus_config.php';
require_once __DIR__ . '/includes/gus_snapshots.php';
require_once __DIR__ . '/includes/gus_validation.php';

$gusRuntimeConfig = [
    'enabled' => true,
    'api_key' => '',
    'environment' => 'prod',
    'wsdl' => '',
    'endpoint' => '',
    'error' => '',
    'source' => 'env',
];

$gusConfigPath = __DIR__ . '/../config/config.php';
if (is_file($gusConfigPath)) {
    try {
        require_once $gusConfigPath;
        if (isset($pdo) && $pdo instanceof PDO) {
            require_once __DIR__ . '/includes/db_schema.php';
            ensureSystemConfigColumns($pdo);
            $row = $pdo->query("SELECT gus_enabled FROM konfiguracja_systemu WHERE id = 1")
                ->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($row) {
                $gusRuntimeConfig['enabled'] = !empty($row['gus_enabled']);
                $gusRuntimeConfig['source'] = $gusRuntimeConfig['source'] ?: 'db';
            }
        }
    } catch (Throwable $e) {
        $gusRuntimeConfig['error'] = 'Blad odczytu ustawien GUS.';
        error_log('gus_lookup: config load failed: ' . $e->getMessage());
    }
}

$runtime = gusResolveRuntimeConfig();
if (!empty($runtime['environment'])) {
    $gusRuntimeConfig['environment'] = $runtime['environment'];
    $gusRuntimeConfig['api_key'] = $runtime['api_key'];
    $gusRuntimeConfig['wsdl'] = $runtime['wsdl'];
    $gusRuntimeConfig['endpoint'] = $runtime['endpoint'];
    $gusRuntimeConfig['source'] = 'env';
    if (empty($runtime['ok'])) {
        $missing = implode(', ', (array)($runtime['missing'] ?? []));
        $gusRuntimeConfig['error'] = 'Brak konfiguracji GUS w .env: ' . $missing . '.';
    }
}

$gusEnv = $gusRuntimeConfig['environment'];
$gusWsdl = $gusRuntimeConfig['wsdl'];
$gusEndpoint = $gusRuntimeConfig['endpoint'];

define('GUS_API_KEY', $gusRuntimeConfig['api_key']);
define('GUS_WSDL', $gusWsdl);
define('GUS_ENDPOINT', $gusEndpoint);
const GUS_SID_NAMESPACE = 'http://CIS/BIR/PUBL/2014/07';
const GUS_WSA_NAMESPACE_2005 = 'http://www.w3.org/2005/08/addressing';
const GUS_WSA_NAMESPACE_2004 = 'http://schemas.xmlsoap.org/ws/2004/08/addressing';
const GUS_WSA_ANONYMOUS = 'http://www.w3.org/2005/08/addressing/anonymous';
const GUS_DEBUG_LIMIT = 60000;
const GUS_LOG_LIMIT = 2000;
const GUS_LOG_MAX_BYTES = 2097152;
const GUS_API_FAILURE_SNIPPET_LIMIT = 2000;

function gusApiFailureLogPath(): string
{
    $storageDir = dirname(__DIR__) . '/storage/logs';
    if (is_dir($storageDir) || @mkdir($storageDir, 0775, true)) {
        if (is_writable($storageDir)) {
            return $storageDir . '/gus_api_failures.log';
        }
    }

    $tmpDir = sys_get_temp_dir();
    if ($tmpDir !== '' && is_writable($tmpDir)) {
        return rtrim($tmpDir, '/\\') . '/gus_api_failures.log';
    }

    return '/tmp/gus_api_failures.log';
}

function gusApiTrimSnippet(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    if (strlen($value) > GUS_API_FAILURE_SNIPPET_LIMIT) {
        return substr($value, 0, GUS_API_FAILURE_SNIPPET_LIMIT);
    }

    return $value;
}

function gusApiLogFailure(string $requestId, string $code, string $message, string $rawSnippet = ''): void
{
    $entry = [
        'ts' => date('c'),
        'requestId' => $requestId,
        'code' => $code,
        'message' => $message,
        'rawSnippet' => $rawSnippet !== '' ? $rawSnippet : null,
    ];

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return;
    }

    @file_put_contents(gusApiFailureLogPath(), $line . PHP_EOL, FILE_APPEND);
}

function respond(
    bool $ok,
    string $message,
    ?array $data = null,
    bool $debug = false,
    array $debugPayload = [],
    string $rawXml = '',
    string $reportName = '',
    int $statusCode = 0,
    string $errorCode = 'GUS_ERROR',
    string $rawSnippet = ''
): void {
    $bufferedOutput = '';
    while (ob_get_level() > 0) {
        $chunk = (string)ob_get_contents();
        ob_end_clean();
        $bufferedOutput = $chunk . $bufferedOutput;
    }

    if (!$ok && $rawSnippet === '') {
        $rawSnippet = gusApiTrimSnippet($bufferedOutput);
    } else {
        $rawSnippet = gusApiTrimSnippet($rawSnippet);
    }

    $requestId = (string)($GLOBALS['gus_api_request_id'] ?? uniqid('gus_api_', true));
    $payload = [
        'ok' => $ok,
        'success' => $ok,
        'message' => $message,
        'data' => $data ?? (object)[],
        'requestId' => $requestId,
    ];

    if (!$ok) {
        $payload['error'] = [
            'code' => $errorCode,
            'message' => $message,
        ];
        $payload['rawSnippet'] = $rawSnippet !== '' ? $rawSnippet : null;
    }

    if ($debug) {
        if ($rawXml !== '') {
            $payload['raw_xml'] = substr($rawXml, 0, GUS_DEBUG_LIMIT);
        }
        if ($reportName !== '') {
            $payload['report'] = $reportName;
        }
        foreach ($debugPayload as $key => $value) {
            $payload[$key] = $value;
        }
    }

    if ($statusCode <= 0) {
        $statusCode = $ok ? 200 : 500;
    }

    if (!$ok) {
        gusApiLogFailure($requestId, $errorCode, $message, $rawSnippet);
    }

    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }

    $GLOBALS['gus_api_response_sent'] = true;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function saveGusSnapshot(array $data): void
{
    if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
        return;
    }

    try {
        $repo = new GusSnapshotRepository($GLOBALS['pdo']);
        $repo->save($data);
    } catch (Throwable $e) {
        error_log('gus_lookup: snapshot save failed: ' . $e->getMessage());
    }
}

function saveValidationSnapshot(string $env, string $requestType, string $requestValue, string $traceId): void
{
    saveGusSnapshot([
        'env' => $env,
        'request_type' => $requestType,
        'request_value' => $requestValue,
        'report_type' => null,
        'http_code' => null,
        'ok' => false,
        'error_code' => 'validation',
        'error_message' => 'validation_failed',
        'error_class' => 'VALIDATION',
        'attempt_no' => 0,
        'latency_ms' => 0,
        'fault_code' => null,
        'fault_string' => null,
        'raw_request' => null,
        'raw_response' => null,
        'raw_parsed' => null,
        'correlation_id' => $traceId,
    ]);
}

function resolveCacheTtlDays(PDO $pdo): int
{
    try {
        ensureSystemConfigColumns($pdo);
        $stmt = $pdo->query("SELECT gus_cache_ttl_days FROM konfiguracja_systemu WHERE id = 1");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : [];
        $ttl = (int)($row['gus_cache_ttl_days'] ?? 30);
        return max(1, $ttl);
    } catch (Throwable $e) {
        return 30;
    }
}

function fetchCachedSnapshot(PDO $pdo, array $identifiers, int $ttlDays): ?array
{
    if ($ttlDays <= 0) {
        return null;
    }
    $ids = array_values(array_filter(array_unique(array_map('strval', $identifiers)), static function ($value) {
        return trim($value) !== '';
    }));
    if (!$ids) {
        return null;
    }

    try {
        ensureGusSnapshotsTable($pdo);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT raw_parsed, created_at FROM gus_snapshots
            WHERE ok = 1 AND raw_parsed IS NOT NULL AND request_value IN ($placeholders)
            ORDER BY created_at DESC LIMIT 1");
        $stmt->execute($ids);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return null;
    }

    if (!$row || empty($row['raw_parsed'])) {
        return null;
    }

    if (!empty($row['created_at'])) {
        try {
            $fetchedAt = new DateTime($row['created_at']);
            $expiry = (clone $fetchedAt)->modify('+' . max(1, $ttlDays) . ' days');
            if ($expiry < new DateTime()) {
                return null;
            }
        } catch (Throwable $e) {
            // ignore timestamp parse errors
        }
    }

    $data = json_decode($row['raw_parsed'], true);
    return is_array($data) ? $data : null;
}

function resolveLogPath(): string
{
    $storageDir = dirname(__DIR__) . '/storage/logs';
    if (is_dir($storageDir) && is_writable($storageDir)) {
        return $storageDir . '/gus_soap.log';
    }

    $tmpDir = sys_get_temp_dir();
    if ($tmpDir !== '' && is_writable($tmpDir)) {
        return rtrim($tmpDir, '/\\') . '/gus_soap.log';
    }

    return '/tmp/gus_soap.log';
}

function setLogError(string $message): void
{
    $GLOBALS['gus_log_error'] = $message;
}

function getLogError(): string
{
    return isset($GLOBALS['gus_log_error']) ? (string)$GLOBALS['gus_log_error'] : '';
}

function getLogStatus(string $path): array
{
    $dir = dirname($path);

    return [
        'log_path' => $path,
        'log_dir' => $dir,
        'log_dir_exists' => is_dir($dir),
        'log_dir_writable' => is_writable($dir),
        'log_file_exists' => file_exists($path),
        'log_file_writable' => file_exists($path) ? is_writable($path) : null,
        'open_basedir' => (string)ini_get('open_basedir'),
    ];
}

function rotateLog(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    $size = filesize($path);
    if ($size === false || $size <= GUS_LOG_MAX_BYTES) {
        return;
    }

    $backup = $path . '.1';
    if (file_exists($backup)) {
        @unlink($backup);
    }
    @rename($path, $backup);
}

function logLine(string $path, string $line): bool
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    if (!is_dir($dir) || !is_writable($dir)) {
        $msg = 'gus_soap.log dir not writable: ' . $dir;
        setLogError($msg);
        error_log($msg);
        return false;
    }

    rotateLog($path);
    $bytes = @file_put_contents($path, $line, FILE_APPEND);
    if ($bytes === false) {
        $error = error_get_last();
        $msg = $error && isset($error['message']) ? $error['message'] : 'unknown log write error';
        $msg = 'gus_soap.log write failed: ' . $msg . ' path=' . $path;
        setLogError($msg);
        error_log($msg);
        return false;
    }

    setLogError('');
    return true;
}

function logPayload(string $path, string $label, array $payload, string $sid = ''): void
{
    $entry = [
        'ts' => date('c'),
        'label' => $label,
        'payload' => sanitizeDebugPayload($payload, $sid, GUS_LOG_LIMIT),
    ];

    $line = json_encode($entry, JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return;
    }
    logLine($path, $line . PHP_EOL);
}

function maskToken(string $value): string
{
    $value = trim($value);
    $len = strlen($value);
    if ($len <= 8) {
        return '****';
    }
    return substr($value, 0, 4) . '****' . substr($value, -4);
}

function maskIdentifierValue(string $value): string
{
    $digits = preg_replace('/\\D+/', '', $value) ?? '';
    $len = strlen($digits);
    if ($len <= 4) {
        return $digits !== '' ? str_repeat('*', $len) : '';
    }
    return str_repeat('*', $len - 4) . substr($digits, -4);
}

function maskIdentifiersInText(string $text): string
{
    $patterns = [
        '/(\\bNip\\b[^0-9]*)([0-9]{10})/i',
        '/(\\bnip=)([0-9]{10})/i',
        '/(\\bRegon\\b[^0-9]*)([0-9]{9}|[0-9]{14})/i',
        '/(\\bregon=)([0-9]{9}|[0-9]{14})/i',
        '/(\\bKrs\\b[^0-9]*)([0-9]{10})/i',
        '/(\\bkrs=)([0-9]{10})/i',
    ];

    foreach ($patterns as $pattern) {
        $text = preg_replace_callback($pattern, static function (array $matches): string {
            return $matches[1] . maskIdentifierValue($matches[2]);
        }, $text) ?? $text;
    }

    return $text;
}

function maskSensitiveText(string $text, string $sid = ''): string
{
    $text = (string)$text;
    $replacements = [];

    if (GUS_API_KEY !== '') {
        $replacements[GUS_API_KEY] = maskToken(GUS_API_KEY);
    }
    if ($sid !== '') {
        $replacements[$sid] = maskToken($sid);
    }

    if (!$replacements) {
        return maskIdentifiersInText($text);
    }

    $masked = str_replace(array_keys($replacements), array_values($replacements), $text);
    return maskIdentifiersInText($masked);
}

function compactDebugValue(string $value, string $sid, int $limit): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $value = maskSensitiveText($value, $sid);
    if ($limit > 0 && strlen($value) > $limit) {
        return substr($value, 0, $limit);
    }

    return $value;
}

function extractMtomSoapXml(string $headers, string $body): string
{
    if ($headers === '' || $body === '') {
        return '';
    }

    if (stripos($headers, 'multipart/related') === false) {
        return '';
    }

    if (!preg_match('/boundary=\"?([^\";]+)\"?/i', $headers, $matches)) {
        return '';
    }

    $boundary = $matches[1];
    if ($boundary === '') {
        return '';
    }

    $parts = explode('--' . $boundary, $body);
    foreach ($parts as $part) {
        if (stripos($part, 'Content-Type: application/xop+xml') === false
            && stripos($part, 'Content-Type: application/soap+xml') === false
        ) {
            continue;
        }

        $segments = preg_split("/\r?\n\r?\n/", $part, 2);
        if ($segments && isset($segments[1])) {
            return trim($segments[1]);
        }
    }

    return '';
}

function extractSoapValueFromXml(string $soapXml, string $resultElement): string
{
    if ($soapXml === '' || $resultElement === '') {
        return '';
    }

    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($soapXml);
    if (!$doc) {
        $pattern = '/<[^>]*' . preg_quote($resultElement, '/') . '\b[^>]*>([^<]*)<\/[^>]*' . preg_quote($resultElement, '/') . '\b[^>]*>/i';
        if (preg_match($pattern, $soapXml, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
        }
        return '';
    }

    $nodes = $doc->xpath('//*[local-name()="' . $resultElement . '"]');
    if ($nodes && isset($nodes[0])) {
        return trim((string)$nodes[0]);
    }

    return '';
}

function soapResponseHasFault(string $soapXml): bool
{
    if ($soapXml === '') {
        return false;
    }
    return stripos($soapXml, ':Fault') !== false || stripos($soapXml, '<Fault') !== false;
}

function soapXmlHasElement(string $soapXml, string $elementName): bool
{
    if ($soapXml === '' || $elementName === '') {
        return false;
    }

    $pattern = '/(<[^>]*\b' . preg_quote($elementName, '/') . '\b[^>]*\/>)'
        . '|(<[^>]*\b' . preg_quote($elementName, '/') . '\b[^>]*>.*?<\/[^>]*'
        . preg_quote($elementName, '/') . '\b[^>]*>)/is';

    return preg_match($pattern, $soapXml) === 1;
}

function buildMtomResult(SoapClient $client, string $method, bool $allowMissingElement = false): ?stdClass
{
    $headers = method_exists($client, '__getLastResponseHeaders') ? (string)$client->__getLastResponseHeaders() : '';
    $body = method_exists($client, '__getLastResponse') ? (string)$client->__getLastResponse() : '';
    $soapXml = extractMtomSoapXml($headers, $body);
    if ($soapXml === '' || soapResponseHasFault($soapXml)) {
        return null;
    }

    $resultElement = getResultElementName($method);
    $hasElement = soapXmlHasElement($soapXml, $resultElement);
    $value = extractSoapValueFromXml($soapXml, $resultElement);
    if ($value === '' && !$hasElement && !$allowMissingElement) {
        return null;
    }

    $result = new stdClass();
    $result->{$resultElement} = $value;
    return $result;
}

function shouldForceMtomResult(string $message): bool
{
    $lower = strtolower($message);
    return strpos($lower, 'no xml document') !== false;
}

function getResultElementName(string $method): string
{
    if ($method === '') {
        return '';
    }
    return $method . 'Result';
}

function sanitizeDebugPayload(array $payload, string $sid, int $limit): array
{
    $result = [];
    foreach ($payload as $key => $value) {
        if (is_string($value)) {
            $value = compactDebugValue($value, $sid, $limit);
            if ($value === '') {
                continue;
            }
            $result[$key] = $value;
            continue;
        }
        if (is_array($value)) {
            $value = sanitizeDebugPayload($value, $sid, $limit);
            if ($value === []) {
                continue;
            }
            $result[$key] = $value;
            continue;
        }
        if ($value === null) {
            continue;
        }
        $result[$key] = $value;
    }

    return $result;
}

function decodeGusXml(string $xml): string
{
    $decoded = html_entity_decode($xml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    return trim($decoded);
}

function parseGusXml(string $xml): array
{
    $decoded = decodeGusXml($xml);
    if ($decoded === '') {
        return [];
    }

    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($decoded);
    if (!$doc) {
        return [];
    }

    $nodes = $doc->xpath('//dane');
    $node = ($nodes && isset($nodes[0])) ? $nodes[0] : $doc;

    $data = [];
    foreach ($node->children() as $child) {
        $data[$child->getName()] = trim((string)$child);
    }

    return $data;
}

function pickValue(array $data, array $keys): string
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $data)) {
            $value = trim((string)$data[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }
    return '';
}

function pickBySuffix(array $data, string $suffix): string
{
    $suffixLen = strlen($suffix);
    foreach ($data as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        if (strlen($key) >= $suffixLen && substr($key, -$suffixLen) === $suffix) {
            return trim((string)$value);
        }
    }
    return '';
}

function pickByPrefix(array $data, string $prefix): string
{
    $prefixLen = strlen($prefix);
    foreach ($data as $key => $value) {
        if ($value === null || $value === '') {
            continue;
        }
        if (strlen($key) >= $prefixLen && strncmp($key, $prefix, $prefixLen) === 0) {
            if (strpos($key, 'Symbol') !== false) {
                continue;
            }
            return trim((string)$value);
        }
    }
    return '';
}

function normalizeGusData(array $raw, string $nipFallback, string $regonFallback): array
{
    $result = [
        'nazwa' => pickValue($raw, ['Nazwa', 'NazwaPelna', 'NazwaSkrocona']),
        'nip' => pickValue($raw, ['Nip', 'NIP']),
        'regon' => pickValue($raw, ['Regon']),
        'krs' => pickValue($raw, ['Krs', 'NumerKrs', 'KrsNumer', 'Krs_numer', 'KRS']),
        'wojewodztwo' => pickValue($raw, [
            'Wojewodztwo',
            'AdresSiedzibyWojewodztwo',
            'AdresDzialalnosciWojewodztwo'
        ]),
        'powiat' => pickValue($raw, [
            'Powiat',
            'AdresSiedzibyPowiat',
            'AdresDzialalnosciPowiat'
        ]),
        'gmina' => pickValue($raw, [
            'Gmina',
            'AdresSiedzibyGmina',
            'AdresDzialalnosciGmina'
        ]),
        'miejscowosc' => pickValue($raw, [
            'Miejscowosc',
            'AdresSiedzibyMiejscowosc',
            'AdresDzialalnosciMiejscowosc'
        ]),
        'ulica' => pickValue($raw, [
            'Ulica',
            'AdresSiedzibyUlica',
            'AdresDzialalnosciUlica'
        ]),
        'nr_nieruchomosci' => pickValue($raw, [
            'NrNieruchomosci',
            'AdresSiedzibyNrNieruchomosci',
            'AdresDzialalnosciNrNieruchomosci'
        ]),
        'nr_lokalu' => pickValue($raw, [
            'NrLokalu',
            'AdresSiedzibyNrLokalu',
            'AdresDzialalnosciNrLokalu'
        ]),
        'kod_pocztowy' => pickValue($raw, [
            'KodPocztowy',
            'AdresSiedzibyKodPocztowy',
            'AdresDzialalnosciKodPocztowy'
        ]),
        'poczta' => pickValue($raw, [
            'Poczta',
            'AdresSiedzibyPoczta',
            'AdresDzialalnosciPoczta'
        ]),
        'pkd_glowne' => pickValue($raw, ['PkdGlowny', 'PkdGlownyKod', 'Pkd', 'Pkd2007']),
    ];

    if ($result['nip'] === '') {
        $result['nip'] = $nipFallback;
    }
    if ($result['regon'] === '') {
        $result['regon'] = $regonFallback;
    }

    if ($result['wojewodztwo'] === '') {
        $result['wojewodztwo'] = pickBySuffix($raw, 'Wojewodztwo');
    }
    if ($result['powiat'] === '') {
        $result['powiat'] = pickBySuffix($raw, 'Powiat');
    }
    if ($result['gmina'] === '') {
        $result['gmina'] = pickBySuffix($raw, 'Gmina');
    }
    if ($result['miejscowosc'] === '') {
        $result['miejscowosc'] = pickBySuffix($raw, 'Miejscowosc');
    }
    if ($result['ulica'] === '') {
        $result['ulica'] = pickBySuffix($raw, 'Ulica');
    }
    if ($result['nr_nieruchomosci'] === '') {
        $result['nr_nieruchomosci'] = pickBySuffix($raw, 'NrNieruchomosci');
    }
    if ($result['nr_lokalu'] === '') {
        $result['nr_lokalu'] = pickBySuffix($raw, 'NrLokalu');
    }
    if ($result['kod_pocztowy'] === '') {
        $result['kod_pocztowy'] = pickBySuffix($raw, 'KodPocztowy');
    }
    if ($result['poczta'] === '') {
        $result['poczta'] = pickBySuffix($raw, 'Poczta');
    }
    if ($result['pkd_glowne'] === '') {
        $result['pkd_glowne'] = pickByPrefix($raw, 'Pkd');
    }

    return $result;
}

function isSslError(string $message): bool
{
    $lower = strtolower($message);
    return strpos($lower, 'ssl') !== false && (strpos($lower, 'cert') !== false || strpos($lower, 'peer') !== false);
}

function isDnsError(string $message): bool
{
    $lower = strtolower($message);
    return strpos($lower, 'could not resolve host') !== false
        || strpos($lower, 'getaddrinfo failed') !== false
        || strpos($lower, 'name or service not known') !== false
        || strpos($lower, 'temporary failure in name resolution') !== false;
}

function isTimeoutError(string $message): bool
{
    $lower = strtolower($message);
    return strpos($lower, 'timed out') !== false || strpos($lower, 'timeout') !== false;
}

function isConnectionError(string $message): bool
{
    $lower = strtolower($message);
    return strpos($lower, 'could not connect to host') !== false
        || strpos($lower, 'failed to connect') !== false
        || strpos($lower, 'connection refused') !== false
        || strpos($lower, 'failed to open stream') !== false;
}

function isWsdlError(string $message): bool
{
    $lower = strtolower($message);
    return strpos($lower, 'wsdl') !== false
        && (strpos($lower, 'failed to load') !== false || strpos($lower, 'parsing') !== false);
}

function mapSoapFaultMessage(string $message, int $httpCode = 0): string
{
    $trimmed = trim($message);
    if ($trimmed === '') {
        return 'Blad SOAP.';
    }

    $lower = strtolower($trimmed);
    if (strpos($lower, 'multipart/related') !== false && strpos($lower, 'application/xop+xml') !== false) {
        return 'Serwer oczekuje SOAP 1.2/MTOM (content-type multipart/related). Upewnij sie, ze uzywasz SOAP 1.2. Szczegoly: ' . $trimmed;
    }

    if ($httpCode >= 500) {
        return 'HTTP 500 z GUS (Internal Server Error). Sprawdz debug: request/response. Szczegoly: ' . $trimmed;
    }
    if (isSslError($trimmed)) {
        return 'Blad SSL: weryfikacja certyfikatu nie powiodla sie. Zaktualizuj CA na hostingu. Szczegoly: ' . $trimmed;
    }
    if (isDnsError($trimmed)) {
        return 'Blad DNS: nie mozna rozwiazac hosta GUS. Szczegoly: ' . $trimmed;
    }
    if (isTimeoutError($trimmed)) {
        return 'Timeout polaczenia do GUS (sprawdz firewall/outbound HTTPS). Szczegoly: ' . $trimmed;
    }
    if (isConnectionError($trimmed)) {
        return 'Brak polaczenia z GUS (sprawdz outbound HTTPS/SSL). Szczegoly: ' . $trimmed;
    }
    if (isWsdlError($trimmed)) {
        return 'Nie mozna pobrac WSDL (sprawdz DNS/SSL/outbound HTTPS). Szczegoly: ' . $trimmed;
    }
    if (strpos($lower, 'brak nag') !== false && strpos($lower, 'sid') !== false) {
        return 'Brak naglowka SID w zapytaniu. Szczegoly: ' . $trimmed;
    }
    if (strpos($lower, 'niepoprawny sid') !== false) {
        return 'Niepoprawny SID (nieudane logowanie lub wygasla sesja). Szczegoly: ' . $trimmed;
    }

    return 'Blad SOAP: ' . $trimmed;
}

function buildSoapClientOptions(int $soapVersion, bool $allowInsecure, array $httpHeaders = []): array
{
    $connectTimeout = gusEnvInt('GUS_CONNECT_TIMEOUT', 15);
    $readTimeout = gusEnvInt('GUS_READ_TIMEOUT', 15);
    $sslOptions = [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ];

    if ($allowInsecure) {
        $sslOptions['verify_peer'] = false;
        $sslOptions['verify_peer_name'] = false;
    }

    $headers = array_merge(['User-Agent: CRM/1.0'], $httpHeaders);
    $headerText = implode("\r\n", array_filter($headers, static function ($line): bool {
        return trim((string)$line) !== '';
    })) . "\r\n";

    $contextOptions = [
        'ssl' => $sslOptions,
        'http' => [
            'header' => $headerText,
            'timeout' => $readTimeout,
        ],
    ];

    return [
        'location' => GUS_ENDPOINT,
        'soap_version' => $soapVersion,
        'trace' => 1,
        'exceptions' => true,
        'cache_wsdl' => WSDL_CACHE_NONE,
        'connection_timeout' => $connectTimeout,
        'features' => SOAP_SINGLE_ELEMENT_ARRAYS,
        'encoding' => 'UTF-8',
        'stream_context' => stream_context_create($contextOptions),
    ];
}

function createSoapClient(int $soapVersion, bool $allowInsecure, array $httpHeaders = []): SoapClient
{
    $options = buildSoapClientOptions($soapVersion, $allowInsecure, $httpHeaders);

    set_error_handler(static function ($severity, $message, $file, $line): void {
        throw new ErrorException($message, 0, $severity, $file, $line);
    });

    try {
        return new SoapClient(GUS_WSDL, $options);
    } finally {
        restore_error_handler();
    }
}

function getSoapFunctions(SoapClient $client): array
{
    try {
        $functions = $client->__getFunctions();
        return is_array($functions) ? $functions : [];
    } catch (Throwable $e) {
        return [];
    }
}

function getSoapTypes(SoapClient $client): array
{
    try {
        $types = $client->__getTypes();
        return is_array($types) ? $types : [];
    } catch (Throwable $e) {
        return [];
    }
}

function hasSoapFunction(array $functions, string $name): bool
{
    foreach ($functions as $function) {
        if (stripos($function, $name . '(') !== false || preg_match('/\b' . preg_quote($name, '/') . '\b/i', $function)) {
            return true;
        }
    }
    return false;
}

function shouldUseScalarParam(array $functions, string $method, string $paramName): bool
{
    foreach ($functions as $function) {
        if (stripos($function, $method . '(') === false) {
            continue;
        }
        $pattern = '/\b' . preg_quote($method, '/') . '\s*\(\s*string\s+\$?' . preg_quote($paramName, '/') . '\s*\)/i';
        if (preg_match($pattern, $function)) {
            return true;
        }
        $pattern = '/\b' . preg_quote($method, '/') . '\s*\(\s*' . preg_quote($paramName, '/') . '\s+\$?' . preg_quote($paramName, '/') . '\s*\)/i';
        if (preg_match($pattern, $function)) {
            return true;
        }
    }
    return false;
}

function getSoapAction(string $method): string
{
    static $map = [
        'GetValue' => 'http://CIS/BIR/2014/07/IUslugaBIR/GetValue',
        'Zaloguj' => 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Zaloguj',
        'Wyloguj' => 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/Wyloguj',
        'DaneSzukajPodmioty' => 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DaneSzukajPodmioty',
        'DanePobierzPelnyRaport' => 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzPelnyRaport',
        'DanePobierzRaportZbiorczy' => 'http://CIS/BIR/PUBL/2014/07/IUslugaBIRzewnPubl/DanePobierzRaportZbiorczy',
    ];

    return $map[$method] ?? '';
}

function generateUuid(): string
{
    if (function_exists('random_bytes')) {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    return uniqid('gus-', true);
}

function getWsaAnonymous(string $namespace): string
{
    if ($namespace === GUS_WSA_NAMESPACE_2004) {
        return 'http://schemas.xmlsoap.org/ws/2004/08/addressing/role/anonymous';
    }
    return GUS_WSA_ANONYMOUS;
}

function buildWsaReplyTo(string $namespace): SoapVar
{
    $address = htmlspecialchars(getWsaAnonymous($namespace), ENT_QUOTES, 'UTF-8');
    $xml = '<wsa:ReplyTo xmlns:wsa="' . $namespace . '"><wsa:Address>' . $address . '</wsa:Address></wsa:ReplyTo>';
    return new SoapVar($xml, XSD_ANYXML);
}

function buildWsaHeaders(string $namespace, string $action, string $to): array
{
    $headers = [];
    $headers[] = new SoapHeader($namespace, 'Action', $action, true);
    $headers[] = new SoapHeader($namespace, 'To', $to, true);
    $headers[] = new SoapHeader($namespace, 'MessageID', 'urn:uuid:' . generateUuid(), true);
    $headers[] = new SoapHeader($namespace, 'ReplyTo', buildWsaReplyTo($namespace), true);
    return $headers;
}

function buildSoapHeaders(
    int $soapVersion,
    string $action,
    string $sid,
    string $sidHeaderName,
    string $wsaNamespace
): array {
    $headers = [];

    if ($soapVersion === SOAP_1_2 && $action !== '' && $wsaNamespace !== '') {
        $headers = array_merge($headers, buildWsaHeaders($wsaNamespace, $action, GUS_ENDPOINT));
    }

    if ($sid !== '' && $sidHeaderName !== '') {
        $headers[] = new SoapHeader(GUS_SID_NAMESPACE, $sidHeaderName, $sid, false);
    }

    return $headers;
}

function getWsaNamespaces(int $soapVersion): array
{
    if ($soapVersion === SOAP_1_2) {
        return [GUS_WSA_NAMESPACE_2005];
    }
    return [''];
}

function applySoapHeaders(SoapClient $client, array $headers): void
{
    if ($headers) {
        $client->__setSoapHeaders($headers);
        return;
    }
    $client->__setSoapHeaders(null);
}

function callGusMethod(
    SoapClient $client,
    array $functions,
    string $method,
    array $params,
    ?string $scalarParamName,
    int $soapVersion,
    string $sid,
    string $sidHeaderName,
    string $wsaNamespace
)
{
    if (!hasSoapFunction($functions, $method)) {
        throw new RuntimeException('Brak metody ' . $method . ' w WSDL.');
    }

    $options = [];
    $soapAction = getSoapAction($method);
    if ($soapAction !== '') {
        $options['soapaction'] = $soapAction;
    }

    $headers = buildSoapHeaders($soapVersion, $soapAction, $sid, $sidHeaderName, $wsaNamespace);
    $inputHeaders = $headers ?: null;
    $resultElement = getResultElementName($method);

    if ($scalarParamName !== null && isset($params[$scalarParamName]) && shouldUseScalarParam($functions, $method, $scalarParamName)) {
        try {
            $result = $client->__soapCall($method, [$params[$scalarParamName]], $options, $inputHeaders);
            $mtomResult = buildMtomResult($client, $method);
            if ($mtomResult) {
                return $mtomResult;
            }
            if ($resultElement !== '' && extractSoapResult($result, [$resultElement]) === '') {
                $mtomResult = buildMtomResult($client, $method);
                if ($mtomResult) {
                    return $mtomResult;
                }
            }
            return $result;
        } catch (SoapFault $e) {
            $mtomResult = buildMtomResult($client, $method, shouldForceMtomResult($e->getMessage()));
            if ($mtomResult) {
                return $mtomResult;
            }
            throw $e;
        }
    }

    try {
        $result = $client->__soapCall($method, [$params], $options, $inputHeaders);
        $mtomResult = buildMtomResult($client, $method);
        if ($mtomResult) {
            return $mtomResult;
        }
        if ($resultElement !== '' && extractSoapResult($result, [$resultElement]) === '') {
            $mtomResult = buildMtomResult($client, $method);
            if ($mtomResult) {
                return $mtomResult;
            }
        }
        return $result;
    } catch (SoapFault $e) {
        $mtomResult = buildMtomResult($client, $method, shouldForceMtomResult($e->getMessage()));
        if ($mtomResult) {
            return $mtomResult;
        }
        throw $e;
    }
}

function extractSoapResult($result, array $keys): string
{
    if (is_string($result)) {
        return trim($result);
    }

    if (is_object($result)) {
        foreach ($keys as $key) {
            if (property_exists($result, $key)) {
                return trim((string)$result->{$key});
            }
        }
    }

    if (is_array($result)) {
        foreach ($keys as $key) {
            if (isset($result[$key])) {
                return trim((string)$result[$key]);
            }
        }
    }

    return '';
}

function resolveSidHeaderName(array $types): string
{
    foreach ($types as $type) {
        if (preg_match('/\bSid\b/', $type)) {
            return 'Sid';
        }
        if (preg_match('/\bsid\b/', $type)) {
            return 'sid';
        }
    }
    return 'sid';
}

function setSidHeader(SoapClient $client, string $sid, string $headerName): void
{
    $header = new SoapHeader(GUS_SID_NAMESPACE, $headerName, $sid, false);
    $client->__setSoapHeaders($header);
}

function shouldRetrySidHeader(string $message): bool
{
    $lower = strtolower($message);
    if (strpos($lower, 'sid') === false) {
        return false;
    }
    if (strpos($lower, 'naglowka') !== false || strpos($lower, 'brak nag') !== false || strpos($lower, 'header') !== false) {
        return true;
    }
    if (strpos($lower, 'niepoprawny') !== false) {
        return true;
    }
    return false;
}

function alternateSidHeaderName(string $headerName): string
{
    return $headerName === 'sid' ? 'Sid' : 'sid';
}

function parseHttpCode(string $headers): int
{
    if ($headers === '') {
        return 0;
    }
    if (preg_match('/HTTP\/\d+\.\d+\s+(\d+)/', $headers, $matches)) {
        return (int)$matches[1];
    }
    return 0;
}

function classifyErrorClass(int $httpCode, bool $hasSoapFault): string
{
    if ($httpCode >= 500) {
        return 'HTTP_5XX';
    }
    if ($httpCode >= 400) {
        return 'HTTP_4XX';
    }
    if ($hasSoapFault) {
        return 'SOAP_FAULT';
    }
    return 'TRANSPORT';
}

function extractSoapFaultData(SoapFault $fault, string $sid): array
{
    $data = [
        'faultcode' => maskSensitiveText((string)$fault->faultcode, $sid),
        'faultstring' => maskSensitiveText((string)$fault->faultstring, $sid),
    ];

    if (isset($fault->detail)) {
        $detail = trim(print_r($fault->detail, true));
        if ($detail !== '') {
            $data['detail'] = maskSensitiveText($detail, $sid);
        }
    }

    return $data;
}

function collectSoapDebugInfo(?SoapClient $client, array $functions, ?SoapFault $fault, string $sid, array $meta, int $limit): array
{
    $info = $meta;

    if ($functions) {
        $info['functions'] = $functions;
    }

    if ($fault) {
        $info['soap_fault'] = extractSoapFaultData($fault, $sid);
    }

    if ($client) {
        $requestHeaders = method_exists($client, '__getLastRequestHeaders') ? (string)$client->__getLastRequestHeaders() : '';
        $requestBody = method_exists($client, '__getLastRequest') ? (string)$client->__getLastRequest() : '';
        $responseHeaders = method_exists($client, '__getLastResponseHeaders') ? (string)$client->__getLastResponseHeaders() : '';
        $responseBody = method_exists($client, '__getLastResponse') ? (string)$client->__getLastResponse() : '';

        $httpCode = parseHttpCode($responseHeaders);
        if ($httpCode > 0) {
            $info['http_code'] = $httpCode;
        }

        $info['last_request_headers'] = compactDebugValue($requestHeaders, $sid, $limit);
        $info['last_request'] = compactDebugValue($requestBody, $sid, $limit);
        $info['last_response_headers'] = compactDebugValue($responseHeaders, $sid, $limit);
        $info['last_response'] = compactDebugValue($responseBody, $sid, $limit);

        $mtomSoap = extractMtomSoapXml($responseHeaders, $responseBody);
        if ($mtomSoap !== '') {
            $info['last_response_soap'] = compactDebugValue($mtomSoap, $sid, $limit);
        }
    }

    return sanitizeDebugPayload($info, $sid, $limit);
}

function getIniInfo(): array
{
    return [
        'default_socket_timeout' => ini_get('default_socket_timeout'),
        'memory_limit' => ini_get('memory_limit'),
        'max_execution_time' => ini_get('max_execution_time'),
    ];
}

function isCurlSslError(int $errno, string $error): bool
{
    if ($errno === 60) {
        return true;
    }
    $lower = strtolower($error);
    return strpos($lower, 'ssl') !== false && (strpos($lower, 'cert') !== false || strpos($lower, 'verify') !== false);
}

function curlProbe(string $url, bool $allowInsecure): array
{
    if (!function_exists('curl_init')) {
        return [];
    }

    $ch = curl_init();
    if ($ch === false) {
        return [];
    }

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    if ($allowInsecure) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    }

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);

    return [
        'url' => $url,
        'http_code' => $info['http_code'] ?? 0,
        'errno' => $errno,
        'error' => $error,
        'content_type' => $info['content_type'] ?? '',
        'response_headers' => is_string($response) ? trim($response) : '',
        'insecure_ssl' => $allowInsecure ? '1' : '0',
    ];
}

function runCurlDiagnostics(bool $debug): array
{
    if (!function_exists('curl_init')) {
        return [];
    }

    $wsdl = curlProbe(GUS_WSDL, false);
    $endpoint = curlProbe(GUS_ENDPOINT, false);

    $result = [
        'wsdl' => $wsdl,
        'endpoint' => $endpoint,
    ];

    if ($debug) {
        if ($wsdl && isCurlSslError((int)($wsdl['errno'] ?? 0), (string)($wsdl['error'] ?? ''))) {
            $result['wsdl_insecure'] = curlProbe(GUS_WSDL, true);
        }
        if ($endpoint && isCurlSslError((int)($endpoint['errno'] ?? 0), (string)($endpoint['error'] ?? ''))) {
            $result['endpoint_insecure'] = curlProbe(GUS_ENDPOINT, true);
        }
    }

    return $result;
}

function gusLogin(SoapClient $client, array $functions, int $soapVersion, string $wsaNamespace): string
{
    $login = callGusMethod(
        $client,
        $functions,
        'Zaloguj',
        ['pKluczUzytkownika' => GUS_API_KEY],
        'pKluczUzytkownika',
        $soapVersion,
        '',
        '',
        $wsaNamespace
    );

    $sid = extractSoapResult($login, ['ZalogujResult']);
    if ($sid === '') {
        $mtomResult = buildMtomResult($client, 'Zaloguj');
        if ($mtomResult) {
            $sid = extractSoapResult($mtomResult, ['ZalogujResult']);
        }
    }
    if ($sid === '') {
        throw new RuntimeException('Brak SID z Zaloguj.');
    }

    return $sid;
}

function runSelfTest(bool $debug, string $logPath): void
{
    $stepResults = [
        'soap_extension' => extension_loaded('soap'),
        'curl_extension' => function_exists('curl_init'),
    ];

    $attempts = [];
    $ok = false;
    $lastDebugInfo = [];

    if (!$stepResults['soap_extension']) {
        respond(false, 'Brak rozszerzenia SOAP na serwerze.', null, $debug, [
            'step_results' => $stepResults,
            'ini' => getIniInfo(),
            'curl_probe' => runCurlDiagnostics($debug),
        ], '', '', 500, 'MISSING_SOAP_EXTENSION');
    }

    $soapVersions = [SOAP_1_1, SOAP_1_2];

    foreach ($soapVersions as $soapVersion) {
        $wsaNamespaces = getWsaNamespaces($soapVersion);
        foreach ($wsaNamespaces as $wsaNamespace) {
            $attempt = [
                'soap_version' => $soapVersion === SOAP_1_2 ? '1.2' : '1.1',
                'wsdl_loaded' => false,
                'login_ok' => false,
                'sid_present' => false,
                'getvalue_ok' => false,
                'insecure_ssl' => '0',
                'wsa' => $soapVersion === SOAP_1_2 ? '1' : '0',
                'wsa_namespace' => $wsaNamespace !== '' ? $wsaNamespace : 'none',
            ];

            $client = null;
            $functions = [];
            $types = [];
            $sid = '';
            $fault = null;

            try {
                $client = createSoapClient($soapVersion, false);
                $functions = getSoapFunctions($client);
                $types = getSoapTypes($client);
                $attempt['wsdl_loaded'] = true;

                $sid = gusLogin($client, $functions, $soapVersion, $wsaNamespace);
                $attempt['login_ok'] = true;
                $attempt['sid_present'] = true;
                $attempt['sid_http_header'] = '1';

                $client = createSoapClient($soapVersion, false, ['sid: ' . $sid]);
                $functions = getSoapFunctions($client);
                $types = getSoapTypes($client);

                $headerName = resolveSidHeaderName($types);

                if (hasSoapFunction($functions, 'GetValue')) {
                    $value = callGusMethod(
                        $client,
                        $functions,
                        'GetValue',
                        ['pNazwaParametru' => 'StanDanych'],
                        'pNazwaParametru',
                        $soapVersion,
                        $sid,
                        $headerName,
                        $wsaNamespace
                    );
                    $result = extractSoapResult($value, ['GetValueResult']);
                    $attempt['getvalue_ok'] = $result !== '';
                }

                $ok = true;
            } catch (SoapFault $e) {
                $fault = $e;
                $attempt['fault'] = extractSoapFaultData($e, $sid);
                $headers = $client ? (string)$client->__getLastResponseHeaders() : '';
                $httpCode = parseHttpCode($headers);
                if ($httpCode > 0) {
                    $attempt['http_code'] = $httpCode;
                }

                if ($debug && isSslError($e->getMessage())) {
                    try {
                        $client = createSoapClient($soapVersion, true);
                        $functions = getSoapFunctions($client);
                        $types = getSoapTypes($client);
                        $attempt['wsdl_loaded'] = true;
                        $attempt['insecure_ssl'] = '1';

                        $sid = gusLogin($client, $functions, $soapVersion, $wsaNamespace);
                        $attempt['login_ok'] = true;
                        $attempt['sid_present'] = true;
                        $attempt['sid_http_header'] = '1';

                        $client = createSoapClient($soapVersion, true, ['sid: ' . $sid]);
                        $functions = getSoapFunctions($client);
                        $types = getSoapTypes($client);

                        $headerName = resolveSidHeaderName($types);

                        if (hasSoapFunction($functions, 'GetValue')) {
                            $value = callGusMethod(
                                $client,
                                $functions,
                                'GetValue',
                                ['pNazwaParametru' => 'StanDanych'],
                                'pNazwaParametru',
                                $soapVersion,
                                $sid,
                                $headerName,
                                $wsaNamespace
                            );
                            $result = extractSoapResult($value, ['GetValueResult']);
                            $attempt['getvalue_ok'] = $result !== '';
                        }

                        $ok = true;
                    } catch (SoapFault $e2) {
                        $fault = $e2;
                        $attempt['fault'] = extractSoapFaultData($e2, $sid);
                        $headers = $client ? (string)$client->__getLastResponseHeaders() : '';
                        $httpCode = parseHttpCode($headers);
                        if ($httpCode > 0) {
                            $attempt['http_code'] = $httpCode;
                        }
                    }
                }
            } catch (Throwable $e) {
                $attempt['error'] = $e->getMessage();
            }

            $attempts[] = $attempt;

            if ($debug && $client) {
                $lastDebugInfo = collectSoapDebugInfo(
                    $client,
                    $functions,
                    $fault,
                    $sid,
                    [
                        'soap_version' => $attempt['soap_version'],
                        'insecure_ssl' => $attempt['insecure_ssl'],
                        'wsa_namespace' => $attempt['wsa_namespace'],
                    ],
                    GUS_DEBUG_LIMIT
                );
                $logInfo = collectSoapDebugInfo(
                    $client,
                    $functions,
                    $fault,
                    $sid,
                    [
                        'soap_version' => $attempt['soap_version'],
                        'insecure_ssl' => $attempt['insecure_ssl'],
                        'wsa_namespace' => $attempt['wsa_namespace'],
                    ],
                    0
                );
                logPayload($logPath, 'selftest', $logInfo, $sid);
            }

            if ($ok) {
                break 2;
            }
        }
    }

    $stepResults['wsdl_loaded'] = false;
    $stepResults['login_ok'] = false;
    $stepResults['sid_present'] = false;
    $stepResults['getvalue_ok'] = false;

    foreach ($attempts as $attempt) {
        if (!empty($attempt['wsdl_loaded'])) {
            $stepResults['wsdl_loaded'] = true;
        }
        if (!empty($attempt['login_ok'])) {
            $stepResults['login_ok'] = true;
        }
        if (!empty($attempt['sid_present'])) {
            $stepResults['sid_present'] = true;
        }
        if (!empty($attempt['getvalue_ok'])) {
            $stepResults['getvalue_ok'] = true;
        }
    }

    $debugPayload = [
        'step_results' => $stepResults,
        'attempts' => $attempts,
        'ini' => getIniInfo(),
        'curl_probe' => runCurlDiagnostics($debug),
        'log_status' => getLogStatus($logPath),
        'log_error' => getLogError(),
    ];

    if ($debug) {
        if ($lastDebugInfo) {
            $debugPayload = array_merge($debugPayload, $lastDebugInfo);
        }
        $debugPayload = sanitizeDebugPayload($debugPayload, '', GUS_DEBUG_LIMIT);
    }

    respond(
        $ok,
        $ok ? 'Selftest OK' : 'Selftest nieudany',
        null,
        $debug,
        $debugPayload,
        '',
        '',
        $ok ? 200 : 502,
        $ok ? 'OK' : 'SELFTEST_FAILED'
    );
}

$nip = isset($_GET['nip']) ? normalizeNip($_GET['nip']) : '';
$regonParam = isset($_GET['regon']) ? normalizeRegon($_GET['regon']) : '';
$krsParam = isset($_GET['krs']) ? normalizeKrs($_GET['krs']) : '';
$debug = isset($_GET['debug']) && $_GET['debug'] === '1';
$selftest = isset($_GET['selftest']) && $_GET['selftest'] === '1';
$traceId = uniqid('gus_', true);

if ($nip !== '' && ($regonParam === '' || $krsParam === '') && isset($pdo) && $pdo instanceof PDO) {
    try {
        require_once __DIR__ . '/includes/db_utils.php';
        if (tableExists($pdo, 'companies')) {
            $stmt = $pdo->prepare('SELECT regon, krs FROM companies WHERE nip = :nip LIMIT 1');
            $stmt->execute([':nip' => $nip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($regonParam === '' && !empty($row['regon'])) {
                $regonParam = normalizeRegon((string)$row['regon']);
            }
            if ($krsParam === '' && !empty($row['krs'])) {
                $krsParam = normalizeKrs((string)$row['krs']);
            }
        }
        if (($regonParam === '' || $krsParam === '') && tableExists($pdo, 'klienci')) {
            $stmt = $pdo->prepare('SELECT regon, krs FROM klienci WHERE nip = :nip LIMIT 1');
            $stmt->execute([':nip' => $nip]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($regonParam === '' && !empty($row['regon'])) {
                $regonParam = normalizeRegon((string)$row['regon']);
            }
            if ($krsParam === '' && !empty($row['krs'])) {
                $krsParam = normalizeKrs((string)$row['krs']);
            }
        }
    } catch (Throwable $e) {
        // fallback identifiers are optional; ignore lookup failures
    }
}

$logPath = resolveLogPath();

if (defined('GUS_LOOKUP_LIB') && GUS_LOOKUP_LIB) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    return;
}

if (!$gusRuntimeConfig['enabled']) {
    respond(false, 'Integracja GUS jest wylaczona.', null, $debug, [
        'config_source' => $gusRuntimeConfig['source'],
    ], '', '', 503, 'GUS_DISABLED');
}

if ($gusRuntimeConfig['error'] !== '') {
    respond(false, $gusRuntimeConfig['error'], null, $debug, [
        'config_source' => $gusRuntimeConfig['source'],
    ], '', '', 500, 'GUS_CONFIG_INVALID');
}

if (trim(GUS_API_KEY) === '') {
    respond(false, 'Brak klucza GUS w ustawieniach.', null, $debug, [
        'config_source' => $gusRuntimeConfig['source'],
    ], '', '', 500, 'GUS_API_KEY_MISSING');
}

if ($debug) {
    logPayload($logPath, 'probe', [
        'query' => isset($_SERVER['QUERY_STRING']) ? (string)$_SERVER['QUERY_STRING'] : '',
        'log_status' => getLogStatus($logPath),
    ]);
}

if ($selftest) {
    runSelfTest($debug, $logPath);
}

if ($nip === '' && $regonParam === '' && $krsParam === '') {
    saveValidationSnapshot($gusEnv, 'none', '', $traceId);
    respond(false, 'Podaj NIP, REGON lub KRS.', null, $debug, [], '', '', 400, 'VALIDATION_IDENTIFIER_MISSING');
}

if ($nip !== '' && !isValidNip($nip)) {
    saveValidationSnapshot($gusEnv, 'nip', $nip, $traceId);
    respond(false, 'Podaj poprawny numer NIP (10 cyfr).', null, $debug, [], '', '', 400, 'VALIDATION_NIP');
}

if ($regonParam !== '' && !isValidRegon($regonParam)) {
    saveValidationSnapshot($gusEnv, 'regon', $regonParam, $traceId);
    respond(false, 'Podaj poprawny numer REGON (9 lub 14 cyfr).', null, $debug, [], '', '', 400, 'VALIDATION_REGON');
}

if ($krsParam !== '' && !isValidKrs($krsParam)) {
    saveValidationSnapshot($gusEnv, 'krs', $krsParam, $traceId);
    respond(false, 'Podaj poprawny numer KRS (10 cyfr).', null, $debug, [], '', '', 400, 'VALIDATION_KRS');
}

if (!extension_loaded('soap')) {
    respond(false, 'Brak rozszerzenia SOAP na serwerze.', null, $debug, [
        'ini' => getIniInfo(),
    ], '', '', 500, 'MISSING_SOAP_EXTENSION');
}

if (!function_exists('curl_init')) {
    respond(false, 'Brak rozszerzenia cURL na serwerze (wymagane do HTTPS).', null, $debug, [
        'ini' => getIniInfo(),
    ], '', '', 500, 'MISSING_CURL_EXTENSION');
}

$soapVersions = [SOAP_1_1, SOAP_1_2];
$attempts = [];
$lastErrorMessage = '';
$lastDebug = [];
$finalData = null;
$rawXml = '';
$reportName = '';
$lastRawRequest = '';
$lastRawResponse = '';
$lastHttpCode = 0;
$requestStart = microtime(true);
$attemptCounter = 0;
$lastErrorClass = null;
$lastFaultCode = null;
$lastFaultString = null;

foreach ($soapVersions as $soapVersion) {
    $wsaNamespaces = getWsaNamespaces($soapVersion);
    foreach ($wsaNamespaces as $wsaNamespace) {
        $allowInsecure = false;

        while (true) {
            $attemptMeta = [
                'soap_version' => $soapVersion === SOAP_1_2 ? '1.2' : '1.1',
                'insecure_ssl' => $allowInsecure ? '1' : '0',
                'wsa' => $soapVersion === SOAP_1_2 ? '1' : '0',
                'wsa_namespace' => $wsaNamespace !== '' ? $wsaNamespace : 'none',
                'ini' => getIniInfo(),
                'curl_probe' => $debug ? runCurlDiagnostics(true) : [],
                'log_status' => getLogStatus($logPath),
                'log_error' => getLogError(),
            ];
            $attemptCounter++;

            $client = null;
            $functions = [];
            $types = [];
            $sid = '';
            $fault = null;
            $httpCode = 0;

            try {
                $client = createSoapClient($soapVersion, $allowInsecure);
                $functions = getSoapFunctions($client);
                $types = getSoapTypes($client);

                $sid = gusLogin($client, $functions, $soapVersion, $wsaNamespace);
                $attemptMeta['sid_http_header'] = '1';

                $client = createSoapClient($soapVersion, $allowInsecure, ['sid: ' . $sid]);
                $functions = getSoapFunctions($client);
                $types = getSoapTypes($client);
                $headerName = resolveSidHeaderName($types);
                $attemptMeta['sid_header'] = $headerName;

                if (hasSoapFunction($functions, 'GetValue')) {
                    try {
                        $sanity = callGusMethod(
                            $client,
                            $functions,
                            'GetValue',
                            ['pNazwaParametru' => 'StanDanych'],
                            'pNazwaParametru',
                            $soapVersion,
                            $sid,
                            $headerName,
                            $wsaNamespace
                        );
                        $sanityValue = extractSoapResult($sanity, ['GetValueResult']);
                        if ($sanityValue !== '') {
                            $attemptMeta['sanity_check'] = $sanityValue;
                        }
                    } catch (SoapFault $e) {
                        if (shouldRetrySidHeader($e->getMessage())) {
                            $headerName = alternateSidHeaderName($headerName);
                            $attemptMeta['sid_header'] = $headerName;

                            $sanity = callGusMethod(
                                $client,
                                $functions,
                                'GetValue',
                                ['pNazwaParametru' => 'StanDanych'],
                                'pNazwaParametru',
                                $soapVersion,
                                $sid,
                                $headerName,
                                $wsaNamespace
                            );
                            $sanityValue = extractSoapResult($sanity, ['GetValueResult']);
                            if ($sanityValue !== '') {
                                $attemptMeta['sanity_check'] = $sanityValue;
                            }
                        } else {
                            $attemptMeta['sanity_error'] = $e->getMessage();
                        }
                    }
                }

                $searchCriteria = [];
                if ($nip !== '') {
                    $searchCriteria[] = [
                        'label' => 'nip',
                        'params' => ['Nip' => $nip],
                    ];
                }
                if ($regonParam !== '') {
                    $searchCriteria[] = [
                        'label' => 'regon',
                        'params' => ['Regon' => $regonParam],
                    ];
                }
                if ($krsParam !== '') {
                    $searchCriteria[] = [
                        'label' => 'krs',
                        'params' => ['Krs' => $krsParam],
                    ];
                }

                $searchData = [];
                $searchXml = '';
                $usedSearch = '';

                foreach ($searchCriteria as $criteria) {
                    try {
                        $search = callGusMethod(
                            $client,
                            $functions,
                            'DaneSzukajPodmioty',
                            [
                                'pParametryWyszukiwania' => $criteria['params'],
                            ]
                            ,
                            null,
                            $soapVersion,
                            $sid,
                            $headerName,
                            $wsaNamespace
                        );
                    } catch (SoapFault $e) {
                        if (shouldRetrySidHeader($e->getMessage())) {
                            $headerName = alternateSidHeaderName($headerName);
                            $attemptMeta['sid_header'] = $headerName;

                            $search = callGusMethod(
                                $client,
                                $functions,
                                'DaneSzukajPodmioty',
                                [
                                    'pParametryWyszukiwania' => $criteria['params'],
                                ]
                                ,
                                null,
                                $soapVersion,
                                $sid,
                                $headerName,
                                $wsaNamespace
                            );
                        } else {
                            throw $e;
                        }
                    }

                    $searchXml = extractSoapResult($search, ['DaneSzukajPodmiotyResult']);
                    $searchData = parseGusXml($searchXml);
                    if (!empty($searchData)) {
                        $usedSearch = $criteria['label'];
                        break;
                    }
                }

                if ($usedSearch !== '') {
                    $attemptMeta['search_criteria'] = $usedSearch;
                }

                if (empty($searchData)) {
                    if (isset($pdo) && $pdo instanceof PDO) {
                        $ttlDays = resolveCacheTtlDays($pdo);
                        $cached = fetchCachedSnapshot($pdo, [$nip, $regonParam, $krsParam], $ttlDays);
                        if (is_array($cached)) {
                            $attemptMeta['cache_hit'] = '1';
                            $attemptMeta['cache_ttl_days'] = (string)$ttlDays;
                            $debugInfo = $debug ? collectSoapDebugInfo(
                                $client,
                                $functions,
                                null,
                                $sid,
                                $attemptMeta,
                                GUS_DEBUG_LIMIT
                            ) : [];
                            respond(true, 'OK (cache)', $cached, $debug, $debugInfo, '', 'cache');
                        }
                    }
                    throw new RuntimeException('Nie znaleziono podmiotu w GUS.');
                }

                $regon = trim((string)($searchData['Regon'] ?? ''));
                if ($regon === '' && $regonParam !== '') {
                    $regon = $regonParam;
                }
                if ($regon === '') {
                    throw new RuntimeException('Brak numeru REGON w odpowiedzi GUS.');
                }

                $reportNames = [
                    'BIR11OsPrawna',
                    'BIR11OsFizycznaDzialalnoscGospodarcza',
                    'BIR11OsFizycznaDzialalnoscRolnicza',
                    'BIR11JednLokalnaOsPrawnej',
                ];

                $reportData = [];
                $reportXmlRaw = '';
                $usedReport = '';

                foreach ($reportNames as $currentReport) {
                    try {
                        $report = callGusMethod(
                            $client,
                            $functions,
                            'DanePobierzPelnyRaport',
                            [
                                'pRegon' => $regon,
                                'pNazwaRaportu' => $currentReport,
                            ]
                            ,
                            null,
                            $soapVersion,
                            $sid,
                            $headerName,
                            $wsaNamespace
                        );
                    } catch (SoapFault $e) {
                        $reportXmlRaw = '';
                        continue;
                    }

                    $reportXmlRaw = extractSoapResult($report, ['DanePobierzPelnyRaportResult']);
                    $reportData = parseGusXml($reportXmlRaw);
                    if (!empty($reportData)) {
                        $usedReport = $currentReport;
                        break;
                    }
                }

                $merged = array_merge($searchData, $reportData);
                $finalData = normalizeGusData($merged, $nip, $regon);
                $rawXml = $debug ? decodeGusXml($reportXmlRaw) : '';
                $reportName = $usedReport;

                $debugInfo = $debug ? collectSoapDebugInfo(
                    $client,
                    $functions,
                    null,
                    $sid,
                    $attemptMeta,
                    GUS_DEBUG_LIMIT
                ) : [];

                if ($debug) {
                    $logInfo = collectSoapDebugInfo(
                        $client,
                        $functions,
                        null,
                        $sid,
                        $attemptMeta,
                        0
                    );
                    logPayload($logPath, 'success', $logInfo, $sid);
                }

                $requestType = $usedSearch !== '' ? $usedSearch : ($nip !== '' ? 'nip' : ($regonParam !== '' ? 'regon' : 'krs'));
                if ($requestType === 'regon') {
                    $requestValue = $regonParam;
                } elseif ($requestType === 'krs') {
                    $requestValue = $krsParam;
                } else {
                    $requestValue = $nip;
                }
                $lastRequestHeaders = $client ? (string)$client->__getLastRequestHeaders() : '';
                $lastResponseHeaders = $client ? (string)$client->__getLastResponseHeaders() : '';
                $lastRawRequest = $client ? (string)$client->__getLastRequest() : '';
                $lastRawResponse = $client ? (string)$client->__getLastResponse() : '';
                $lastHttpCode = parseHttpCode($lastResponseHeaders);
                $latencyMs = (int)round((microtime(true) - $requestStart) * 1000);
                saveGusSnapshot([
                    'env' => $gusEnv,
                    'request_type' => $requestType,
                    'request_value' => $requestValue,
                    'report_type' => $reportName !== '' ? $reportName : null,
                    'http_code' => $lastHttpCode > 0 ? $lastHttpCode : null,
                    'ok' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'error_class' => null,
                    'attempt_no' => max(1, $attemptCounter),
                    'latency_ms' => $latencyMs,
                    'fault_code' => null,
                    'fault_string' => null,
                    'raw_request' => $lastRawRequest !== '' ? $lastRawRequest : $lastRequestHeaders,
                    'raw_response' => $lastRawResponse !== '' ? $lastRawResponse : $lastResponseHeaders,
                    'raw_parsed' => $finalData,
                    'correlation_id' => $traceId,
                ]);

                respond(true, 'OK', $finalData, $debug, $debugInfo, $rawXml, $reportName);
            } catch (SoapFault $e) {
                if ($debug && !$allowInsecure && isSslError($e->getMessage())) {
                    $allowInsecure = true;
                    continue;
                }

                $fault = $e;
                $headers = $client ? (string)$client->__getLastResponseHeaders() : '';
                $httpCode = parseHttpCode($headers);
                $attemptMeta['http_code'] = $httpCode > 0 ? $httpCode : '';
                $lastErrorMessage = $e->getMessage();
                $lastRawRequest = $client ? (string)$client->__getLastRequest() : '';
                $lastRawResponse = $client ? (string)$client->__getLastResponse() : '';
                $lastHttpCode = $httpCode;
                $faultCode = isset($e->faultcode) ? trim((string)$e->faultcode) : '';
                $faultString = isset($e->faultstring) ? trim((string)$e->faultstring) : '';
                $lastFaultCode = $faultCode !== '' ? $faultCode : null;
                $lastFaultString = $faultString !== '' ? $faultString : null;
                $lastErrorClass = classifyErrorClass($httpCode, true);

                $debugInfo = $debug ? collectSoapDebugInfo(
                    $client,
                    $functions,
                    $fault,
                    $sid,
                    $attemptMeta,
                    GUS_DEBUG_LIMIT
                ) : [];

                if ($debug) {
                    $logInfo = collectSoapDebugInfo(
                        $client,
                        $functions,
                        $fault,
                        $sid,
                        $attemptMeta,
                        0
                    );
                    logPayload($logPath, 'fault', $logInfo, $sid);
                }

                $attempts[] = [
                    'soap_version' => $attemptMeta['soap_version'],
                    'insecure_ssl' => $attemptMeta['insecure_ssl'],
                    'wsa_namespace' => $attemptMeta['wsa_namespace'],
                    'http_code' => $httpCode,
                    'fault' => extractSoapFaultData($e, $sid),
                ];

                $lastDebug = $debugInfo;
                break;
            } catch (Throwable $e) {
                if ($debug && !$allowInsecure && isSslError($e->getMessage())) {
                    $allowInsecure = true;
                    continue;
                }

                $lastErrorMessage = $e->getMessage();
                $lastRawRequest = $client ? (string)$client->__getLastRequest() : '';
                $lastRawResponse = $client ? (string)$client->__getLastResponse() : '';
                $lastHttpCode = isset($attemptMeta['http_code']) ? (int)$attemptMeta['http_code'] : 0;
                $lastErrorClass = classifyErrorClass($lastHttpCode, false);
                $attempts[] = [
                    'soap_version' => $attemptMeta['soap_version'],
                    'insecure_ssl' => $attemptMeta['insecure_ssl'],
                    'wsa_namespace' => $attemptMeta['wsa_namespace'],
                    'error' => $lastErrorMessage,
                ];

                $debugInfo = $debug ? collectSoapDebugInfo(
                    $client,
                    $functions,
                    null,
                    $sid,
                    $attemptMeta + ['error' => $lastErrorMessage],
                    GUS_DEBUG_LIMIT
                ) : [];

                if ($debug) {
                    $logInfo = collectSoapDebugInfo(
                        $client,
                        $functions,
                        null,
                        $sid,
                        $attemptMeta + ['error' => $lastErrorMessage],
                        0
                    );
                    logPayload($logPath, 'error', $logInfo, $sid);
                }

                $lastDebug = $debugInfo;
                break;
            }
        }
    }
}

$finalDebug = $lastDebug;
if ($debug) {
    $finalDebug = array_merge($finalDebug, [
        'attempts' => $attempts,
        'log_status' => getLogStatus($logPath),
        'log_error' => getLogError(),
    ]);
    $finalDebug = sanitizeDebugPayload($finalDebug, '', GUS_DEBUG_LIMIT);
}

$httpCode = 0;
if (isset($finalDebug['http_code'])) {
    $httpCode = (int)$finalDebug['http_code'];
}

if ($lastHttpCode <= 0 && $httpCode > 0) {
    $lastHttpCode = $httpCode;
}

$requestType = $nip !== '' ? 'nip' : ($regonParam !== '' ? 'regon' : 'krs');
$requestValue = $nip !== '' ? $nip : ($regonParam !== '' ? $regonParam : $krsParam);
$latencyMs = (int)round((microtime(true) - $requestStart) * 1000);
$errorClass = $lastErrorClass ?? classifyErrorClass($lastHttpCode, false);
saveGusSnapshot([
    'env' => $gusEnv,
    'request_type' => $requestType,
    'request_value' => $requestValue,
    'report_type' => $reportName !== '' ? $reportName : null,
    'http_code' => $lastHttpCode > 0 ? $lastHttpCode : null,
    'ok' => false,
    'error_code' => null,
    'error_message' => $lastErrorMessage !== '' ? $lastErrorMessage : 'Blad SOAP.',
    'error_class' => $errorClass,
    'attempt_no' => max(1, $attemptCounter),
    'latency_ms' => $latencyMs,
    'fault_code' => $lastFaultCode,
    'fault_string' => $lastFaultString,
    'raw_request' => $lastRawRequest !== '' ? $lastRawRequest : null,
    'raw_response' => $lastRawResponse !== '' ? $lastRawResponse : null,
    'raw_parsed' => null,
    'correlation_id' => $traceId,
]);

respond(
    false,
    mapSoapFaultMessage($lastErrorMessage, $httpCode),
    null,
    $debug,
    $finalDebug,
    '',
    '',
    502,
    $errorClass !== '' ? $errorClass : 'GUS_SOAP_ERROR',
    $lastRawResponse !== '' ? $lastRawResponse : $lastErrorMessage
);
