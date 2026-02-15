<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../includes/gus_service.php';
require_once __DIR__ . '/../includes/gus_company_mapper.php';
require_once __DIR__ . '/../../services/company_writer.php';
require_once __DIR__ . '/../../services/gus_refresh_queue.php';
require_once __DIR__ . '/../includes/gus_validation.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/crm_activity.php';
require_once __DIR__ . '/../includes/activity_log.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Brak aktywnej sesji.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = normalizeRole($currentUser);
$canRefresh = in_array($role, ['Administrator', 'Manager'], true) || isSuperAdmin();
$canForce = $canRefresh;
$forceRefresh = !empty($_POST['force']) && $canForce;

if (!$canRefresh) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak uprawnień do odświeżenia danych z GUS.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Brak identyfikatora klienta.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!canAccessCrmObject('klient', $clientId, $currentUser)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak dostępu do klienta.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!isCsrfTokenValid($token)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Niepoprawny token CSRF.'], JSON_UNESCAPED_UNICODE);
    exit;
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
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Nie znaleziono klienta.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$configRow = $pdo->query("SELECT gus_enabled, gus_cache_ttl_days FROM konfiguracja_systemu WHERE id = 1")
    ->fetch(PDO::FETCH_ASSOC) ?: [];
if (empty($configRow['gus_enabled'])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Integracja GUS jest wyłączona.'], JSON_UNESCAPED_UNICODE);
    exit;
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
    http_response_code(409);
    echo json_encode([
        'ok' => false,
        'error' => 'Odświeżanie GUS jest już w toku dla tej firmy.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
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
            echo json_encode([
                'ok' => true,
                'message' => 'Dane są aktualne (cache).',
                'updated_fields' => [],
                'skipped_locked_fields' => [],
                'diff' => [],
                'source' => 'CACHE',
                'last_refresh_at' => $latestSnapshot['created_at'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
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
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Brak NIP/REGON do odświeżenia danych.'], JSON_UNESCAPED_UNICODE);
    exit;
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

echo json_encode([
    'ok' => true,
    'message' => 'Żądanie odświeżenia GUS zostało dodane do kolejki.',
    'queued' => true,
    'job_id' => $enqueuedJobId > 0 ? $enqueuedJobId : null,
], JSON_UNESCAPED_UNICODE);
