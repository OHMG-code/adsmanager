<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../../services/gus_refresh_queue.php';
require_once __DIR__ . '/../../services/integration_alert_throttle.php';
require_once __DIR__ . '/../../config/config.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];
$actorId = (int)($currentUser['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
$token = $_POST['csrf_token'] ?? '';
if (!isCsrfTokenValid($token)) {
    http_response_code(400);
    exit('Invalid CSRF');
}

$mode = $_POST['mode'] ?? '';
$maxBatch = 500;
$companyIds = [];
$search = '';
$statusFilter = '';
$ownerScope = '';
require_once __DIR__ . '/../../services/admin_audit_logger.php';
require_once __DIR__ . '/../../services/integration_circuit_breaker.php';
$isDegraded = isDegradedNow($pdo, 'gus');

try {
    if ($isDegraded && $mode === 'filter') {
        http_response_code(403);
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Integracja GUS w trybie degraded – akcja filtrowa zablokowana.'];
        header('Location: ' . BASE_URL . '/lista_klientow.php');
        exit;
    }
    ensureClientLeadColumns($pdo);
    if ($mode === 'selected') {
        $clientIds = array_filter(array_map('intval', $_POST['client_ids'] ?? []), static fn($v) => $v > 0);
        if ($clientIds) {
            $in = implode(',', array_fill(0, count($clientIds), '?'));
            $sql = "SELECT company_id FROM klienci WHERE company_id IS NOT NULL AND id IN ($in)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($clientIds);
            $companyIds = array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0)), static fn($v) => $v > 0);
        }
    } elseif ($mode === 'filter') {
        $search = trim((string)($_POST['q'] ?? ''));
        $statusFilter = trim((string)($_POST['status'] ?? ''));
        $ownerScope = trim((string)($_POST['owner_scope'] ?? ''));
        $clientColumns = getTableColumns($pdo, 'klienci');
        $assignedColumn = hasColumn($clientColumns, 'assigned_user_id') ? 'assigned_user_id' : (hasColumn($clientColumns, 'owner_user_id') ? 'owner_user_id' : null);

        $conditions = ['company_id IS NOT NULL'];
        $params = [];
        if ($search !== '') {
            $conditions[] = "(nazwa_firmy LIKE :search OR nip LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }
        if ($statusFilter !== '') {
            $conditions[] = 'status = :status';
            $params[':status'] = $statusFilter;
        }
        if ($assignedColumn && $ownerScope === 'mine') {
            $conditions[] = "$assignedColumn = :owner_id";
            $params[':owner_id'] = $actorId;
        }
        $sql = 'SELECT company_id FROM klienci WHERE ' . implode(' AND ', $conditions) . ' LIMIT ' . ($maxBatch + 1);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $companyIds = array_filter(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN, 0)), static fn($v) => $v > 0);
        if (count($companyIds) > $maxBatch) {
            $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Za dużo wyników (' . count($companyIds) . '). Zawęź filtr do 500.'];
            header('Location: ' . BASE_URL . '/lista_klientow.php');
            exit;
        }
    }

    $companyIds = array_values(array_unique($companyIds));
    if (!$companyIds) {
        $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Brak firm do dodania do kolejki.'];
        header('Location: ' . BASE_URL . '/lista_klientow.php');
        exit;
    }

    $res = enqueueForCompanies($pdo, $companyIds, 3, 'bulk_ui', $actorId);
    $msg = sprintf('Dodano do kolejki: created=%d, updated=%d, skipped=%d', $res['created'], $res['updated'], $res['skipped']);
    $_SESSION['flash'] = ['type' => 'success', 'message' => $msg];

    // audit log
    try {
        adminAuditLog($pdo, 'enqueue_bulk', $mode === 'selected' ? 'selected' : 'filter', $currentUser, ['mode' => $mode, 'q' => $search ?? null, 'status' => $statusFilter ?? null], array_slice($companyIds, 0, 200), ($res['created'] + $res['updated']), []);
    } catch (Throwable $e) {
        error_log('gus_enqueue_bulk audit failed: ' . $e->getMessage());
    }

    header('Location: ' . BASE_URL . '/lista_klientow.php');
    exit;
} catch (Throwable $e) {
    error_log('gus_enqueue_bulk failed: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Nie udało się dodać do kolejki.'];
    header('Location: ' . BASE_URL . '/lista_klientow.php');
    exit;
}
