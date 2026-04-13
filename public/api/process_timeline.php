<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/documents.php';
require_once __DIR__ . '/../includes/process_timeline.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Wymagane logowanie.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nie mozna zweryfikowac uzytkownika.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$campaignId = isset($_GET['campaign_id']) ? (int)$_GET['campaign_id'] : 0;
if ($campaignId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nieprawidlowe campaign_id.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!canUserAccessKampania($pdo, $currentUser, $campaignId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak dostepu do kampanii.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
$limit = max(1, min(200, $limit));

try {
    $events = buildCampaignTimelineEvents($pdo, $campaignId, ['limit' => $limit]);
    echo json_encode([
        'success' => true,
        'campaign_id' => $campaignId,
        'events' => $events,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('process_timeline: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Nie udalo sie zbudowac timeline.'], JSON_UNESCAPED_UNICODE);
}
exit;
