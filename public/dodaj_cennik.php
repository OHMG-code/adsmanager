<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['Administrator', 'Manager']);
require_once '../config/config.php';

$typ = $_GET['typ'] ?? null;

function ensureCennikPatronatTable(PDO $pdo): void
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cennik_patronat (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pozycja VARCHAR(255) NOT NULL,
                stawka_netto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                stawka_vat DECIMAL(5,2) NOT NULL DEFAULT 23.00,
                data_modyfikacji TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('dodaj_cennik.php: cannot ensure cennik_patronat: ' . $e->getMessage());
    }
}

if ($typ === 'patronat') {
    ensureCennikPatronatTable($pdo);
}

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

if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    redirectError($typ);
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
    } elseif ($typ === 'patronat') {
        $pozycja = trim((string)($_POST['pozycja'] ?? ''));
        if ($pozycja === '') {
            redirectError($typ);
        }
        $stmt = $pdo->prepare("INSERT INTO cennik_patronat (pozycja, stawka_netto, stawka_vat) VALUES (?, ?, ?)");
        $stmt->execute([
            $pozycja,
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
