<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../config/config.php';

$typ = $_GET['typ'] ?? null;
$id = $_POST['id'] ?? null;

$tabela = [
    'spoty'   => 'cennik_spoty',
    'sygnaly' => 'cennik_sygnaly',
    'wywiady' => 'cennik_wywiady',
    'display' => 'cennik_display',
    'social'  => 'cennik_social'
][$typ] ?? null;

if ($tabela && $id && is_numeric($id)) {
    $stmt = $pdo->prepare("DELETE FROM $tabela WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: ' . BASE_URL . '/cenniki.php?msg=deleted#' . $typ);
} else {
    header('Location: ' . BASE_URL . '/cenniki.php?msg=error#' . $typ);
}
exit;

