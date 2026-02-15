<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../config/config.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: ' . BASE_URL . '/lista_klientow.php?msg=error_invalid_id');
    exit;
}

$klient_id = (int) $_GET['id'];

try {
    // Sprawdź, czy klient istnieje
    $stmt = $pdo->prepare("SELECT * FROM klienci WHERE id = ?");
    $stmt->execute([$klient_id]);

    if ($stmt->rowCount() === 0) {
        header('Location: ' . BASE_URL . '/lista_klientow.php?msg=error_not_found');
        exit;
    }

    // Usuń klienta
    $stmt = $pdo->prepare("DELETE FROM klienci WHERE id = ?");
    $stmt->execute([$klient_id]);

    header('Location: ' . BASE_URL . '/lista_klientow.php?msg=deleted');
    exit;

} catch (PDOException $e) {
    header('Location: ' . BASE_URL . '/lista_klientow.php?msg=error_db');
    exit;
}

