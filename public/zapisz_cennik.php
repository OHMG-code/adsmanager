<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../config/config.php';

$typ = $_GET['typ'] ?? null;

$tabela = [
    'spoty'   => 'cennik_spoty',
    'sygnaly' => 'cennik_sygnaly',
    'wywiady' => 'cennik_wywiady',
    'display' => 'cennik_display',
    'social'  => 'cennik_social'
][$typ] ?? null;

if (!$tabela) {
    header('Location: ' . BASE_URL . '/cenniki.php?msg=error');
    exit;
}

try {
    foreach ($_POST['netto'] as $id => $netto) {
        $vat = $_POST['vat'][$id] ?? 23.0;

        $fields = ["stawka_netto = ?", "stawka_vat = ?"];
        $params = [$netto, $vat];

        if ($typ === 'wywiady') {
            $fields[] = "nazwa = ?";
            $fields[] = "opis = ?";
            $params[] = $_POST['nazwa'][$id] ?? '';
            $params[] = $_POST['opis'][$id] ?? '';
        }
        if ($typ === 'display') {
            $fields[] = "format = ?";
            $fields[] = "opis = ?";
            $params[] = $_POST['format'][$id] ?? '';
            $params[] = $_POST['opis'][$id] ?? '';
        }
        if ($typ === 'social') {
            $fields[] = "platforma = ?";
            $fields[] = "rodzaj_postu = ?";
            $params[] = $_POST['platforma'][$id] ?? '';
            $params[] = $_POST['rodzaj_postu'][$id] ?? '';
        }

        $params[] = $id;
        $sql = "UPDATE $tabela SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    header('Location: ' . BASE_URL . '/cenniki.php?msg=saved#' . $typ);
    exit;
} catch (Exception $e) {
    header('Location: ' . BASE_URL . '/cenniki.php?msg=error#' . $typ);
    exit;
}

