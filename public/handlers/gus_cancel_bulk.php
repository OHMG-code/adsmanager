<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../../services/gus_refresh_queue.php';
require_once __DIR__ . '/../../services/admin_audit_logger.php';
require_once __DIR__ . '/../../services/integration_circuit_breaker.php';
require_once __DIR__ . '/../../config/config.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
$token = $_POST['csrf_token'] ?? '';
if (!isCsrfTokenValid($token)) {
    http_response_code(400);
    exit('Invalid CSRF');
}

$scope = $_POST['scope'] ?? 'selected';
$limit = 200;
$confirm = $_POST['confirm'] ?? '';
$filters = [
    'status' => $_POST['status'] ?? '',
    'priority' => $_POST['priority'] ?? '',
    'error_class' => $_POST['error_class'] ?? '',
    'company_id' => $_POST['company_id'] ?? '',
    'reason' => $_POST['reason'] ?? '',
    'locked_by' => $_POST['locked_by'] ?? '',
];

$isDegraded = isDegradedNow($pdo, 'gus');
if ($scope === 'filter' && $isDegraded) {
    http_response_code(403);
    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Tryb degraded: akcje filtrowe zablokowane.'];
    header('Location: ' . BASE_URL . '/admin/gus-queue.php');
    exit;
}

if (!gusBulkConfirmOk($scope, $confirm)) {
    http_response_code(400);
    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Potwierdź wpisując CONFIRM dla akcji filtrowych.'];
    header('Location: ' . BASE_URL . '/admin/gus-queue.php');
    exit;
}

$jobIds = [];
if ($scope === 'selected') {
    $jobIds = array_filter(array_map('intval', $_POST['job_ids'] ?? []), static fn($v) => $v > 0);
} else {
    $idsProbe = findJobIdsByFilter($pdo, $filters, $limit + 1);
    if (count($idsProbe) > $limit) {
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Zbyt dużo rekordów (>200). Zawęź filtr.'];
        header('Location: ' . BASE_URL . '/admin/gus-queue.php');
        exit;
    }
    $jobIds = $idsProbe;
}

if (!$jobIds) {
    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Brak rekordów do anulowania.'];
    header('Location: ' . BASE_URL . '/admin/gus-queue.php');
    exit;
}

$res = cancelByIds($pdo, $jobIds, $limit);
$_SESSION['flash'] = ['type' => 'success', 'message' => 'Cancel: affected=' . $res['affected'] . ', skipped=' . $res['skipped']];

try {
    adminAuditLog(
        $pdo,
        $scope === 'filter' ? 'cancel_filter' : 'cancel_selected',
        $scope,
        $currentUser,
        $scope === 'filter' ? $filters : [],
        $scope === 'selected' ? $jobIds : [],
        (int)$res['affected'],
        []
    );
} catch (Throwable $e) {
    error_log('audit cancel bulk failed: ' . $e->getMessage());
}

header('Location: ' . BASE_URL . '/admin/gus-queue.php');
exit;
