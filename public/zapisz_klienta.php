<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/handlers/client_save.php';

if (!isset($_POST['id']) || !is_numeric($_POST['id'])) {
    header('Location: ' . BASE_URL . '/lista_klientow.php?msg=error_invalid_id');
    exit;
}

$id = (int) $_POST['id'];

try {
    $currentUser = fetchCurrentUser($pdo) ?? [];
    $result = saveClientFromPost($pdo, $_POST, (int)($currentUser['id'] ?? 0), ['mode' => 'update']);
    if (!$result['ok']) {
        $errors = $result['errors'] ? implode(', ', $result['errors']) : 'Nie udało się zapisać danych klienta.';
        echo "<pre>Błąd aktualizacji: " . htmlspecialchars($errors) . "</pre>";
        exit;
    }

    header('Location: ' . BASE_URL . '/lista_klientow.php?msg=updated');
    exit;

} catch (PDOException $e) {
    error_log('zapisz_klienta.php: save failed: ' . $e->getMessage());
    echo "<pre>Błąd aktualizacji.</pre>";
    exit;
}
