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

try {
    if (empty($_POST['netto']) || !is_array($_POST['netto'])) {
        throw new Exception('Brak danych do zapisu');
    }

    foreach ($_POST['netto'] as $id => $netto) {
        $id = (int)$id;
        if ($id <= 0) {
            throw new Exception('Nieprawidłowe ID');
        }

        $nettoValue = parseMoney($netto);
        $vatValue = parseMoney($_POST['vat'][$id] ?? 23.0);
        if ($nettoValue === null || $nettoValue < 0 || $vatValue === null || $vatValue < 0) {
            throw new Exception('Nieprawidłowe wartości liczbowe');
        }

        $fields = ["stawka_netto = ?", "stawka_vat = ?"];
        $params = [$nettoValue, $vatValue];

        if ($typ === 'wywiady') {
            $nazwa = trim((string)($_POST['nazwa'][$id] ?? ''));
            if ($nazwa === '') {
                throw new Exception('Nazwa jest wymagana');
            }
            $fields[] = "nazwa = ?";
            $fields[] = "opis = ?";
            $params[] = $nazwa;
            $params[] = trim((string)($_POST['opis'][$id] ?? ''));
        }
        if ($typ === 'display') {
            $format = trim((string)($_POST['format'][$id] ?? ''));
            if ($format === '') {
                throw new Exception('Format jest wymagany');
            }
            $fields[] = "format = ?";
            $fields[] = "opis = ?";
            $params[] = $format;
            $params[] = trim((string)($_POST['opis'][$id] ?? ''));
        }
        if ($typ === 'social') {
            $platforma = trim((string)($_POST['platforma'][$id] ?? ''));
            if ($platforma === '') {
                throw new Exception('Platforma jest wymagana');
            }
            $fields[] = "platforma = ?";
            $fields[] = "rodzaj_postu = ?";
            $params[] = $platforma;
            $params[] = trim((string)($_POST['rodzaj_postu'][$id] ?? ''));
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
