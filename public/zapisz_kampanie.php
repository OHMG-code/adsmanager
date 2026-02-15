<?php
// zapisz_kampanie.php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db_schema.php';
/**
 * 1) Zaladowanie PDO:
 *    - jesli bootstrap/auth juz dostarczyl $pdo, uzyj go,
 *    - jesli istnieje db.php z $pdo, doladuj go,
 *    - w innym wypadku bezposrednie polaczenie na podstawie zmiennych srodowiskowych.
 */
if (!isset($pdo) || !($pdo instanceof PDO)) {
    if (file_exists(__DIR__ . '/db.php')) {
        require __DIR__ . '/db.php';
    }
}

if (!isset($pdo) || !($pdo instanceof PDO)) {
    $dbHost    = getenv('DB_HOST') ?: 'YOUR_DB_HOST';
    $dbName    = getenv('DB_NAME') ?: 'YOUR_DB_NAME';
    $dbUser    = getenv('DB_USER') ?: 'YOUR_DB_USER';
    $dbPass    = getenv('DB_PASS') ?: 'YOUR_DB_PASSWORD';
    $dbCharset = getenv('DB_CHARSET') ?: 'utf8mb4';

    if ($dbHost === 'YOUR_DB_HOST' || $dbName === 'YOUR_DB_NAME' || $dbUser === 'YOUR_DB_USER' || $dbPass === 'YOUR_DB_PASSWORD') {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Brak konfiguracji DB. Ustaw DB_HOST/DB_NAME/DB_USER/DB_PASS.'];
        header('Location: ' . BASE_URL . '/kalkulator_tygodniowy.php');
        exit;
    }

    $dsn = "mysql:host={$dbHost};dbname={$dbName};charset={$dbCharset}";
    try {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $options[PDO::MYSQL_ATTR_USE_BUFFERED_QUERY] = true;
        }
        $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
        if (defined('PDO::MYSQL_ATTR_USE_BUFFERED_QUERY')) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
        }
    } catch (Throwable $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Blad polaczenia z baza: ' . htmlspecialchars($e->getMessage())];
        header('Location: ' . BASE_URL . '/kalkulator_tygodniowy.php');
        exit;
    }
}

/**
 * 2) Helpery
 */
function money_to_decimal(?string $val): ?float {
    if ($val === null) {
        return null;
    }
    // Usuwamy spacje, zl, przecinki
    $v = trim($val);
    $v = str_ireplace(['zl', 'PLN', "z\u{142}"], '', $v);
    $v = str_replace([' ', "\xc2\xa0"], '', $v); // normal i NBSP
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : null;
}

function map_hour_to_time(string $hour): string {
    // Normalizujemy np. "06:00" lub "06:00:00" i obsluga ">23:00"
    if ($hour === '>23:00' || $hour === '&gt;23:00') {
        return '23:59:59';
    }
    if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $hour)) {
        return $hour;
    }
    if (preg_match('/^\d{2}:\d{2}$/', $hour)) {
        return $hour . ':00';
    }
    return '00:00:00';
}

/**
 * 3) Odbior formularza
 */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['flash'] = ['type' => 'warning', 'msg' => 'Nieprawidlowa metoda wywolania.'];
    header('Location: ' . BASE_URL . '/kalkulator_tygodniowy.php');
    exit;
}

$klientId     = $_POST['klient_id']      ?? null;
$klientNazwa  = $_POST['klient_nazwa']   ?? null;
$nazwaKampanii = trim((string)($_POST['nazwa_kampanii'] ?? ''));
$kampaniaTygodniowaId = (int)($_POST['kampania_tygodniowa_id'] ?? 0);
$dlugosc      = $_POST['dlugosc']        ?? null;
$dataStart    = $_POST['data_start']     ?? null;
$dataKoniec   = $_POST['data_koniec']    ?? null;
$rabat        = $_POST['rabat']          ?? '0';

$sumyPrime    = $_POST['sumy']['prime']    ?? 0;
$sumyStandard = $_POST['sumy']['standard'] ?? 0;
$sumyNight    = $_POST['sumy']['night']    ?? 0;

$nettoSpoty      = money_to_decimal($_POST['netto_spoty']      ?? null) ?? 0.0;
$nettoDodatki    = money_to_decimal($_POST['netto_dodatki']    ?? null) ?? 0.0;
// Ilosci dodatkow (przekazywane z kalkulatora)
$qtyDisplay   = (int)($_POST['display_ad_qty']     ?? 0);
$qtySponsor   = (int)($_POST['sponsor_signal_qty'] ?? 0);
$qtyInterview = (int)($_POST['interview_qty']      ?? 0);
$qtySocial    = (int)($_POST['social_media_qty']   ?? 0);

// Pobierz stawki z cennika po stronie serwera i policz sumy netto dodatkow
$cennikDodatki = [
    'display_ad'     => 0.0,
    'sponsor_signal' => 0.0,
    'interview'      => 0.0,
    'social_media'   => 0.0,
];
try { $v = $pdo->query("SELECT stawka_netto FROM cennik_display ORDER BY id LIMIT 1")->fetchColumn(); if ($v !== false) $cennikDodatki['display_ad'] = (float)$v; } catch (Throwable $e) {}
try { $v = $pdo->query("SELECT stawka_netto FROM cennik_sygnaly ORDER BY id LIMIT 1")->fetchColumn(); if ($v !== false) $cennikDodatki['sponsor_signal'] = (float)$v; } catch (Throwable $e) {}
try { $v = $pdo->query("SELECT stawka_netto FROM cennik_wywiady ORDER BY id LIMIT 1")->fetchColumn(); if ($v !== false) $cennikDodatki['interview'] = (float)$v; } catch (Throwable $e) {}
try { $v = $pdo->query("SELECT stawka_netto FROM cennik_social ORDER BY id LIMIT 1")->fetchColumn(); if ($v !== false) $cennikDodatki['social_media'] = (float)$v; } catch (Throwable $e) {}

$displayAd     = $qtyDisplay   * $cennikDodatki['display_ad'];
$sponsorSignal = $qtySponsor   * $cennikDodatki['sponsor_signal'];
$interview     = $qtyInterview * $cennikDodatki['interview'];
$socialMedia   = $qtySocial    * $cennikDodatki['social_media'];

// Jezeli policzona suma dodatkow > 0, nadpisujemy netto_dodatki
$sumDodatki = $displayAd + $sponsorSignal + $interview + $socialMedia;
if ($sumDodatki > 0) {
    $nettoDodatki = $sumDodatki;
}
$razemPoRabacie = money_to_decimal($_POST['razem_po_rabacie'] ?? null);
$razemBrutto    = money_to_decimal($_POST['razem_brutto']     ?? null);

// Siatka emisji moze przyjsc jako tablica lub JSON
$emisjaPost  = $_POST['emisja']      ?? null;
$emisjaJsonS = $_POST['emisja_json'] ?? null;
$siatkaDecoded = [];
if (!empty($emisjaJsonS)) {
    $decoded = json_decode($emisjaJsonS, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
        $siatkaDecoded = $decoded;
    }
} elseif (is_array($emisjaPost)) {
    $siatkaDecoded = $emisjaPost;
}

if (!$klientNazwa || !$dlugosc || !$dataStart || !$dataKoniec) {
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Brak wymaganych danych: klient, dlugosc, daty.'];
    header('Location: ' . BASE_URL . '/kalkulator_tygodniowy.php');
    exit;
}

// Sanitizacja podstawowa
$klientId   = $klientId ? (int)$klientId : null;
$dlugosc    = (int)$dlugosc;
$rabatFloat = (float)$rabat;

// Jezeli nie policzylismy "po rabacie", policz teraz
if ($razemPoRabacie === null) {
    $razemNetto = $nettoSpoty + $nettoDodatki;
    $razemPoRabacie = $razemNetto * (1 - ($rabatFloat / 100));
}
if ($razemBrutto === null) {
    $razemBrutto = $razemPoRabacie * 1.23; // VAT 23%
}

/**
 * 4) Transakcja - zapis do kampanie + kampanie_emisje
 */
try {
    $pdo->beginTransaction();

    // Insert do kampanie
    $stmt = $pdo->prepare("
        INSERT INTO kampanie 
            (klient_id, klient_nazwa, dlugosc_spotu, data_start, data_koniec, rabat, 
             netto_spoty, netto_dodatki, razem_netto, razem_brutto, created_at)
        VALUES
            (:klient_id, :klient_nazwa, :dlugosc_spotu, :data_start, :data_koniec, :rabat,
             :netto_spoty, :netto_dodatki, :razem_netto, :razem_brutto, NOW())
    ");
    $razemNetto = $nettoSpoty + $nettoDodatki;
    $stmt->execute([
        ':klient_id'     => $klientId,
        ':klient_nazwa'  => $klientNazwa,
        ':dlugosc_spotu' => $dlugosc,
        ':data_start'    => $dataStart,
        ':data_koniec'   => $dataKoniec,
        ':rabat'         => $rabatFloat,
        ':netto_spoty'   => $nettoSpoty,
        ':netto_dodatki' => $nettoDodatki,
        ':razem_netto'   => $razemPoRabacie, // zapisujemy "po rabacie" jako razem_netto (lub zmien na $razemNetto jesli wolisz)
        ':razem_brutto'  => $razemBrutto,
    ]);

    $kampaniaId = (int)$pdo->lastInsertId();

    // Przygotowanie insertu emisji
    $ins = $pdo->prepare("
        INSERT INTO kampanie_emisje (kampania_id, dzien_tygodnia, godzina, ilosc)
        VALUES (:kampania_id, :dzien, :godzina, :ilosc)
    ");

    // Parsowanie siatki emisji
    $emisjeDoZapisania = [];

    foreach ($siatkaDecoded as $dzien => $godziny) {
        if (!is_array($godziny)) {
            continue;
        }
        foreach ($godziny as $godzina => $ilosc) {
            $ilosc = (int)($ilosc ?? 0);
            if ($ilosc <= 0) {
                continue;
            }
            $emisjeDoZapisania[] = [$dzien, map_hour_to_time($godzina), $ilosc];
        }
    }

    // Zapis
    foreach ($emisjeDoZapisania as [$dzien, $godzina, $ilosc]) {
        $ins->execute([
            ':kampania_id' => $kampaniaId,
            ':dzien'       => $dzien,        // mon/tue/.../sun
            ':godzina'     => $godzina,      // HH:MM:SS (lub 23:59:59 dla >23:00)
            ':ilosc'       => $ilosc,
        ]);
    }

    // Zapis kampanii tygodniowej (idempotentny update po ID)
    if (tableExists($pdo, 'kampanie_tygodniowe')) {
        $weeklyCols = getTableColumns($pdo, 'kampanie_tygodniowe');
        $sumyPayload = [
            'prime' => (int)$sumyPrime,
            'standard' => (int)$sumyStandard,
            'night' => (int)$sumyNight,
        ];
        $produktyPayload = [
            ['nazwa' => 'Reklama Display', 'ilosc' => $qtyDisplay, 'kwota' => round($displayAd, 2)],
            ['nazwa' => 'Sygnał sponsorski', 'ilosc' => $qtySponsor, 'kwota' => round($sponsorSignal, 2)],
            ['nazwa' => 'Wywiad', 'ilosc' => $qtyInterview, 'kwota' => round($interview, 2)],
            ['nazwa' => 'Social Media', 'ilosc' => $qtySocial, 'kwota' => round($socialMedia, 2)],
        ];
        $produktyPayload = array_values(array_filter(
            $produktyPayload,
            static fn(array $row): bool => ((int)($row['ilosc'] ?? 0)) > 0 || ((float)($row['kwota'] ?? 0.0)) > 0
        ));

        $weeklyData = [];
        if (hasColumn($weeklyCols, 'klient_nazwa')) {
            $weeklyData['klient_nazwa'] = $klientNazwa;
        }
        if (hasColumn($weeklyCols, 'nazwa_kampanii')) {
            $weeklyData['nazwa_kampanii'] = $nazwaKampanii !== '' ? $nazwaKampanii : $klientNazwa;
        }
        if (hasColumn($weeklyCols, 'dlugosc')) {
            $weeklyData['dlugosc'] = $dlugosc;
        }
        if (hasColumn($weeklyCols, 'data_start')) {
            $weeklyData['data_start'] = $dataStart;
        }
        if (hasColumn($weeklyCols, 'data_koniec')) {
            $weeklyData['data_koniec'] = $dataKoniec;
        }
        if (hasColumn($weeklyCols, 'sumy')) {
            $weeklyData['sumy'] = json_encode($sumyPayload, JSON_UNESCAPED_UNICODE);
        }
        if (hasColumn($weeklyCols, 'produkty')) {
            $weeklyData['produkty'] = json_encode($produktyPayload, JSON_UNESCAPED_UNICODE);
        }
        if (hasColumn($weeklyCols, 'netto_spoty')) {
            $weeklyData['netto_spoty'] = $nettoSpoty;
        }
        if (hasColumn($weeklyCols, 'netto_dodatki')) {
            $weeklyData['netto_dodatki'] = $nettoDodatki;
        }
        if (hasColumn($weeklyCols, 'rabat')) {
            $weeklyData['rabat'] = $rabatFloat;
        }
        if (hasColumn($weeklyCols, 'razem_po_rabacie')) {
            $weeklyData['razem_po_rabacie'] = $razemPoRabacie;
        }
        if (hasColumn($weeklyCols, 'razem_brutto')) {
            $weeklyData['razem_brutto'] = $razemBrutto;
        }
        if (hasColumn($weeklyCols, 'siatka')) {
            $weeklyData['siatka'] = json_encode($siatkaDecoded, JSON_UNESCAPED_UNICODE);
        }

        if ($weeklyData) {
            $weeklyExists = false;
            if ($kampaniaTygodniowaId > 0) {
                $stmtExists = $pdo->prepare('SELECT COUNT(*) FROM kampanie_tygodniowe WHERE id = :id');
                $stmtExists->execute([':id' => $kampaniaTygodniowaId]);
                $weeklyExists = ((int)$stmtExists->fetchColumn()) > 0;
            }

            if ($weeklyExists) {
                $setParts = [];
                $params = [':id' => $kampaniaTygodniowaId];
                foreach ($weeklyData as $column => $value) {
                    $setParts[] = "{$column} = :{$column}";
                    $params[':' . $column] = $value;
                }
                if (hasColumn($weeklyCols, 'updated_at')) {
                    $setParts[] = 'updated_at = NOW()';
                }
                $stmtWeekly = $pdo->prepare('UPDATE kampanie_tygodniowe SET ' . implode(', ', $setParts) . ' WHERE id = :id');
                $stmtWeekly->execute($params);
            } else {
                $columns = array_keys($weeklyData);
                $placeholders = array_map(static fn(string $column): string => ':' . $column, $columns);
                $params = [];
                foreach ($weeklyData as $column => $value) {
                    $params[':' . $column] = $value;
                }
                if (hasColumn($weeklyCols, 'created_at')) {
                    $columns[] = 'created_at';
                    $placeholders[] = 'NOW()';
                }
                if (hasColumn($weeklyCols, 'updated_at')) {
                    $columns[] = 'updated_at';
                    $placeholders[] = 'NOW()';
                }
                $stmtWeekly = $pdo->prepare(
                    'INSERT INTO kampanie_tygodniowe (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')'
                );
                $stmtWeekly->execute($params);
                $kampaniaTygodniowaId = (int)$pdo->lastInsertId();
            }
        }
    }

    $pdo->commit();

    $_SESSION['flash'] = [
        'type' => 'success',
        'msg'  => 'Kampania zostala zapisana (ID: ' . $kampaniaId . ').'
    ];

    // Po zapisie wracamy z powrotem do kalkulatora (mozna zmienic na np. kampania_podglad.php?id=...)
    header('Location: ' . BASE_URL . '/kalkulator_tygodniowy.php?zapis=ok&id=' . $kampaniaId);
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $logPath = __DIR__ . '/../storage/logs/zapisz_kampanie.log';
    @file_put_contents($logPath, '[' . date('Y-m-d H:i:s') . '] ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
    $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Blad zapisu: ' . htmlspecialchars($e->getMessage())];
    header('Location: ' . BASE_URL . '/kalkulator_tygodniowy.php');
    exit;
}
