<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../services/AiLeadGeneratorService.php';
require_once __DIR__ . '/../../../services/AiLeadDeduplicationService.php';

$user = aiLeadsRequireUser($pdo);
$payload = aiLeadsReadJsonPayload();

$industry = trim((string)($payload['industry'] ?? ''));
$location = trim((string)($payload['location'] ?? ''));
$limit = (int)($payload['limit'] ?? 10);
$radiusKm = (int)($payload['radius_km'] ?? 30);
$assignedUserId = (int)($payload['assigned_user_id'] ?? (int)$user['id']);
$mode = trim((string)($payload['mode'] ?? 'google_ai'));

if ($industry === '' || $location === '') {
    aiLeadsJson(['success' => false, 'error' => 'industry i location sa wymagane.'], 400);
}

try {
    $service = new AiLeadGeneratorService($pdo);
    $result = $service->generateLeads($industry, $location, $limit, [
        'radius_km' => $radiusKm,
        'assigned_user_id' => $assignedUserId,
        'mode' => $mode,
    ]);
    $leads = $result['leads'];
    $dedupe = new AiLeadDeduplicationService($pdo);
    foreach ($leads as $index => $lead) {
        $duplicate = $dedupe->checkDuplicates($lead);
        $leads[$index]['duplicate_status'] = $duplicate['recommended_status'] === 'duplicate' ? 'duplicate' : 'safe';
        $leads[$index]['duplicate_score'] = $duplicate['score'];
        $leads[$index]['duplicates'] = $duplicate['matches'];
        $leads[$index]['assigned_user_id'] = $assignedUserId > 0 ? $assignedUserId : null;
    }

    $statusCode = !empty($result['errors']) ? 422 : 200;
    aiLeadsJson([
        'success' => empty($result['errors']),
        'data' => [
            'leads' => $leads,
            'saved' => false,
            'enrichment_status' => $result['enrichment_status'],
            'warnings' => $result['warnings'],
            'errors' => $result['errors'],
            'settings' => $result['settings'],
        ],
    ], $statusCode);
} catch (InvalidArgumentException $e) {
    aiLeadsJson(['success' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    error_log('api/ai-leads/generate: ' . $e->getMessage());
    aiLeadsJson(['success' => false, 'error' => 'Nie udalo sie wygenerowac leadow.'], 500);
}
