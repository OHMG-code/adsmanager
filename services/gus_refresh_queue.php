<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/db_schema.php';

function ensureGusRefreshQueue(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS gus_refresh_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_id INT NOT NULL,
        priority TINYINT NOT NULL DEFAULT 5,
        reason VARCHAR(50) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        attempts INT NOT NULL DEFAULT 0,
        max_attempts INT NOT NULL DEFAULT 3,
        next_run_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        locked_at DATETIME NULL,
        locked_by VARCHAR(50) NULL,
        error_class VARCHAR(20) NULL,
        last_error_code VARCHAR(50) NULL,
        last_error_message TEXT NULL,
        error_code VARCHAR(50) NULL,
        error_message TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        finished_at DATETIME NULL
    );");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_queue_status_next ON gus_refresh_queue(status, next_run_at, priority)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_queue_company ON gus_refresh_queue(company_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_queue_company_status ON gus_refresh_queue(company_id, status)');
}

function gusQueueEnqueue(PDO $pdo, int $companyId, ?string $reason = null, int $priority = 5): array
{
    ensureGusRefreshQueue($pdo);
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM gus_refresh_queue WHERE company_id = :cid AND status IN (\'pending\',\'running\') ORDER BY priority ASC LIMIT 1');
        $stmt->execute([':cid' => $companyId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        if ($existing) {
            $newPriority = min($priority, (int)$existing['priority']);
            $newNext = min(strtotime($now), strtotime((string)$existing['next_run_at']));
            $stmt = $pdo->prepare('UPDATE gus_refresh_queue SET priority = :p, next_run_at = :nr WHERE id = :id');
            $stmt->execute([
                ':p' => $newPriority,
                ':nr' => date('Y-m-d H:i:s', $newNext),
                ':id' => (int)$existing['id'],
            ]);
            $pdo->commit();
            return ['id' => (int)$existing['id'], 'existing' => true];
        }
        $stmt = $pdo->prepare('INSERT INTO gus_refresh_queue (company_id, priority, reason, next_run_at) VALUES (:cid, :p, :r, :nr)');
        $stmt->execute([
            ':cid' => $companyId,
            ':p' => $priority,
            ':r' => $reason,
            ':nr' => $now,
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return ['id' => $id, 'existing' => false];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function enqueueForCompanies(PDO $pdo, array $companyIds, int $priority, string $reason, ?int $actorUserId = null): array
{
    ensureGusRefreshQueue($pdo);
    $created = 0;
    $updated = 0;
    $skipped = 0;
    $idsCreated = [];
    $idsUpdated = [];

    $ids = array_values(array_unique(array_filter(array_map('intval', $companyIds), static fn($v) => $v > 0)));
    if (!$ids) {
        return compact('created', 'updated', 'skipped', 'idsCreated', 'idsUpdated');
    }

    foreach ($ids as $cid) {
        $res = gusQueueSmartEnqueue($pdo, $cid, $priority, $reason, false);
        if ($res['created']) {
            $created++;
            if (count($idsCreated) < 20) {
                $idsCreated[] = $res['job_id'];
            }
        } elseif ($res['updated']) {
            $updated++;
            if (count($idsUpdated) < 20) {
                $idsUpdated[] = $res['job_id'];
            }
        } else {
            $skipped++;
        }
    }

    return [
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
        'ids_created' => $idsCreated,
        'ids_updated' => $idsUpdated,
    ];
}

function gusQueueSmartEnqueue(PDO $pdo, int $companyId, int $priority, string $reason, bool $forceNow = false): array
{
    ensureGusRefreshQueue($pdo);
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM gus_refresh_queue WHERE company_id = :cid AND status IN ('pending','running') ORDER BY priority ASC LIMIT 1");
        $stmt->execute([':cid' => $companyId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $stmt->closeCursor();
        if ($existing) {
            $newPriority = min($priority, (int)$existing['priority']);
            $newNext = $existing['next_run_at'];
            if (($priority === 1 || $forceNow) && strtotime((string)$existing['next_run_at']) > time()) {
                $newNext = $now;
            }
            $upd = $pdo->prepare("UPDATE gus_refresh_queue SET priority = :p, next_run_at = :nr, updated_at = CURRENT_TIMESTAMP WHERE id = :id");
            $upd->execute([':p' => $newPriority, ':nr' => $newNext, ':id' => (int)$existing['id']]);
            $pdo->commit();
            return ['created' => false, 'updated' => true, 'job_id' => (int)$existing['id'], 'bumped' => true];
        }

        // No active job; check last failed/backoff
        if ($priority > 1) {
            $lastFailed = $pdo->prepare("SELECT next_run_at FROM gus_refresh_queue WHERE company_id = :cid AND status = 'failed' ORDER BY updated_at DESC LIMIT 1");
            $lastFailed->execute([':cid' => $companyId]);
            $failedRow = $lastFailed->fetch(PDO::FETCH_ASSOC) ?: null;
            $lastFailed->closeCursor();
            if ($failedRow && !empty($failedRow['next_run_at']) && strtotime((string)$failedRow['next_run_at']) > time()) {
                $pdo->commit();
                return ['created' => false, 'updated' => false, 'skipped' => true, 'job_id' => null];
            }
        }

        // No active job; insert new
        $insert = $pdo->prepare("INSERT INTO gus_refresh_queue (company_id, priority, reason, status, next_run_at, created_at, updated_at, attempts, max_attempts) VALUES (:cid, :p, :r, 'pending', :nr, :ca, :ua, 0, 3)");
        $insert->execute([
            ':cid' => $companyId,
            ':p' => $priority,
            ':r' => $reason,
            ':nr' => $priority === 1 || $forceNow ? $now : $now,
            ':ca' => $now,
            ':ua' => $now,
        ]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return ['created' => true, 'updated' => false, 'job_id' => $id];
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function gusQueueClaimBatch(PDO $pdo, int $limit, string $workerId): array
{
    ensureGusRefreshQueue($pdo);
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM gus_refresh_queue WHERE status = \'pending\' AND next_run_at <= :now ORDER BY priority ASC, next_run_at ASC, id ASC LIMIT :lim');
        $stmt->bindValue(':now', $now);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        $stmt->closeCursor();
        if (!$ids) {
            $pdo->commit();
            return [];
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("UPDATE gus_refresh_queue SET status='running', locked_at=?, locked_by=? WHERE id IN ($in)");
        $stmt->execute(array_merge([$now, $workerId], $ids));
        $stmt = $pdo->prepare('SELECT * FROM gus_refresh_queue WHERE id IN (' . $in . ')');
        $stmt->execute($ids);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $stmt->closeCursor();
        $pdo->commit();
        return $jobs;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function gusQueueMarkDone(PDO $pdo, int $jobId): void
{
    ensureGusRefreshQueue($pdo);
    $stmt = $pdo->prepare("UPDATE gus_refresh_queue SET status='done', locked_at=NULL, locked_by=NULL, updated_at=CURRENT_TIMESTAMP WHERE id=:id");
    $stmt->execute([':id' => $jobId]);
    $pdo->prepare("UPDATE gus_refresh_queue SET finished_at = CURRENT_TIMESTAMP WHERE id = :id")->execute([':id' => $jobId]);
}

function gusQueueMarkFailed(PDO $pdo, int $jobId, string $code, string $message, bool $retryable, int $attempts, int $maxAttempts, string $errorClass = 'unknown', int $backoffSeconds = 0): void
{
    ensureGusRefreshQueue($pdo);
    $attempts++;
    $now = time();
    $backoff = $backoffSeconds > 0 ? $backoffSeconds : 0;
    if ($retryable && $attempts < $maxAttempts) {
        if ($backoff === 0) {
            $backoff = match ($attempts) {
                1 => 300,
                2 => 1800,
                default => 7200,
            };
        }
        $next = date('Y-m-d H:i:s', $now + $backoff);
        $stmt = $pdo->prepare("UPDATE gus_refresh_queue SET attempts=:a, status='pending', next_run_at=:next, error_class=:ec, last_error_code=:c, last_error_message=:m, error_code=:c, error_message=:m, locked_at=NULL, locked_by=NULL WHERE id=:id");
        $stmt->execute([':a' => $attempts, ':next' => $next, ':ec' => $errorClass, ':c' => $code, ':m' => $message, ':id' => $jobId]);
        return;
    }
    $stmt = $pdo->prepare("UPDATE gus_refresh_queue SET attempts=:a, status='failed', error_class=:ec, last_error_code=:c, last_error_message=:m, error_code=:c, error_message=:m, locked_at=NULL, locked_by=NULL, finished_at=CURRENT_TIMESTAMP WHERE id=:id");
    $stmt->execute([':a' => $attempts, ':ec' => $errorClass, ':c' => $code, ':m' => $message, ':id' => $jobId]);
}

function gusQueueReleaseStuck(PDO $pdo, int $minutes, ?string $workerId = null): int
{
    ensureGusRefreshQueue($pdo);
    $cutoffTs = time() - ($minutes * 60);
    $cutoff = date('Y-m-d H:i:s', $cutoffTs);
    $nextRun = date('Y-m-d H:i:s', time() + 60);
    $sql = "UPDATE gus_refresh_queue
        SET status='pending',
            locked_at=NULL,
            locked_by=NULL,
            next_run_at=:next,
            last_error_code='STUCK_UNLOCK',
            last_error_message='Auto-released after timeout'
        WHERE status='running' AND locked_at IS NOT NULL AND locked_at < :cutoff";
    if ($workerId !== null) {
        $sql .= " AND locked_by = :worker";
    }
    $stmt = $pdo->prepare($sql);
    $params = [':next' => $nextRun, ':cutoff' => $cutoff];
    if ($workerId !== null) {
        $params[':worker'] = $workerId;
    }
    $stmt->execute($params);
    return (int)$stmt->rowCount();
}

function gusQueueRequeue(PDO $pdo, int $jobId): void
{
    ensureGusRefreshQueue($pdo);
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("UPDATE gus_refresh_queue SET status='pending', attempts=0, locked_at=NULL, locked_by=NULL, next_run_at=:now, finished_at=NULL WHERE id=:id");
    $stmt->execute([':now' => $now, ':id' => $jobId]);
}

function gusQueueCancel(PDO $pdo, int $jobId): void
{
    ensureGusRefreshQueue($pdo);
    $stmt = $pdo->prepare("UPDATE gus_refresh_queue SET status='cancelled', locked_at=NULL, locked_by=NULL WHERE id=:id");
    $stmt->execute([':id' => $jobId]);
}

function requeueByIds(PDO $pdo, array $jobIds, int $limit = 200): array
{
    ensureGusRefreshQueue($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $jobIds), static fn($v) => $v > 0)));
    $ids = array_slice($ids, 0, $limit);
    if (!$ids) {
        return ['ok' => true, 'affected' => 0, 'skipped' => 0];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE gus_refresh_queue
            SET status='pending', next_run_at=CURRENT_TIMESTAMP, locked_at=NULL, locked_by=NULL, finished_at=NULL, updated_at=CURRENT_TIMESTAMP
            WHERE id IN ($in) AND status IN ('failed','cancelled','done')";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    return ['ok' => true, 'affected' => $stmt->rowCount(), 'skipped' => count($ids) - $stmt->rowCount()];
}

function cancelByIds(PDO $pdo, array $jobIds, int $limit = 200): array
{
    ensureGusRefreshQueue($pdo);
    $ids = array_values(array_unique(array_filter(array_map('intval', $jobIds), static fn($v) => $v > 0)));
    $ids = array_slice($ids, 0, $limit);
    if (!$ids) {
        return ['ok' => true, 'affected' => 0, 'skipped' => 0];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE gus_refresh_queue
            SET status='cancelled', finished_at=CURRENT_TIMESTAMP, error_class='cancelled', error_message='Cancelled by admin', locked_at=NULL, locked_by=NULL
            WHERE id IN ($in) AND status='pending'";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
    return ['ok' => true, 'affected' => $stmt->rowCount(), 'skipped' => count($ids) - $stmt->rowCount()];
}

function findJobIdsByFilter(PDO $pdo, array $filters, int $limit = 200): array
{
    ensureGusRefreshQueue($pdo);
    $conditions = [];
    $params = [];
    if (!empty($filters['status'])) {
        $statuses = array_filter(array_map('trim', explode(',', (string)$filters['status'])));
        if ($statuses) {
            $placeholders = [];
            foreach ($statuses as $i => $status) {
                $ph = ':s' . $i;
                $placeholders[] = $ph;
                $params[$ph] = $status;
            }
            $conditions[] = 'status IN (' . implode(',', $placeholders) . ')';
        }
    }
    if (!empty($filters['priority'])) {
        $conditions[] = 'priority = :priority';
        $params[':priority'] = (int)$filters['priority'];
    }
    if (!empty($filters['error_class'])) {
        $conditions[] = 'error_class = :error_class';
        $params[':error_class'] = $filters['error_class'];
    }
    if (!empty($filters['company_id'])) {
        $conditions[] = 'company_id = :company_id';
        $params[':company_id'] = (int)$filters['company_id'];
    }
    if (!empty($filters['reason'])) {
        $conditions[] = 'reason = :reason';
        $params[':reason'] = $filters['reason'];
    }
    if (!empty($filters['locked_by'])) {
        $conditions[] = 'locked_by = :locked_by';
        $params[':locked_by'] = $filters['locked_by'];
    }
    $where = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';
    $sql = "SELECT id FROM gus_refresh_queue $where ORDER BY id ASC LIMIT :limit";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    $stmt->closeCursor();
    return array_map('intval', $ids);
}

function gusBulkConfirmOk(string $scope, string $confirm): bool
{
    return $scope !== 'filter' || $confirm === 'CONFIRM';
}

function gusQueueCleanupDone(PDO $pdo, int $days): int
{
    ensureGusRefreshQueue($pdo);
    $cutoff = date('Y-m-d H:i:s', time() - ($days * 86400));
    $stmt = $pdo->prepare("DELETE FROM gus_refresh_queue WHERE status='done' AND updated_at < :cutoff");
    $stmt->execute([':cutoff' => $cutoff]);
    return (int)$stmt->rowCount();
}
