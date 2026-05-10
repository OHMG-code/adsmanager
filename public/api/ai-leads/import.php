<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../services/AiLeadImportService.php';

$user = aiLeadsRequireUser($pdo);
$payload = aiLeadsReadJsonPayload();
$leads = $payload['leads'] ?? $payload;
$assignedUserId = (int)($payload['assigned_user_id'] ?? (int)$user['id']);

if (!is_array($leads)) {
    aiLeadsJson(['success' => false, 'error' => 'Pole leads musi byc tablica.'], 400);
}

try {
    $service = new AiLeadImportService($pdo);
    $saved = $service->importGeneratedLeads($leads, $assignedUserId > 0 ? $assignedUserId : (int)$user['id']);
    aiLeadsJson([
        'success' => true,
        'data' => [
            'saved' => $saved,
            'count' => count($saved),
        ],
    ]);
} catch (Throwable $e) {
    error_log('api/ai-leads/import: ' . $e->getMessage());
    aiLeadsJson(['success' => false, 'error' => 'Nie udalo sie zaimportowac leadow.'], 500);
}
