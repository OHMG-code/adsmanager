<?php
require_once __DIR__ . '/includes/auth.php';
require_once '../config/config.php';

$typ = $_GET['typ'] ?? null;

function parseMoney($value): ?float
{
    if ($value === null) {
        return null;
    }
    $val = trim((string)$value);
    $val = str_replace(',', '.', $val);
    if ($val === '' || !is_numeric($val)) {
        return null;
    }
    return (float)$val;
}

function redirectError(?string $typ): void
{
    header('Location: ' . BASE_URL . '/cenniki.php?msg=error#' . ($typ ?? ''));
    exit;
}

try {
    $netto = parseMoney($_POST['netto'] ?? null);
    $vat = parseMoney($_POST['vat'] ?? 23.00);
    if ($netto === null || $netto < 0 || $vat === null || $vat < 0) {
        redirectError($typ);
    }

    if ($typ === 'spoty') {
        $dlugosc = trim((string)($_POST['dlugosc'] ?? ''));
        $pasmo = trim((string)($_POST['pasmo'] ?? ''));
        if ($dlugosc === '' || $pasmo === '') {
            redirectError($typ);
        }
        $stmt = $pdo->prepare("INSERT INTO cennik_spoty (dlugosc, pasmo, stawka_netto, stawka_vat) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $dlugosc,
            $pasmo,
            $netto,
            $vat
        ]);
    } elseif ($typ === 'sygnaly') {
        $typProgramu = trim((string)($_POST['typ_programu'] ?? ''));
        if ($typProgramu === '') {
            redirectError($typ);
        }
        $stmt = $pdo->prepare("INSERT INTO cennik_sygnaly (typ_programu, stawka_netto, stawka_vat) VALUES (?, ?, ?)");
        $stmt->execute([
            $typProgramu,
            $netto,
            $vat
        ]);
    } elseif ($typ === 'wywiady') {
        $nazwa = trim((string)($_POST['nazwa'] ?? ''));
        if ($nazwa === '') {
            redirectError($typ);
        }
        $stmt = $pdo->prepare("INSERT INTO cennik_wywiady (nazwa, opis, stawka_netto, stawka_vat) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $nazwa,
            trim((string)($_POST['opis'] ?? '')),
            $netto,
            $vat
        ]);
    } elseif ($typ === 'display') {
        $format = trim((string)($_POST['format'] ?? ''));
        if ($format === '') {
            redirectError($typ);
        }
        $stmt = $pdo->prepare("INSERT INTO cennik_display (format, opis, stawka_netto, stawka_vat) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $format,
            trim((string)($_POST['opis'] ?? '')),
            $netto,
            $vat
        ]);
    } elseif ($typ === 'social') {
        $platforma = trim((string)($_POST['platforma'] ?? ''));
        if ($platforma === '') {
            redirectError($typ);
        }
        $stmt = $pdo->prepare("INSERT INTO cennik_social (platforma, rodzaj_postu, stawka_netto, stawka_vat) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $platforma,
            trim((string)($_POST['rodzaj_postu'] ?? '')),
            $netto,
            $vat
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
