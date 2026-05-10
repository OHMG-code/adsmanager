<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../../services/AiLeadPromotionService.php';

aiLeadsRequireUser($pdo);
$payload = aiLeadsReadJsonPayload();
$aiLeadId = (int)($payload['ai_lead_id'] ?? ($payload['id'] ?? 0));

if ($aiLeadId <= 0) {
    aiLeadsJson(['success' => false, 'error' => 'ai_lead_id jest wymagane.'], 400);
}

try {
    $service = new AiLeadPromotionService($pdo);
    $promoted = $service->promoteToLead($aiLeadId);
    if (!$promoted) {
        aiLeadsJson(['success' => false, 'error' => 'Lead nie moze zostac promowany.'], 409);
    }

    aiLeadsJson(['success' => true]);
} catch (Throwable $e) {
    error_log('api/ai-leads/promote: ' . $e->getMessage());
    aiLeadsJson(['success' => false, 'error' => 'Nie udalo sie promowac leada.'], 500);
}
