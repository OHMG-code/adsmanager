<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
ob_start();

$gusApiRequestId = uniqid('gus_api_', true);
$GLOBALS['gus_api_lookup_sent'] = false;

register_shutdown_function(static function () use ($gusApiRequestId): void {
    if (!empty($GLOBALS['gus_api_lookup_sent'])) {
        return;
    }

    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $isFatal = is_array($error) && in_array((int)($error['type'] ?? 0), $fatalTypes, true);

    $buffer = gusApiLookupDrainBuffer();
    $rawSnippet = gusApiLookupSnippet($buffer);
    if ($isFatal && isset($error['message'])) {
        $rawSnippet = gusApiLookupSnippet(trim($rawSnippet . ' ' . (string)$error['message']));
    }

    if (!$isFatal && $rawSnippet === '') {
        return;
    }

    $code = $isFatal ? 'GUS_FATAL' : 'NON_JSON_RESPONSE';
    $message = $isFatal ? 'Błąd krytyczny backendu GUS.' : 'Nieprawidłowa odpowiedź backendu GUS.';
    gusApiLookupLogFailure($gusApiRequestId, $code, $message, $rawSnippet);

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
        'requestId' => $gusApiRequestId,
    ], JSON_UNESCAPED_UNICODE);

    $GLOBALS['gus_api_lookup_sent'] = true;
});

function gusApiLookupLogPath(): string
{
    $storageDir = dirname(__DIR__, 2) . '/storage/logs';
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

function gusApiLookupSnippet(?string $value, int $limit = 2000): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }

    if (strlen($value) > $limit) {
        return substr($value, 0, $limit);
    }

    return $value;
}

function gusApiLookupDrainBuffer(): string
{
    $buffer = '';
    while (ob_get_level() > 0) {
        $chunk = (string)ob_get_contents();
        ob_end_clean();
        $buffer = $chunk . $buffer;
    }

    return $buffer;
}

function gusApiLookupLogFailure(string $requestId, string $code, string $message, string $rawSnippet = ''): void
{
    $line = json_encode([
        'ts' => date('c'),
        'requestId' => $requestId,
        'code' => $code,
        'message' => $message,
        'rawSnippet' => $rawSnippet !== '' ? $rawSnippet : null,
    ], JSON_UNESCAPED_UNICODE);

    if ($line === false) {
        return;
    }

    @file_put_contents(gusApiLookupLogPath(), $line . PHP_EOL, FILE_APPEND);
}

function gusApiLookupEmit(int $statusCode, array $payload): void
{
    gusApiLookupDrainBuffer();

    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }

    $GLOBALS['gus_api_lookup_sent'] = true;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function gusApiLookupError(int $statusCode, string $code, string $message, string $requestId, string $rawSnippet = ''): void
{
    if ($rawSnippet === '') {
        $rawSnippet = gusApiLookupSnippet(gusApiLookupDrainBuffer());
    } else {
        $rawSnippet = gusApiLookupSnippet($rawSnippet);
    }

    gusApiLookupLogFailure($requestId, $code, $message, $rawSnippet);

    gusApiLookupEmit($statusCode, [
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
    ]);
}

function gusApiLookupSuccess(array $data, string $requestId, bool $cached): void
{
    gusApiLookupEmit(200, [
        'ok' => true,
        'success' => true,
        'message' => 'OK',
        'data' => $data,
        'cached' => $cached,
        'requestId' => $requestId,
    ]);
}

function gusApiLookupStatus(string $code, string $message): int
{
    if (stripos($message, 'chwilowo niedostępna') !== false) {
        return 503;
    }

    if (str_starts_with($code, 'VALIDATION')) {
        return 400;
    }

    switch ($code) {
        case 'NOT_FOUND':
            return 404;
        case 'UNAUTHORIZED':
            return 401;
        case 'FORBIDDEN':
            return 403;
        case 'GUS_DISABLED':
            return 503;
        case 'CONFIG_MISSING':
            return 500;
        case 'LOGIN_FAILED':
        case 'LOGIN_SID_MISSING':
        case 'SEARCH_FAILED':
        case 'GUS_SERVICE_ERROR':
            return 502;
        default:
            return 500;
    }
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../includes/gus_config.php';
require_once __DIR__ . '/../includes/gus_service.php';
require_once __DIR__ . '/../includes/gus_validation.php';

try {
    if (!isset($_SESSION['user_id'])) {
        gusApiLookupError(401, 'UNAUTHORIZED', 'Wymagane logowanie.', $gusApiRequestId);
    }

    $currentUser = fetchCurrentUser($pdo);
    if (!$currentUser) {
        gusApiLookupError(401, 'UNAUTHORIZED', 'Nie można zweryfikować użytkownika.', $gusApiRequestId);
    }

    ensureSystemConfigColumns($pdo);
    ensureGusCacheTable($pdo);
    ensureIntegrationsLogsTable($pdo);

    $configRow = $pdo->query("SELECT gus_enabled, gus_cache_ttl_days FROM konfiguracja_systemu WHERE id = 1")
        ->fetch(PDO::FETCH_ASSOC) ?: [];
    if (empty($configRow['gus_enabled'])) {
        gusApiLookupError(503, 'GUS_DISABLED', 'Integracja GUS jest wyłączona.', $gusApiRequestId);
    }

    $runtime = gusResolveRuntimeConfig();
    if (empty($runtime['ok'])) {
        $missing = isset($runtime['missing']) ? implode(', ', (array)$runtime['missing']) : 'brakujące wartości';
        gusApiLookupError(500, 'CONFIG_MISSING', 'Brak konfiguracji GUS w .env: ' . $missing . '.', $gusApiRequestId);
    }

    $nip = isset($_GET['nip']) ? normalizeNip($_GET['nip']) : '';
    $regon = isset($_GET['regon']) ? normalizeRegon($_GET['regon']) : '';
    $krs = isset($_GET['krs']) ? normalizeKrs($_GET['krs']) : '';
    if ($nip === '' && $regon === '' && $krs === '') {
        gusApiLookupError(400, 'VALIDATION_IDENTIFIER_MISSING', 'Podaj NIP, REGON lub KRS.', $gusApiRequestId);
    }

    $config = [
        'cache_ttl_days' => (int)($configRow['gus_cache_ttl_days'] ?? 30),
    ];

    if ($nip !== '') {
        $result = gusFetchCompanyByNip($pdo, $nip, $config, (int)$currentUser['id']);
    } elseif ($regon !== '') {
        $result = gusFetchCompanyByRegon($pdo, $regon, $config, (int)$currentUser['id']);
    } else {
        $result = gusFetchCompanyByKrs($pdo, $krs, $config, (int)$currentUser['id']);
    }

    $requestId = (string)($result['request_id'] ?? $gusApiRequestId);
    if (!empty($result['success'])) {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        gusApiLookupSuccess($data, $requestId, !empty($result['cached']));
    }

    $errorMessage = (string)($result['error'] ?? 'Błąd usługi GUS.');
    $errorCode = (string)($result['code'] ?? 'GUS_SERVICE_ERROR');
    $statusCode = gusApiLookupStatus($errorCode, $errorMessage);
    $rawSnippet = gusApiLookupSnippet((string)($result['raw_snippet'] ?? ''));
    if ($rawSnippet === '' && isset($result['response'])) {
        $rawSnippet = gusApiLookupSnippet((string)$result['response']);
    }

    gusApiLookupError($statusCode, $errorCode, $errorMessage, $requestId, $rawSnippet);
} catch (Throwable $e) {
    gusApiLookupError(500, 'UNCAUGHT_EXCEPTION', 'Nieoczekiwany błąd backendu GUS.', $gusApiRequestId, $e->getMessage());
}
