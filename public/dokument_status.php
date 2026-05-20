<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/document_status.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo 'Metoda niedozwolona.';
    exit;
}

$documentId = (int)($_POST['document_id'] ?? 0);
$newStatus = trim((string)($_POST['new_status'] ?? ''));
$redirectUrl = BASE_URL . '/dokumenty.php';
if ($documentId > 0) {
    $redirectUrl = BASE_URL . '/dokument_podglad.php?id=' . $documentId;
}
$appendQuery = static function (string $url, string $key, string $value): string {
    return $url . (strpos($url, '?') === false ? '?' : '&') . rawurlencode($key) . '=' . rawurlencode($value);
};

if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    header('Location: ' . $appendQuery($redirectUrl, 'status_error', 'Niepoprawny token CSRF.'));
    exit;
}

try {
    $user = getCurrentUser($pdo) ?: currentUser();
    $acceptedByName = trim((string)($user['login'] ?? $_SESSION['user_login'] ?? $_SESSION['login'] ?? ''));
    $acceptedByEmail = trim((string)($user['email'] ?? $_SESSION['user_email'] ?? ''));

    transitionDocumentStatus($pdo, $documentId, $newStatus, [
        'accepted_by_name' => $acceptedByName,
        'accepted_by_email' => $acceptedByEmail,
        'acceptance_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
        'acceptance_user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'user_id' => (int)($user['id'] ?? $_SESSION['user_id'] ?? 0),
    ]);

    header('Location: ' . BASE_URL . '/dokument_podglad.php?id=' . $documentId . '&status=updated');
    exit;
} catch (Throwable $e) {
    $message = $e->getMessage() !== '' ? $e->getMessage() : 'Nie udalo sie zmienic statusu dokumentu.';
    header('Location: ' . $appendQuery($redirectUrl, 'status_error', $message));
    exit;
}
