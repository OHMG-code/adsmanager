<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../includes/gus_config.php';
require_once __DIR__ . '/../../../includes/gus_service.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Brak aktywnej sesji.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$role = normalizeRole($currentUser);
if (!in_array($role, ['Administrator', 'Manager'], true) && !isSuperAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Brak uprawnien.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = gusResolveRuntimeConfig();
$selftest = ['ok' => false, 'error' => 'Brak konfiguracji GUS (sprawdz .env).'];
if (!empty($config['ok'])) {
    $selftest = gusSelfTest();
}

if (function_exists('gusLogSelftest')) {
    gusLogSelftest($config, $selftest);
}

$response = [
    'ok' => !empty($selftest['ok']),
    'timestamp' => date('c'),
    'env' => $config['environment'] ?? '',
    'wsdl' => $config['wsdl'] ?? '',
    'endpoint' => $config['endpoint'] ?? '',
    'selftest' => [
        'ok' => !empty($selftest['ok']),
    ],
];

if (!empty($selftest['error'])) {
    $response['selftest']['error'] = (string)$selftest['error'];
}
if (!empty($config['missing'])) {
    $response['missing'] = array_values((array)$config['missing']);
}

if (!$response['ok']) {
    http_response_code(502);
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
