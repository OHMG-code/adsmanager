<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../../services/gus_refresh_queue.php';
require_once __DIR__ . '/../../services/gus_queue_metrics.php';
require_once __DIR__ . '/../../services/integration_circuit_breaker.php';
require_once __DIR__ . '/../../services/admin_audit_logger.php';
require_once __DIR__ . '/../../services/worker_lock.php';
require_once __DIR__ . '/../../config/config.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
$role = $currentUser ? normalizeRole($currentUser) : '';
if (!in_array($role, ['Administrator', 'Manager'], true) && !isSuperAdmin()) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$statusesFilter = isset($_GET['status']) ? array_filter(array_map('trim', explode(',', (string)$_GET['status']))) : ['pending','running','failed'];
$companyFilter = isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0;
$reasonFilter = isset($_GET['reason']) ? trim((string)$_GET['reason']) : '';
$lockedByFilter = isset($_GET['locked_by']) ? trim((string)$_GET['locked_by']) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $token = $_POST['csrf_token'] ?? '';
    if (!isCsrfTokenValid($token)) {
        $flash = ['type' => 'danger', 'msg' => 'Invalid CSRF token'];
    } else {
        try {
            if ($action === 'requeue') {
                $id = (int)($_POST['job_id'] ?? 0);
                gusQueueRequeue($pdo, $id);
                $flash = ['type' => 'success', 'msg' => 'Job requeued'];
            } elseif ($action === 'cancel') {
                $id = (int)($_POST['job_id'] ?? 0);
                gusQueueCancel($pdo, $id);
                $flash = ['type' => 'success', 'msg' => 'Job cancelled'];
            } elseif ($action === 'enqueue') {
                $cid = (int)($_POST['company_id'] ?? 0);
                $priority = max(1, min(9, (int)($_POST['priority'] ?? 5)));
                $reason = trim((string)($_POST['reason'] ?? 'manual'));
                if ($cid > 0) {
                    gusQueueEnqueue($pdo, $cid, $reason, $priority);
                    $flash = ['type' => 'success', 'msg' => 'Enqueued company ' . $cid];
                } else {
                    $flash = ['type' => 'warning', 'msg' => 'company_id required'];
                }
            }
        } catch (Throwable $e) {
            $flash = ['type' => 'danger', 'msg' => 'Action failed: ' . $e->getMessage()];
        }
    }
}

ensureGusRefreshQueue($pdo);
$metricsSvc = new GusQueueMetrics($pdo);
$metrics = $metricsSvc->summary();
$breaker = getCircuitState($pdo, 'gus');
$isDegraded = isDegradedNow($pdo, 'gus');
$csrfToken = getCsrfToken();
$auditLast = adminAuditLast($pdo, 20);
$lockSvc = new WorkerLock($pdo);
$workerLock = $lockSvc->getStatus('gus_worker');

// summary
$stmt = $pdo->query("SELECT status, COUNT(*) cnt FROM gus_refresh_queue GROUP BY status");
$rows = $stmt ? $stmt->fetchAll(PDO::FETCH_KEY_PAIR) : [];
$summary = [
    'pending' => (int)($rows['pending'] ?? 0),
    'running' => (int)($rows['running'] ?? 0),
    'failed' => (int)($rows['failed'] ?? 0),
    'done' => (int)($rows['done'] ?? 0),
    'cancelled' => (int)($rows['cancelled'] ?? 0),
];

// list
$conditions = [];
$params = [];
if ($statusesFilter) {
    $placeholders = implode(',', array_fill(0, count($statusesFilter), '?'));
    $conditions[] = 'status IN (' . $placeholders . ')';
    $params = array_merge($params, $statusesFilter);
}
if ($companyFilter > 0) {
    $conditions[] = 'company_id = ?';
    $params[] = $companyFilter;
}
if ($reasonFilter !== '') {
    $conditions[] = 'reason = ?';
    $params[] = $reasonFilter;
}
if ($lockedByFilter !== '') {
    $conditions[] = 'locked_by = ?';
    $params[] = $lockedByFilter;
}
$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
$sql = "SELECT * FROM gus_refresh_queue $where ORDER BY status ASC, priority ASC, next_run_at ASC, id ASC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$list = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

include __DIR__ . '/../includes/header.php';
?>
<div class="container mt-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Administracja techniczna</p>
            <h1 class="h4 mb-1">Kolejka GUS</h1>
            <p class="text-muted mb-0">Techniczne zarządzanie kolejką odświeżeń, workerami i retry dla integracji GUS.</p>
        </div>
        <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/admin/index.php">Panel narzędzi</a>
    </div>
    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Integration State</span>
            <?php if (in_array($role, ['Administrator','Manager'], true) || isSuperAdmin()): ?>
            <form method="post" action="handlers/gus_clear_degraded.php" class="mb-0">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <button class="btn btn-sm btn-outline-secondary" type="submit">Clear degraded</button>
            </form>
            <?php endif; ?>
        </div>
        <div class="card-body small">
            <div>State: <strong><?= htmlspecialchars($breaker['state'] ?? 'ok') ?></strong></div>
            <?php if (($breaker['state'] ?? 'ok') === 'degraded'): ?>
                <div>Until: <?= htmlspecialchars($breaker['degraded_until'] ?? '—') ?></div>
                <div>Reason: <?= htmlspecialchars($breaker['reason'] ?? '') ?></div>
                <div>Details: <code><?= htmlspecialchars(mb_strimwidth((string)($breaker['details'] ?? ''), 0, 200, '...')) ?></code></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-header">Worker</div>
        <div class="card-body small">
            <?php
            $locked = !empty($workerLock['locked']);
            $hb = $workerLock['heartbeat_at'] ?? null;
            $exp = $workerLock['expires_at'] ?? null;
            $ageSec = $hb ? max(0, time() - strtotime((string)$hb)) : null;
            $expSec = $exp ? max(0, strtotime((string)$exp) - time()) : null;
            ?>
            <div>Status: <strong><?= $locked ? 'ACTIVE' : 'IDLE' ?></strong></div>
            <div>Locked by: <?= htmlspecialchars($workerLock['locked_by'] ?? '—') ?></div>
            <div>Locked at: <?= htmlspecialchars($workerLock['locked_at'] ?? '—') ?></div>
            <div>Heartbeat: <?= htmlspecialchars($hb ?? '—') ?> <?= $ageSec !== null ? '(' . (int)floor($ageSec/60) . ' min ago)' : '' ?></div>
            <div>Expires in: <?= $expSec !== null ? (int)ceil($expSec/60) . ' min' : '—' ?></div>
        </div>
    </div>
    <div class="card mb-3">
        <div class="card-header">Health</div>
        <div class="card-body small">
            <div class="row g-3">
                <div class="col-sm-3">Pending: <strong><?= (int)$metrics['pending_count'] ?></strong></div>
                <div class="col-sm-3">Running: <strong><?= (int)$metrics['running_count'] ?></strong></div>
                <div class="col-sm-3">Failed: <strong><?= (int)$metrics['failed_count'] ?></strong></div>
                <div class="col-sm-3">Stuck: <strong><?= (int)$metrics['stuck_count'] ?></strong></div>
                <div class="col-sm-3">Oldest pending age: <strong><?= $metrics['oldest_pending_age_sec'] !== null ? (int)$metrics['oldest_pending_age_sec'] . 's' : '—' ?></strong></div>
                <div class="col-sm-3">Done last 24h: <strong><?= (int)$metrics['done_last_24h'] ?></strong></div>
                <div class="col-sm-3">Fail last 1h / OK last 1h: <strong><?= (int)$metrics['fail_last_1h'] ?></strong> / <strong><?= (int)$metrics['ok_last_1h'] ?></strong></div>
                <div class="col-sm-3">Last done at: <strong><?= htmlspecialchars($metrics['last_done_at'] ?? '—') ?></strong></div>
            </div>
        </div>
    </div>
    <p>Pending: <?= (int)$summary['pending'] ?> | Running: <?= (int)$summary['running'] ?> | Failed: <?= (int)$summary['failed'] ?> | Done: <?= (int)$summary['done'] ?> | Cancelled: <?= (int)$summary['cancelled'] ?></p>

    <form class="row g-2 mb-3" method="get">
        <div class="col-sm-3"><input type="text" name="status" value="<?= htmlspecialchars(implode(',', $statusesFilter)) ?>" class="form-control" placeholder="statuses"></div>
        <div class="col-sm-2"><input type="number" name="company_id" value="<?= $companyFilter ?>" class="form-control" placeholder="company_id"></div>
        <div class="col-sm-2"><input type="text" name="reason" value="<?= htmlspecialchars($reasonFilter) ?>" class="form-control" placeholder="reason"></div>
        <div class="col-sm-2"><input type="text" name="locked_by" value="<?= htmlspecialchars($lockedByFilter) ?>" class="form-control" placeholder="locked_by"></div>
        <div class="col-sm-2"><button class="btn btn-primary w-100" type="submit">Filtruj</button></div>
    </form>

    <form class="row g-2 mb-4" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="action" value="enqueue">
        <div class="col-sm-2"><input type="number" name="company_id" class="form-control" placeholder="company_id" required></div>
        <div class="col-sm-2"><input type="text" name="reason" class="form-control" placeholder="reason" value="manual"></div>
        <div class="col-sm-2"><input type="number" name="priority" class="form-control" value="5" min="1" max="9"></div>
        <div class="col-sm-2"><button class="btn btn-success w-100" type="submit">Enqueue</button></div>
    </form>

    <form id="jobs_form" method="post" action="handlers/gus_requeue_bulk.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="scope" value="selected">
    </form>

    <div class="d-flex flex-wrap gap-2 mb-2">
        <button class="btn btn-sm btn-outline-primary" form="jobs_form" formaction="handlers/gus_requeue_bulk.php">Requeue zaznaczone</button>
        <button class="btn btn-sm btn-outline-danger" form="jobs_form" formaction="handlers/gus_cancel_bulk.php">Cancel zaznaczone</button>
    </div>

    <form id="filter_action_form" class="row g-2 mb-3" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="scope" value="filter">
        <input type="hidden" name="status" value="<?= htmlspecialchars(implode(',', $statusesFilter)) ?>">
        <input type="hidden" name="company_id" value="<?= $companyFilter ?>">
        <input type="hidden" name="priority" value="">
        <input type="hidden" name="error_class" value="">
        <input type="hidden" name="reason" value="<?= htmlspecialchars($reasonFilter) ?>">
        <input type="hidden" name="locked_by" value="<?= htmlspecialchars($lockedByFilter) ?>">
        <div class="col-sm-3"><input type="text" name="confirm" class="form-control" placeholder="CONFIRM dla akcji filtrowej" <?= $isDegraded ? 'disabled' : '' ?>></div>
        <div class="col-sm-3 text-muted small align-self-center">Limit 200 rekordów. Wymagane CONFIRM.</div>
        <div class="col-sm-6 d-flex gap-2">
            <button class="btn btn-sm btn-outline-primary" formaction="handlers/gus_requeue_bulk.php" <?= $isDegraded ? 'disabled' : '' ?>>Requeue wynik filtra</button>
            <button class="btn btn-sm btn-outline-danger" formaction="handlers/gus_cancel_bulk.php" <?= $isDegraded ? 'disabled' : '' ?>>Cancel wynik filtra</button>
            <?php if ($isDegraded): ?>
                <span class="text-warning small align-self-center">Degraded: akcje filtrowe wyłączone</span>
            <?php endif; ?>
        </div>
    </form>

    <table class="table table-sm table-striped">
        <thead>
            <tr>
                <th></th><th>ID</th><th>Company</th><th>Status</th><th>Prio</th><th>Reason</th><th>Attempts</th><th>Next run</th><th>Locked</th><th>Locked by</th><th>Error</th><th>Updated</th><th>Akcje</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($list as $row): ?>
                <tr>
                    <td><input type="checkbox" name="job_ids[]" value="<?= (int)$row['id'] ?>" form="jobs_form"></td>
                    <td><?= (int)$row['id'] ?></td>
                    <td><?= (int)$row['company_id'] ?></td>
                    <td><?= htmlspecialchars($row['status']) ?></td>
                    <td><?= (int)$row['priority'] ?></td>
                    <td><?= htmlspecialchars($row['reason'] ?? '') ?></td>
                    <td><?= (int)$row['attempts'] ?>/<?= (int)$row['max_attempts'] ?></td>
                    <td><?= htmlspecialchars($row['next_run_at'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['locked_at'] ?? '') ?></td>
                    <td><?= htmlspecialchars($row['locked_by'] ?? '') ?></td>
                    <td>
                        <?php
                        $errorClass = trim((string)($row['error_class'] ?? ''));
                        $errorCode = trim((string)($row['last_error_code'] ?? ''));
                        $errorMessage = trim((string)($row['last_error_message'] ?? ''));
                        $errorLine = '';
                        if ($errorClass !== '') {
                            $errorLine .= '[' . $errorClass . '] ';
                        }
                        if ($errorCode !== '') {
                            $errorLine .= $errorCode . ' ';
                        }
                        $errorLine .= $errorMessage;
                        echo htmlspecialchars(mb_strimwidth(trim($errorLine), 0, 120, '...'));
                        ?>
                    </td>
                    <td><?= htmlspecialchars($row['updated_at'] ?? '') ?></td>
                    <td>
                        <form method="post" style="display:inline-block">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="job_id" value="<?= (int)$row['id'] ?>">
                            <button name="action" value="requeue" class="btn btn-sm btn-outline-primary">Requeue</button>
                        </form>
                        <form method="post" style="display:inline-block">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="job_id" value="<?= (int)$row['id'] ?>">
                            <button name="action" value="cancel" class="btn btn-sm btn-outline-danger">Cancel</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="card mt-4">
        <div class="card-header">Ostatnie akcje admin</div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>czas</th><th>user</th><th>akcja</th><th>scope</th><th>count</th><th>filters</th></tr></thead>
                <tbody>
                <?php foreach ($auditLast as $row): ?>
                    <tr>
                        <td><?= htmlspecialchars($row['created_at'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['username'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['action'] ?? '') ?></td>
                        <td><?= htmlspecialchars($row['scope'] ?? '') ?></td>
                        <td><?= (int)($row['affected_count'] ?? 0) ?></td>
                        <td><code><?= htmlspecialchars(mb_strimwidth((string)($row['filters_json'] ?: $row['ids_json'] ?: ''), 0, 80, '...')) ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
