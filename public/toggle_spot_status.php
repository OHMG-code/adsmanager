<?php
require_once __DIR__ . '/includes/auth.php';
file_put_contents('log.txt', print_r($_POST, true), FILE_APPEND);
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo "Brak autoryzacji";
    exit;
}

require_once '../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';
ensureSpotColumns($pdo);

// Odbierz dane
$spot_id = $_POST['spot_id'] ?? null;
$status = $_POST['status'] ?? null;

if ($spot_id === null || $status === null) {
    http_response_code(400);
    echo "Brak danych";
    exit;
}

// Upewnij się, że status to 0 lub 1
$status = (int)($status === '1' ? 1 : 0);
$statusText = $status === 1 ? 'Aktywny' : 'Nieaktywny';

// Aktualizuj status w bazie
$stmt = $pdo->prepare("UPDATE spoty SET aktywny = ?, status = ? WHERE id = ?");
if ($stmt->execute([$status, $statusText, $spot_id])) {
    echo "Zmieniono status na: " . ($status ? "Aktywny" : "Nieaktywny");
} else {
    http_response_code(500);
    echo "Błąd podczas zapisu do bazy";
}

