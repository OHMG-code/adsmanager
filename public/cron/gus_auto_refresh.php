<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../includes/gus_service.php';
require_once __DIR__ . '/../includes/gus_company_mapper.php';
require_once __DIR__ . '/../../services/company_writer.php';
require_once __DIR__ . '/../../services/gus_refresh_queue.php';
require_once __DIR__ . '/../../services/gus_rate_limiter.php';
require_once __DIR__ . '/../../services/gus_queue_metrics.php';
require_once __DIR__ . '/../../services/gus_error_classifier.php';
require_once __DIR__ . '/../../services/gus_request_preflight.php';
require_once __DIR__ . '/../../services/integration_alert_throttle.php';
require_once __DIR__ . '/../../services/integration_circuit_breaker.php';
require_once __DIR__ . '/../../services/worker_lock.php';
require_once __DIR__ . '/../includes/gus_validation.php';

function gusCronLog(string $message): void
{
    $logDir = __DIR__ . '/../../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/gus_auto_refresh.log';
    $line = sprintf("[%s] %s\n", date('c'), $message);
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function gusWorkerTextLower(string $value): string
{
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($value, 'UTF-8');
    }
    return strtolower($value);
}

function gusWorkerFindCorrelationId(PDO $pdo, int $companyId, string $requestType, string $requestValue): ?string
{
    try {
        $stmt = $pdo->prepare('SELECT correlation_id FROM gus_snapshots WHERE company_id = :company_id AND request_type = :request_type AND request_value = :request_value ORDER BY id DESC LIMIT 1');
        $stmt->execute([
            ':company_id' => $companyId,
            ':request_type' => strtolower($requestType),
            ':request_value' => $requestValue,
        ]);
        $val = $stmt->fetchColumn();
        if ($val === false || $val === null) {
            return null;
        }
        $trimmed = trim((string)$val);
        return $trimmed !== '' ? $trimmed : null;
    } catch (Throwable $e) {
        return null;
    }
}

$isCli = (PHP_SAPI === 'cli');
$tokenEnv = getenv('GUS_CRON_TOKEN') ?: '';
$tokenReq = $_GET['token'] ?? '';
if (!$isCli && $tokenEnv !== '' && $tokenReq !== $tokenEnv) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

ensureSystemConfigColumns($pdo);
$settings = $pdo->query('SELECT gus_enabled, gus_auto_refresh_enabled, gus_auto_refresh_batch, gus_auto_refresh_interval_days, gus_auto_refresh_backoff_minutes FROM konfiguracja_systemu WHERE id = 1')
    ->fetch(PDO::FETCH_ASSOC) ?: [];

if (empty($settings['gus_enabled'])) {
    gusCronLog('GUS integration disabled.');
    echo "GUS disabled\n";
    exit;
}
if (empty($settings['gus_auto_refresh_enabled'])) {
    gusCronLog('Auto-refresh disabled.');
    echo "Auto-refresh disabled\n";
    exit;
}

$batchSize = max(1, (int)($settings['gus_auto_refresh_batch'] ?? 20));
$intervalDays = max(1, (int)($settings['gus_auto_refresh_interval_days'] ?? 30));
$backoffMinutes = max(1, (int)($settings['gus_auto_refresh_backoff_minutes'] ?? 60));

ensureCompaniesTable($pdo);
ensureGusRefreshQueue($pdo);
ensureWorkerLocksTable($pdo);

$ownerId = gethostname() . ':' . getmypid();
$lock = new WorkerLock($pdo);
$acq = $lock->acquire('gus_worker', $ownerId, 600);
if (!$acq['ok']) {
    gusCronLog('Worker already running by ' . ($acq['lock']['locked_by'] ?? 'unknown'));
    echo "Already running\n";
    exit;
}

$lastHeartbeat = time();
register_shutdown_function(static function () use ($lock, $ownerId) {
    try {
        $lock->release('gus_worker', $ownerId);
    } catch (Throwable $e) {
        // ignore
    }
});

// unlock stuck jobs
$released = gusQueueReleaseStuck($pdo, 30);
if ($released > 0) {
    gusCronLog('Auto-released stuck jobs: ' . $released);
}

// cleanup done once per day at 03:xx
if ((int)date('G') === 3) {
    $cleaned = gusQueueCleanupDone($pdo, 14);
    if ($cleaned > 0) {
        gusCronLog('Cleaned old done jobs: ' . $cleaned);
    }
}

$cutoff = (new DateTime())->modify('-' . $intervalDays . ' days')->format('Y-m-d H:i:s');
$isDegraded = isDegradedNow($pdo, 'gus');
if (!$isDegraded) {
    $stmt = $pdo->prepare("SELECT id FROM companies
        WHERE (
            (nip IS NOT NULL AND TRIM(nip) <> '')
            OR (regon IS NOT NULL AND TRIM(regon) <> '')
            OR (krs IS NOT NULL AND TRIM(krs) <> '')
        )
        AND (last_gus_check_at IS NULL OR last_gus_check_at < :cutoff)
        AND (gus_hold_until IS NULL OR gus_hold_until <= NOW())
        LIMIT :limit");
    $stmt->bindValue(':cutoff', $cutoff);
    $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $staleCompanies = $stmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];
    foreach ($staleCompanies as $cid) {
        gusQueueSmartEnqueue($pdo, (int)$cid, 7, 'stale', false);
    }
}

$workerId = $ownerId;
$writer = new CompanyWriter($pdo);
$metricsSvc = new GusQueueMetrics($pdo);
$metrics = $metricsSvc->summary();
$breakerState = getCircuitState($pdo, 'gus');
$isDegraded = isDegradedNow($pdo, 'gus');

// alarms
$payload = json_encode($metrics, JSON_UNESCAPED_UNICODE);
if ($metrics['stuck_count'] > 0 && shouldLogAlert($pdo, 'GUS_QUEUE_STUCK', 900, $payload)) {
    gusCronLog('ALERT GUS_QUEUE_STUCK ' . $payload);
}
if ($metrics['pending_count'] > 200 && shouldLogAlert($pdo, 'GUS_QUEUE_BACKLOG', 900, $payload)) {
    gusCronLog('ALERT GUS_QUEUE_BACKLOG ' . $payload);
}
if ($metrics['fail_last_1h'] >= 20 && $metrics['fail_last_1h'] > $metrics['ok_last_1h'] && shouldLogAlert($pdo, 'GUS_QUEUE_FAIL_SPIKE', 900, $payload)) {
    gusCronLog('ALERT GUS_QUEUE_FAIL_SPIKE ' . $payload);
}
$lastDoneTooOld = empty($metrics['last_done_at']) || strtotime((string)$metrics['last_done_at']) < (time() - 3600);
if ($lastDoneTooOld && shouldLogAlert($pdo, 'GUS_QUEUE_NO_PROGRESS', 900, $payload)) {
    gusCronLog('ALERT GUS_QUEUE_NO_PROGRESS ' . $payload);
}

// enter degraded based on metrics
$enterReason = null;
if ($metrics['fail_last_1h'] >= 10 && ($metrics['failed_count'] > $metrics['pending_count'])) {
    $enterReason = 'auth_spike';
} elseif ($metrics['fail_last_1h'] >= 30) {
    $enterReason = 'transient_spike';
} elseif ($metrics['pending_count'] > 500 || ($metrics['oldest_pending_age_sec'] !== null && $metrics['oldest_pending_age_sec'] > 7200)) {
    $enterReason = 'backlog';
} elseif (($metrics['last_done_at'] === null || strtotime((string)$metrics['last_done_at']) < (time() - 5400)) && $metrics['pending_count'] > 50) {
    $enterReason = 'no_progress';
}
if (!$isDegraded && $enterReason) {
    setDegraded($pdo, 'gus', $enterReason, 30, $payload);
    gusCronLog('CIRCUIT degraded reason=' . $enterReason . ' payload=' . $payload);
    $isDegraded = true;
}

// auto clear
if ($isDegraded && $enterReason === null && $metrics['fail_last_1h'] < 5 && $metrics['pending_count'] < 200 && !empty($metrics['last_done_at']) && strtotime((string)$metrics['last_done_at']) > (time() - 1800)) {
    clearCircuit($pdo, 'gus');
    gusCronLog('CIRCUIT cleared automatically');
    $isDegraded = false;
}

$claimLimit = $isDegraded ? 200 : $batchSize;
$jobs = gusQueueClaimBatch($pdo, $claimLimit, $workerId);
$processed = 0;
$done = 0;
$failed = 0;

foreach ($jobs as $job) {
    if (time() - $lastHeartbeat >= 30) {
        $lock->heartbeat('gus_worker', $ownerId, 600);
        $lastHeartbeat = time();
    }
    $processed++;
    $jobId = (int)$job['id'];
    $companyId = (int)$job['company_id'];
    $attempts = (int)$job['attempts'];
    $maxAttempts = (int)$job['max_attempts'];
    $priority = (int)($job['priority'] ?? 5);

    $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = :id');
    $stmt->execute([':id' => $companyId]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$company) {
        gusQueueMarkFailed($pdo, $jobId, 'NO_COMPANY', 'Company not found', false, $attempts, $maxAttempts);
        $failed++;
        continue;
    }

    if ($priority > 1 && (!empty($company['gus_hold_until']) && strtotime((string)$company['gus_hold_until']) > time())) {
        gusQueueMarkDone($pdo, $jobId);
        gusCronLog('Skipped job ' . $jobId . ' company ' . $companyId . ' due to hold');
        $done++;
        continue;
    }

    if ($isDegraded && $priority > 1) {
        // park lower-priority jobs during degraded
        gusQueueMarkFailed($pdo, $jobId, 'degraded', 'Circuit degraded', true, $attempts, $maxAttempts, 'degraded', 600);
        $failed++;
        continue;
    }

    $request = gusSelectCompanyRequestIdentifier($company);
    $requestType = (string)($request['request_type'] ?? 'NONE');
    $requestValue = (string)($request['request_value'] ?? '');

    if (empty($request['ok'])) {
        $now = date('Y-m-d H:i:s');
        $invalidCode = (string)($request['error_code'] ?? 'invalid_identifier');
        $invalidMessage = (string)($request['error_message'] ?? 'missing valid identifier');

        $writer->upsertForCompanyId($companyId, [
            'gus_last_status' => 'ERR',
            'gus_last_error_code' => $invalidCode,
            'gus_last_error_message' => $invalidMessage,
            'gus_last_error_class' => 'invalid_request',
            'gus_error_class' => 'invalid_request',
            'gus_error_retryable' => false,
            'last_gus_check_at' => $now,
            'last_gus_error_at' => $now,
            'last_gus_error_message' => $invalidMessage,
            'gus_last_refresh_at' => $now,
        ], 0, 'gus');

        gusQueueMarkFailed(
            $pdo,
            $jobId,
            $invalidCode,
            $invalidMessage,
            false,
            $attempts,
            $maxAttempts,
            'invalid_request',
            0
        );
        $failed++;
        gusCronLog('Preflight rejected job ' . $jobId
            . ' company ' . $companyId
            . ' request_type=' . $requestType
            . ' request_value=' . gusMaskRequestValue($requestValue)
            . ' reason=' . $invalidMessage);
        continue;
    }

    $allowGlobal = gusRateAllow($pdo, 'global', 30, 60);
    $allowCompany = gusRateAllow($pdo, 'company:' . $companyId, 1, 900);
    if (!$allowGlobal || !$allowCompany) {
        gusQueueMarkFailed($pdo, $jobId, 'RATE_LIMIT', 'Rate limited', true, $attempts, $maxAttempts);
        gusCronLog('Rate limited job ' . $jobId . ' company ' . $companyId);
        continue;
    }

    $result = match ($requestType) {
        'NIP' => gusFetchCompanyByNip($pdo, $requestValue, ['cache_ttl_days' => 0], null, $companyId),
        'REGON' => gusFetchCompanyByRegon($pdo, $requestValue, ['cache_ttl_days' => 0], null, $companyId),
        'KRS' => gusFetchCompanyByKrs($pdo, $requestValue, ['cache_ttl_days' => 0], null, $companyId),
        default => ['success' => false, 'error' => 'Invalid request type', 'error_code' => 'invalid_request_type'],
    };
    $now = date('Y-m-d H:i:s');

    if (!empty($result['success'])) {
        $dto = GusCompanyMapper::fromGusData($result['data'] ?? []);
        $upsert = $writer->upsertForCompanyId($companyId, $dto + [
            'last_gus_check_at' => $now,
            'gus_last_refresh_at' => $now,
            'gus_last_status' => 'OK',
            'gus_last_error_code' => null,
            'gus_last_error_message' => null,
        ], 0, 'gus');
        gusQueueMarkDone($pdo, $jobId);
        $done++;
        gusCronLog('Done job ' . $jobId
            . ' company ' . $companyId
            . ' request_type=' . $requestType
            . ' request_value=' . gusMaskRequestValue($requestValue)
            . ' fields=' . implode(',', $upsert['updated_fields'] ?? []));
    } else {
        $errorMsg = $result['error'] ?? 'Unknown error';
        $errorCode = trim((string)($result['error_code'] ?? ''));
        if ($errorCode === '') {
            $lower = gusWorkerTextLower($errorMsg);
            if (str_contains($lower, 'nie znaleziono podmiotu') || str_contains($lower, 'brak danych')) {
                $errorCode = 'not_found';
            } elseif (str_contains($lower, 'nieprawidł') || str_contains($lower, 'invalid')) {
                $errorCode = 'invalid_request';
            }
        }
        if ($errorCode === '') {
            $errorCode = 'gus_fetch';
        }
        $ctx = [
            'http_code' => $result['http_code'] ?? null,
            'faultcode' => $result['faultcode'] ?? null,
            'faultstring' => $result['faultstring'] ?? null,
            'exception_message' => $result['error'] ?? null,
            'gus_error_code' => $errorCode,
            'gus_error_message' => $errorMsg,
        ];
        $class = gusClassifyError($ctx);
        $queueMessage = trim($errorMsg);
        if ($queueMessage === '') {
            $queueMessage = $class['user_message_short'];
        }
        if (function_exists('mb_strimwidth')) {
            $queueMessage = mb_strimwidth($queueMessage, 0, 180, '...');
        }

        $writer->upsertForCompanyId($companyId, [
            'gus_last_status' => 'ERR',
            'gus_last_error_code' => $ctx['gus_error_code'] ?? $errorCode,
            'gus_last_error_message' => $errorMsg,
            'gus_last_error_class' => $class['error_class'],
            'gus_error_class' => $class['error_class'],
            'gus_error_retryable' => $class['retryable'],
            'last_gus_check_at' => $now,
            'last_gus_error_at' => $now,
            'last_gus_error_message' => $errorMsg,
            'gus_last_refresh_at' => $now,
        ], 0, 'gus');

        gusQueueMarkFailed(
            $pdo,
            $jobId,
            $ctx['gus_error_code'] ?? $errorCode,
            $queueMessage,
            $class['retryable'],
            $attempts,
            $maxAttempts,
            $class['error_class'],
            $class['backoff_seconds']
        );
        $failed++;
        if ($class['error_class'] === 'not_found') {
            $correlationId = gusWorkerFindCorrelationId($pdo, $companyId, $requestType, $requestValue) ?? '-';
            gusCronLog('NotFound job ' . $jobId
                . ' company_id=' . $companyId
                . ' request_type=' . $requestType
                . ' request_value=' . gusMaskRequestValue($requestValue)
                . ' correlation_id=' . $correlationId);
        }
        gusCronLog('Failed job ' . $jobId
            . ' company ' . $companyId
            . ' request_type=' . $requestType
            . ' request_value=' . gusMaskRequestValue($requestValue)
            . ': ' . $errorMsg
            . ' class=' . $class['error_class']);
    }
}

gusCronLog("Worker {$workerId} complete. claimed={$processed}, done={$done}, failed={$failed}");
echo "OK claimed={$processed}, done={$done}, failed={$failed}\n";
$lock->release('gus_worker', $ownerId);
