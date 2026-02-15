<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../services/integration_circuit_breaker.php';
require_once __DIR__ . '/../../config/config.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
$role = $currentUser ? normalizeRole($currentUser) : '';
if (!in_array($role, ['Administrator', 'Manager'], true) && !isSuperAdmin()) {
    http_response_code(403);
    exit('Forbidden');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}
$token = $_POST['csrf_token'] ?? '';
if (!isCsrfTokenValid($token)) {
    http_response_code(400);
    exit('Invalid CSRF');
}

clearCircuit($pdo, 'gus');
$_SESSION['flash'] = ['type' => 'success', 'message' => 'Degraded mode cleared'];
header('Location: ' . BASE_URL . '/admin/gus-queue.php');
exit;
