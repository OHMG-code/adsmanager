<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../../services/gus_refresh_queue.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../services/admin_audit_logger.php';

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

$companyId = (int)($_POST['company_id'] ?? 0);
$clientId = (int)($_POST['client_id'] ?? 0);
if ($companyId === 0 && $clientId > 0) {
    $stmt = $pdo->prepare('SELECT company_id FROM klienci WHERE id = :id');
    $stmt->execute([':id' => $clientId]);
    $companyId = (int)($stmt->fetchColumn() ?: 0);
}

if ($companyId <= 0) {
    $_SESSION['flash'] = ['type' => 'warning', 'message' => 'Brak powiązanej firmy.'];
    header('Location: ' . BASE_URL . '/klient_szczegoly.php?id=' . $clientId);
    exit;
}

try {
    ensureGusRefreshQueue($pdo);
    gusQueueSmartEnqueue($pdo, $companyId, 1, 'retry_from_client', true);
    adminAuditLog($pdo, 'requeue_one', 'one', $currentUser, [], [$companyId], 1, []);
    $_SESSION['flash'] = ['type' => 'success', 'message' => 'Zlecono ponowienie GUS.'];
} catch (Throwable $e) {
    error_log('gus_requeue_one failed: ' . $e->getMessage());
    $_SESSION['flash'] = ['type' => 'danger', 'message' => 'Nie udało się zlecić odświeżenia.'];
}

$redirect = 'klient_szczegoly.php';
if ($clientId > 0) {
    $redirect .= '?id=' . $clientId;
}
header('Location: ' . BASE_URL . '/' . $redirect);
exit;
