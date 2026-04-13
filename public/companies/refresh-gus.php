<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../includes/gus_service.php';
require_once __DIR__ . '/../../services/company_writer.php';
require_once __DIR__ . '/../../services/gus_refresh_queue.php';
require_once __DIR__ . '/../includes/gus_validation.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/crm_activity.php';
require_once __DIR__ . '/../includes/activity_log.php';

header('Content-Type: application/json; charset=utf-8');

$gusRefreshRequestId = uniqid('gus_refresh_', true);
$GLOBALS['gus_refresh_response_sent'] = false;

function gusRefreshLogPath(): string
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

function gusRefreshSnippet(?string $value, int $limit = 2000): string
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

function gusRefreshDrainBuffer(): string
{
    $buffer = '';
    while (ob_get_level() > 0) {
        $chunk = (string)ob_get_contents();
        ob_end_clean();
        $buffer = $chunk . $buffer;
    }
    return $buffer;
}

function gusRefreshLogFailure(string $requestId, string $code, string $message, string $rawSnippet = ''): void
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
    @file_put_contents(gusRefreshLogPath(), $line . PHP_EOL, FILE_APPEND);
}

function gusRefreshEmit(int $statusCode, array $payload): void
{
    gusRefreshDrainBuffer();
    if (!headers_sent()) {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
    }
    $GLOBALS['gus_refresh_response_sent'] = true;
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function gusRefreshError(int $statusCode, string $code, string $message, string $requestId, string $rawSnippet = ''): void
{
    if ($rawSnippet === '') {
        $rawSnippet = gusRefreshSnippet(gusRefreshDrainBuffer());
    } else {
        $rawSnippet = gusRefreshSnippet($rawSnippet);
    }

    gusRefreshLogFailure($requestId, $code, $message, $rawSnippet);

    gusRefreshEmit($statusCode, [
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

function gusRefreshSuccess(array $data, string $requestId): void
{
    gusRefreshEmit(200, [
        'ok' => true,
        'success' => true,
        'requestId' => $requestId,
        'data' => $data,
    ] + $data);
}

register_shutdown_function(static function () use ($gusRefreshRequestId): void {
    if (!empty($GLOBALS['gus_refresh_response_sent'])) {
        return;
    }

    $error = error_get_last();
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    $isFatal = is_array($error) && in_array((int)($error['type'] ?? 0), $fatalTypes, true);

    $buffer = gusRefreshDrainBuffer();
    $rawSnippet = gusRefreshSnippet($buffer);
    if ($isFatal && isset($error['message'])) {
        $rawSnippet = gusRefreshSnippet(trim($rawSnippet . ' ' . (string)$error['message']));
    }

    if (!$isFatal && $rawSnippet === '') {
        return;
    }

    $code = $isFatal ? 'GUS_REFRESH_FATAL' : 'NON_JSON_RESPONSE';
    $message = $isFatal ? 'Błąd krytyczny odświeżania GUS.' : 'Nieprawidłowa odpowiedź backendu GUS.';
    gusRefreshLogFailure($gusRefreshRequestId, $code, $message, $rawSnippet);

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
        'requestId' => $gusRefreshRequestId,
    ], JSON_UNESCAPED_UNICODE);

    $GLOBALS['gus_refresh_response_sent'] = true;
});

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    gusRefreshError(401, 'UNAUTHORIZED', 'Brak aktywnej sesji.', $gusRefreshRequestId);
}

$role = normalizeRole($currentUser);
$canRefresh = in_array($role, ['Administrator', 'Manager'], true) || isSuperAdmin();
$canForce = $canRefresh;
$forceRefresh = !empty($_POST['force']) && $canForce;

if (!$canRefresh) {
    gusRefreshError(403, 'FORBIDDEN', 'Brak uprawnień do odświeżenia danych z GUS.', $gusRefreshRequestId);
}

$clientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($clientId <= 0) {
    gusRefreshError(400, 'VALIDATION_CLIENT_ID', 'Brak identyfikatora klienta.', $gusRefreshRequestId);
}

if (!canAccessCrmObject('klient', $clientId, $currentUser)) {
    gusRefreshError(403, 'FORBIDDEN', 'Brak dostępu do klienta.', $gusRefreshRequestId);
}

$token = $_POST['csrf_token'] ?? '';
if (!isCsrfTokenValid($token)) {
    gusRefreshError(400, 'CSRF_INVALID', 'Niepoprawny token CSRF.', $gusRefreshRequestId);
}

try {
    ensureSystemConfigColumns($pdo);
    ensureClientLeadColumns($pdo);
    $stmt = $pdo->prepare('SELECT * FROM klienci WHERE id = :id');
    $stmt->execute([':id' => $clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $client = null;
}

if (!$client) {
    gusRefreshError(404, 'CLIENT_NOT_FOUND', 'Nie znaleziono klienta.', $gusRefreshRequestId);
}

$configRow = $pdo->query("SELECT gus_enabled, gus_cache_ttl_days FROM konfiguracja_systemu WHERE id = 1")
    ->fetch(PDO::FETCH_ASSOC) ?: [];
if (empty($configRow['gus_enabled'])) {
    gusRefreshError(503, 'GUS_DISABLED', 'Integracja GUS jest wyłączona.', $gusRefreshRequestId);
}

$nip = isset($client['nip']) ? normalizeNip((string)$client['nip']) : '';
$regon = isset($client['regon']) ? normalizeRegon((string)$client['regon']) : '';

ensureCompaniesTable($pdo);
$companyId = 0;
$companyRow = null;
if ($nip !== '') {
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE nip = :nip LIMIT 1');
    $stmt->execute([':nip' => $nip]);
    $companyRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} elseif ($regon !== '') {
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE regon = :regon LIMIT 1');
    $stmt->execute([':regon' => $regon]);
    $companyRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}
if ($companyRow) {
    $companyId = (int)$companyRow['id'];
}

if ($companyId === 0) {
    $street = isset($client['adres']) ? trim((string)$client['adres']) : null;
    $city = isset($client['miejscowosc']) ? trim((string)$client['miejscowosc']) : null;
    if ($street === '') {
        $street = null;
    }
    if ($city === '') {
        $city = null;
    }

    $stmt = $pdo->prepare('INSERT INTO companies (nip, regon, name_full, street, city, wojewodztwo, country)
        VALUES (:nip, :regon, :name_full, :street, :city, :wojewodztwo, :country)');
    $stmt->execute([
        ':nip' => $nip !== '' ? $nip : null,
        ':regon' => $regon !== '' ? $regon : null,
        ':name_full' => $client['nazwa_firmy'] ?? null,
        ':street' => $street,
        ':city' => $city,
        ':wojewodztwo' => $client['wojewodztwo'] ?? null,
        ':country' => 'PL',
    ]);
    $companyId = (int)$pdo->lastInsertId();
}

// Per-company lock to prevent concurrent refreshes
$lockDir = dirname(__DIR__, 2) . '/storage/locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockPath = rtrim($lockDir, '/\\') . '/gus_company_' . $companyId . '.lock';
$lockHandle = @fopen($lockPath, 'c');
if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    if ($lockHandle) {
        @fclose($lockHandle);
    }
    gusRefreshError(409, 'GUS_REFRESH_LOCKED', 'Odświeżanie GUS jest już w toku dla tej firmy.', $gusRefreshRequestId);
}
register_shutdown_function(static function () use ($lockHandle): void {
    if (is_resource($lockHandle)) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
});

$cacheHours = max(1, gusEnvInt('GUS_COMPANY_CACHE_HOURS', 24));
$cacheCutoff = (new DateTime())->modify('-' . $cacheHours . ' hours')->format('Y-m-d H:i:s');

if ($companyId > 0 && !$forceRefresh) {
    $latestSnapshot = null;
    try {
        ensureGusSnapshotsTable($pdo);
        $stmt = $pdo->prepare('SELECT created_at, raw_parsed FROM gus_snapshots WHERE company_id = :company_id AND ok = 1 AND raw_parsed IS NOT NULL ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([':company_id' => $companyId]);
        $latestSnapshot = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $latestSnapshot = null;
    }

    if (!$latestSnapshot && ($nip !== '' || $regon !== '')) {
        try {
            $identifiers = [];
            if ($nip !== '') {
                $identifiers[] = $nip;
            }
            if ($regon !== '') {
                $identifiers[] = $regon;
            }
            $identifiers = array_values(array_unique($identifiers));
            if ($identifiers) {
                $placeholders = implode(',', array_fill(0, count($identifiers), '?'));
                $stmt = $pdo->prepare("SELECT created_at, raw_parsed FROM gus_snapshots WHERE request_value IN ($placeholders) AND ok = 1 AND raw_parsed IS NOT NULL ORDER BY created_at DESC LIMIT 1");
                $stmt->execute($identifiers);
                $latestSnapshot = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            }
        } catch (Throwable $e) {
            $latestSnapshot = null;
        }
    }

    if ($latestSnapshot && !empty($latestSnapshot['created_at']) && $latestSnapshot['created_at'] >= $cacheCutoff) {
        $rawParsed = !empty($latestSnapshot['raw_parsed']) ? json_decode($latestSnapshot['raw_parsed'], true) : null;
        if (is_array($rawParsed)) {
            try {
                ensureActivityLogTable($pdo);
                $msg = sprintf(
                    'gus_refresh cache ok company_id=%d updated=0 locked=0',
                    $companyId
                );
                logActivity($pdo, 'klient', $clientId, 'gus_refresh', $msg, (int)$currentUser['id']);
            } catch (Throwable $e) {
                error_log('gus_refresh: audit log failed: ' . $e->getMessage());
            }
            gusRefreshSuccess([
                'ok' => true,
                'message' => 'Dane są aktualne (cache).',
                'updated_fields' => [],
                'skipped_locked_fields' => [],
                'diff' => [],
                'source' => 'CACHE',
                'last_refresh_at' => $latestSnapshot['created_at'],
            ], $gusRefreshRequestId);
        }
    }
}

$config = [
    'cache_ttl_days' => (int)($configRow['gus_cache_ttl_days'] ?? 30),
];

if ($nip === '' && $regon === '') {
    try {
        ensureActivityLogTable($pdo);
        $msg = sprintf('gus_refresh error company_id=%d error=missing_identifier', $companyId);
        logActivity($pdo, 'klient', $clientId, 'gus_refresh', $msg, (int)$currentUser['id']);
    } catch (Throwable $e) {
        error_log('gus_refresh: audit log failed: ' . $e->getMessage());
    }
    gusRefreshError(400, 'VALIDATION_IDENTIFIER_MISSING', 'Brak NIP/REGON do odświeżenia danych.', $gusRefreshRequestId);
}

$enqueue = gusQueueSmartEnqueue($pdo, $companyId, 1, 'manual_click', true);
$enqueuedJobId = (int)($enqueue['job_id'] ?? $enqueue['id'] ?? 0);
try {
    ensureActivityLogTable($pdo);
    $msg = sprintf(
        'gus_refresh queued company_id=%d reason=manual_click job_id=%d',
        $companyId,
        $enqueuedJobId
    );
    logActivity($pdo, 'klient', $clientId, 'gus_refresh', $msg, (int)$currentUser['id']);
} catch (Throwable $e) {
    error_log('gus_refresh: audit log failed: ' . $e->getMessage());
}

gusRefreshSuccess([
    'ok' => true,
    'message' => 'Żądanie odświeżenia GUS zostało dodane do kolejki.',
    'queued' => true,
    'job_id' => $enqueuedJobId > 0 ? $enqueuedJobId : null,
], $gusRefreshRequestId);
