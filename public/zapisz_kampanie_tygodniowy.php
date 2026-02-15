<?php
// zapisz_kampanie_tygodniowy.php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once '../config/config.php'; // ma wystawić $pdo (PDO)
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

// Pomocniczo: normalizacja polskich liczb (1 234,56 -> 1234.56)
function pl_to_decimal(string $s): float {
    $s = trim($s);
    $s = str_replace(["\xC2\xA0", ' '], '', $s); // nbsp + spacje
    $s = preg_replace('/[^0-9,\.\-]/', '', $s);
    if (substr_count($s, ',') === 1 && substr_count($s, '.') === 0) {
        $s = str_replace(',', '.', $s);
    }
    return (float)$s;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit('Method Not Allowed');
    }

    // CSRF
    if (empty($_POST['csrf_token']) || empty($_SESSION['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        throw new RuntimeException('Nieprawidłowy token CSRF.');
    }

    // Wymagane pola
    $klient      = trim($_POST['klient_nazwa'] ?? '');
    $dlugosc     = (int)($_POST['dlugosc'] ?? 0);
    $data_start  = $_POST['data_start'] ?? null;
    $data_koniec = $_POST['data_koniec'] ?? null;

    if ($klient === '' || !$dlugosc || !$data_start || !$data_koniec) {
        throw new InvalidArgumentException('Brak wymaganych pól formularza.');
    }

    // Sumy pasm (opcjonalnie 0)
    $sumy = [
        'prime'    => (int)($_POST['sumy']['prime'] ?? 0),
        'standard' => (int)($_POST['sumy']['standard'] ?? 0),
        'night'    => (int)($_POST['sumy']['night'] ?? 0),
    ];

    // Produkty dodatkowe (tablica nazw)
    $produkty = isset($_POST['produkty']) && is_array($_POST['produkty']) ? array_values($_POST['produkty']) : [];

    // Kwoty (readonly z frontu – normalizujemy)
    $netto_spoty      = pl_to_decimal($_POST['netto_spoty'] ?? '0');
    $netto_dodatki    = pl_to_decimal($_POST['netto_dodatki'] ?? '0');
    $rabat            = pl_to_decimal($_POST['rabat'] ?? '0');
    $razem_po_rabacie = pl_to_decimal($_POST['razem_po_rabacie'] ?? '0');
    $razem_brutto     = pl_to_decimal($_POST['razem_brutto'] ?? '0');

    // Siatka emisji jako JSON z hidden input
    $siatka_json = $_POST['siatka_json'] ?? '';
    $siatka_arr = [];
    if ($siatka_json !== '') {
        $tmp = json_decode($siatka_json, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) {
            $siatka_arr = $tmp;
        } else {
            throw new InvalidArgumentException('Nieprawidłowy format siatki emisji (JSON).');
        }
    }

    $sql = "INSERT INTO kampanie_tygodniowe
            (klient_nazwa, dlugosc, data_start, data_koniec, sumy, produkty,
             netto_spoty, netto_dodatki, rabat, razem_po_rabacie, razem_brutto, siatka, created_at)
            VALUES
            (:klient, :dlugosc, :data_start, :data_koniec, :sumy, :produkty,
             :netto_spoty, :netto_dodatki, :rabat, :razem_po_rabacie, :razem_brutto, :siatka, NOW())";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':klient'           => $klient,
        ':dlugosc'          => $dlugosc,
        ':data_start'       => $data_start,
        ':data_koniec'      => $data_koniec,
        ':sumy'             => json_encode($sumy, JSON_UNESCAPED_UNICODE),
        ':produkty'         => json_encode($produkty, JSON_UNESCAPED_UNICODE),
        ':netto_spoty'      => $netto_spoty,
        ':netto_dodatki'    => $netto_dodatki,
        ':rabat'            => $rabat,
        ':razem_po_rabacie' => $razem_po_rabacie,
        ':razem_brutto'     => $razem_brutto,
        ':siatka'           => $siatka_arr ? json_encode($siatka_arr, JSON_UNESCAPED_UNICODE) : null,
    ]);

    $id = (int)$pdo->lastInsertId();
    header('Location: ' . BASE_URL . '/kampania_podglad.php?id=' . $id);
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    // Na produkcji zamiast echować błąd – loguj i pokaż ładny komunikat
    echo 'Błąd zapisu: ' . htmlspecialchars($e->getMessage());
}

