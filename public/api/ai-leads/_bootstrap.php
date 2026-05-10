<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_schema.php';

header('Content-Type: application/json; charset=utf-8');

function aiLeadsRequireUser(PDO $pdo): array
{
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Wymagane logowanie.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $user = currentUser();
    if (!$user || (int)($user['id'] ?? 0) <= 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Nie mozna zweryfikowac uzytkownika.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    ensureSystemConfigColumns($pdo);
    ensureAiLeadTables($pdo);
    ensureLeadColumns($pdo);

    return $user;
}

/**
 * @return array<string,mixed>
 */
function aiLeadsReadJsonPayload(): array
{
    $rawInput = file_get_contents('php://input') ?: '';
    $payload = json_decode($rawInput, true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }

    return is_array($payload) ? $payload : [];
}

/**
 * @param array<string,mixed> $payload
 */
function aiLeadsJson(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
