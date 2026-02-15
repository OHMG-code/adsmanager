<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Brak ID kampanii do usuniecia.'];
    header('Location: ' . BASE_URL . '/kampanie_lista.php');
    exit;
}

$deleted = false;

// Proba usuniecia z nowszej tabeli
try {
    $stmt = $pdo->prepare('DELETE FROM kampanie WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->rowCount() > 0) {
        $deleted = true;
    }
} catch (Throwable $e) {
    error_log('kampania_usun.php: blad usuwania z kampanie: ' . $e->getMessage());
}

// Fallback do starej tabeli
if (!$deleted) {
    try {
        $stmt = $pdo->prepare('DELETE FROM kampanie_tygodniowe WHERE id = :id');
        $stmt->execute([':id' => $id]);
        if ($stmt->rowCount() > 0) {
            $deleted = true;
        }
    } catch (Throwable $e) {
        error_log('kampania_usun.php: blad usuwania z kampanie_tygodniowe: ' . $e->getMessage());
    }
}

if ($deleted) {
    $_SESSION['flash'] = ['type' => 'success', 'msg' => "Kampania #{$id} zostala usunieta."];
} else {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => "Nie udalo sie usunac kampanii #{$id}."];
}

header('Location: ' . BASE_URL . '/kampanie_lista.php');
exit;

