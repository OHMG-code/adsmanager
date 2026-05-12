<?php
declare(strict_types=1);

ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../includes/db_schema.php';
require_once __DIR__ . '/../../includes/gus_service.php';
require_once __DIR__ . '/../../../services/LeadFormService.php';

function leadFormJson(int $statusCode, bool $success, string $message): void
{
    http_response_code($statusCode);
    echo json_encode(['success' => $success, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function leadFormRateLimitPath(string $ip, string $publicKey): string
{
    $dir = dirname(__DIR__, 3) . '/storage/rate_limit';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $bucket = ($ip !== '' ? $ip : 'unknown') . '|' . ($publicKey !== '' ? $publicKey : 'missing');
    return $dir . '/lead_form_' . hash('sha256', $bucket) . '.json';
}

function leadFormRateLimited(string $ip, string $publicKey, int $limit = 20, int $windowSec = 600): bool
{
    $path = leadFormRateLimitPath($ip, $publicKey);
    $now = time();
    $items = [];
    if (is_file($path)) {
        $decoded = json_decode((string)@file_get_contents($path), true);
        if (is_array($decoded)) {
            $items = array_values(array_filter($decoded, static fn($ts): bool => is_numeric($ts) && (int)$ts >= ($now - $windowSec)));
        }
    }
    if (count($items) >= $limit) {
        @file_put_contents($path, json_encode($items));
        return true;
    }
    $items[] = $now;
    @file_put_contents($path, json_encode($items));
    return false;
}

try {
    ensureLeadColumns($pdo);
    ensureLeadActivityTable($pdo);
    ensureLeadFormTables($pdo);

    $service = new LeadFormService($pdo);
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $host = LeadFormService::originHost($origin, $_SERVER['HTTP_REFERER'] ?? null);
    $corsHost = $service->findCorsOriginForHost($host);
    if ($origin !== '' && $corsHost !== null) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        header('Access-Control-Max-Age: 600');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code($corsHost !== null ? 204 : 403);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        leadFormJson(405, false, 'Nieobslugiwana metoda.');
    }

    if ($origin !== '' && $corsHost === null) {
        leadFormJson(403, false, 'Formularz nie jest dozwolony dla tej domeny.');
    }

    $raw = (string)file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        leadFormJson(400, false, 'Nieprawidlowe dane formularza.');
    }

    $publicKey = trim((string)($payload['public_key'] ?? ''));
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (leadFormRateLimited($ip, $publicKey)) {
        leadFormJson(429, false, 'Zbyt wiele zgloszen. Sprobuj ponownie za chwile.');
    }

    $result = $service->submit($payload, $_SERVER);
    $status = !empty($result['success']) ? 200 : 400;
    $message = (string)($result['message'] ?? '');
    if (($result['status'] ?? '') === 'rejected' && (
        stripos($message, 'domena') !== false
        || stripos($message, 'klucza') !== false
        || stripos($message, 'nieaktywny') !== false
    )) {
        $status = 403;
    } elseif (($result['status'] ?? '') === 'error') {
        $status = 500;
    }
    leadFormJson($status, !empty($result['success']), $message);
} catch (Throwable $e) {
    error_log('lead_form_endpoint: ' . $e->getMessage());
    leadFormJson(500, false, 'Nie udalo sie zapisac zgloszenia. Sprobuj ponownie.');
}
