<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../includes/config.php';
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../../services/LeadFormService.php';

function leadFormSubmitPayload(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode((string)$raw, true);
        return is_array($decoded) ? $decoded : [];
    }
    return $_POST;
}

function leadFormSubmitEmit(array $result): void
{
    $status = (int)($result['status'] ?? ($result['ok'] ? 200 : 400));
    http_response_code($status);
    echo json_encode([
        'ok' => !empty($result['ok']),
        'success' => !empty($result['ok']),
        'code' => (string)($result['code'] ?? ''),
        'message' => (string)($result['message'] ?? ''),
        'lead_id' => $result['lead_id'] ?? null,
        'submission_id' => $result['submission_id'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    $options = [
        'gus_fetcher' => static function (string $nip) use ($pdo): ?array {
            try {
                $cfg = $pdo->query("SELECT gus_enabled, gus_cache_ttl_days FROM konfiguracja_systemu WHERE id = 1 LIMIT 1")
                    ->fetch(PDO::FETCH_ASSOC) ?: [];
                if (empty($cfg['gus_enabled'])) {
                    return null;
                }
                require_once __DIR__ . '/../../../includes/gus_service.php';
                return gusFetchCompanyByNip($pdo, $nip, ['cache_ttl_days' => (int)($cfg['gus_cache_ttl_days'] ?? 30)], null);
            } catch (Throwable $e) {
                error_log('lead_form_submit: GUS lookup skipped: ' . $e->getMessage());
                return null;
            }
        },
    ];
    leadFormSubmitEmit(leadFormHandleSubmission($pdo, leadFormSubmitPayload(), $_SERVER, $options));
} catch (Throwable $e) {
    error_log('lead_form_submit: ' . $e->getMessage());
    leadFormSubmitEmit([
        'ok' => false,
        'status' => 500,
        'code' => 'SERVER_ERROR',
        'message' => 'Nie udało się zapisać zgłoszenia.',
    ]);
}
