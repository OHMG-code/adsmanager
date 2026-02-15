<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../config/config.php';

$typ = $_GET['typ'] ?? null;

try {
    if ($typ === 'spoty') {
        $stmt = $pdo->prepare("INSERT INTO cennik_spoty (dlugosc, pasmo, stawka_netto, stawka_vat) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $_POST['dlugosc'],
            $_POST['pasmo'],
            $_POST['netto'],
            $_POST['vat'] ?? 23.00
        ]);
    } elseif ($typ === 'sygnaly') {
        $stmt = $pdo->prepare("INSERT INTO cennik_sygnaly (typ_programu, stawka_netto, stawka_vat) VALUES (?, ?, ?)");
        $stmt->execute([
            $_POST['typ_programu'],
            $_POST['netto'],
            $_POST['vat'] ?? 23.00
        ]);
    } elseif ($typ === 'wywiady') {
        $stmt = $pdo->prepare("INSERT INTO cennik_wywiady (nazwa, opis, stawka_netto, stawka_vat) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $_POST['nazwa'],
            $_POST['opis'] ?? '',
            $_POST['netto'],
            $_POST['vat'] ?? 23.00
        ]);
    } elseif ($typ === 'display') {
        $stmt = $pdo->prepare("INSERT INTO cennik_display (format, opis, stawka_netto, stawka_vat) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $_POST['format'],
            $_POST['opis'] ?? '',
            $_POST['netto'],
            $_POST['vat'] ?? 23.00
        ]);
    } elseif ($typ === 'social') {
        $stmt = $pdo->prepare("INSERT INTO cennik_social (platforma, rodzaj_postu, stawka_netto, stawka_vat) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $_POST['platforma'],
            $_POST['rodzaj_postu'] ?? '',
            $_POST['netto'],
            $_POST['vat'] ?? 23.00
        ]);
    } else {
        throw new Exception("Nieprawidłowy typ danych");
    }

    header('Location: ' . BASE_URL . '/cenniki.php?msg=added#' . $typ);
    exit;
} catch (Exception $e) {
    header('Location: ' . BASE_URL . '/cenniki.php?msg=error#' . $typ);
    exit;
}

