<?php
require_once __DIR__ . '/../includes/config.php';
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Wymagane logowanie.'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../includes/gus_config.php';
require_once __DIR__ . '/../includes/gus_service.php';
require_once __DIR__ . '/../includes/gus_validation.php';

header('Content-Type: application/json; charset=utf-8');

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nie można zweryfikować użytkownika.'], JSON_UNESCAPED_UNICODE);
    exit;
}

ensureSystemConfigColumns($pdo);
ensureGusCacheTable($pdo);
ensureIntegrationsLogsTable($pdo);

$configRow = $pdo->query("SELECT gus_enabled, gus_cache_ttl_days FROM konfiguracja_systemu WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
if (empty($configRow['gus_enabled'])) {
    echo json_encode(['success' => false, 'error' => 'Integracja GUS jest wyłączona.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$runtime = gusResolveRuntimeConfig();
if (empty($runtime['ok'])) {
    $missing = isset($runtime['missing']) ? implode(', ', (array)$runtime['missing']) : 'brakujące wartości';
    echo json_encode(['success' => false, 'error' => 'Brak konfiguracji GUS w .env: ' . $missing . '.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$nip = isset($_GET['nip']) ? normalizeNip($_GET['nip']) : '';
$regon = isset($_GET['regon']) ? normalizeRegon($_GET['regon']) : '';
$krs = isset($_GET['krs']) ? normalizeKrs($_GET['krs']) : '';
if ($nip === '' && $regon === '' && $krs === '') {
    echo json_encode(['success' => false, 'error' => 'Podaj NIP, REGON lub KRS.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = [
    'cache_ttl_days' => (int)($configRow['gus_cache_ttl_days'] ?? 30),
];

if ($nip !== '') {
    $result = gusFetchCompanyByNip($pdo, $nip, $config, (int)$currentUser['id']);
} elseif ($regon !== '') {
    $result = gusFetchCompanyByRegon($pdo, $regon, $config, (int)$currentUser['id']);
} else {
    $result = gusFetchCompanyByKrs($pdo, $krs, $config, (int)$currentUser['id']);
}

if (!empty($result['success'])) {
    echo json_encode(['success' => true, 'data' => $result['data'], 'cached' => !empty($result['cached'])], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Błąd usługi GUS.'], JSON_UNESCAPED_UNICODE);
exit;
