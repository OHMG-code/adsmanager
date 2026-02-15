<?php
require_once __DIR__ . '/includes/config.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/emisje_helpers.php';

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

function mapujDzienTygodniaNaNumer($kod) {
    return [
        'mon' => 1,
        'tue' => 2,
        'wed' => 3,
        'thu' => 4,
        'fri' => 5,
        'sat' => 6,
        'sun' => 7,
    ][$kod] ?? null;
}

function zbudujSiatkeTygodniowa(array $emisja): array {
    $rows = [];
    foreach ($emisja as $kodDnia => $godziny) {
        $dow = mapujDzienTygodniaNaNumer($kodDnia);
        if (!$dow) {
            continue;
        }
        foreach ($godziny as $godzina => $bloki) {
            if (!is_array($bloki)) {
                continue;
            }
            $liczba = 0;
            foreach ($bloki as $val) {
                if ((string)$val !== '0') {
                    $liczba++;
                }
            }
            if ($liczba > 0) {
                $rows[] = [
                    'dow' => $dow,
                    'godzina' => strlen($godzina) === 5 ? $godzina . ':00' : $godzina,
                    'liczba' => $liczba,
                ];
            }
        }
    }
    return $rows;
}

function buildValidationDates(string $dataStart, string $dataEnd): array {
    $today = new DateTime('today');
    $start = new DateTime($dataStart);
    $end = new DateTime($dataEnd);
    if ($start < $today) {
        $start = clone $today;
    }
    $windowEnd = (clone $start)->modify('+6 days');
    if ($end < $windowEnd) {
        $windowEnd = $end;
    }
    if ($start > $windowEnd) {
        return [];
    }
    $dates = [];
    for ($d = clone $start; $d <= $windowEnd; $d->modify('+1 day')) {
        $dates[] = $d->format('Y-m-d');
    }
    return $dates;
}

function validateBandLimits(PDO $pdo, array $emisja, int $spotLength, string $dataStart, string $dataEnd, ?int $excludeSpotId = null): array {
    try {
        ensureSystemConfigColumns($pdo);
        $pdo->exec("INSERT INTO konfiguracja_systemu (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id");
    } catch (Throwable $e) {
        error_log('add_spot: ensure config failed: ' . $e->getMessage());
    }

    $stmtCfg = $pdo->query("SELECT limit_prime_seconds_per_day, limit_standard_seconds_per_day, limit_night_seconds_per_day FROM konfiguracja_systemu WHERE id = 1");
    $cfg = $stmtCfg ? $stmtCfg->fetch(PDO::FETCH_ASSOC) : [];
    $limits = [
        'Prime' => (int)($cfg['limit_prime_seconds_per_day'] ?? 3600),
        'Standard' => (int)($cfg['limit_standard_seconds_per_day'] ?? 3600),
        'Night' => (int)($cfg['limit_night_seconds_per_day'] ?? 3600),
    ];

    $errors = [];
    $dates = buildValidationDates($dataStart, $dataEnd);
    foreach ($dates as $date) {
        $options = $excludeSpotId ? ['exclude_spot_id' => $excludeSpotId] : [];
        $base = calculateBandUsageForDate($pdo, $date, null, $options);
        $delta = calculateBandUsageFromEmisjaGridForDate($emisja, $date, $spotLength);

        foreach (['Prime', 'Standard', 'Night'] as $band) {
            $limitSeconds = $limits[$band];
            if ($limitSeconds <= 0) {
                continue;
            }
            $afterSeconds = (int)($base['bands'][$band]['suma_sekund'] ?? 0) + (int)($delta[$band]['seconds'] ?? 0);
            if ($afterSeconds <= $limitSeconds) {
                continue;
            }
            $overMinutes = ($afterSeconds - $limitSeconds) / 60;
            $limitMinutes = $limitSeconds / 60;
            $afterMinutes = $afterSeconds / 60;
            $errors[] = sprintf(
                '%s pasmo %s przekroczy limit o %s min (limit %s min, po zmianie %s min).',
                $date,
                $band,
                number_format($overMinutes, 1, '.', ' '),
                number_format($limitMinutes, 1, '.', ' '),
                number_format($afterMinutes, 1, '.', ' ')
            );
        }
    }

    return $errors;
}

// Przetwarzamy tylko żądania POST — zabezpieczenie przed bezpośrednim wejściem GET
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

$klient_id    = $_POST['klient_id'] ?? '';
$kampania_id  = isset($_POST['kampania_id']) && $_POST['kampania_id'] !== '' ? (int)$_POST['kampania_id'] : null;
$nazwa_spotu  = trim($_POST['nazwa_spotu'] ?? '');
$dlugosc_s    = (int)($_POST['dlugosc_s'] ?? ($_POST['dlugosc'] ?? 0));
$data_start   = $_POST['data_start'] ?? '';
$data_koniec  = $_POST['data_koniec'] ?? '';
$emisja       = $_POST['emisja'] ?? [];
$rezerwacja   = !empty($_POST['rezerwacja']) ? 1 : 0;
$status       = 'Aktywny';
$rotationGroupRaw = $_POST['rotation_group'] ?? '';
$rotationModeRaw = $_POST['rotation_mode'] ?? '';
$rotationGroup = in_array($rotationGroupRaw, ['A', 'B'], true) ? $rotationGroupRaw : null;
$rotationMode = in_array($rotationModeRaw, ['ALT_1_1', 'PREFER_A', 'PREFER_B'], true) ? $rotationModeRaw : null;
if ($rotationGroup === null) {
    $rotationMode = null;
}

// Weryfikacja pól — wymagamy nazwy i emisji oraz dat
if (empty($nazwa_spotu) || $dlugosc_s <= 0 || empty($data_start) || empty($data_koniec) || !isset($emisja) || !is_array($emisja)) {
    header('Location: ' . BASE_URL . '/spoty.php?msg=error_missing');
    exit;
}

// Jeśli klient_id nie podany spróbuj dopasować po nazwie/NIP (jeśli użytkownik wpisał tekst w polu 'klient_nazwa')
$klient_field = $_POST['klient_nazwa'] ?? '';
if (empty($klient_id) && !empty($klient_field)) {
    $stmtClient = $pdo->prepare("SELECT id FROM klienci WHERE nip = ? OR nazwa_firmy = ? LIMIT 1");
    $stmtClient->execute([$klient_field, $klient_field]);
    $found = $stmtClient->fetch();
    if ($found && !empty($found['id'])) {
        $klient_id = $found['id'];
    }
}

// Jeśli mamy kampanię, dopnij klienta z kampanii
if ($kampania_id) {
    $stmtK = $pdo->prepare("SELECT id, klient_id FROM kampanie WHERE id = ? LIMIT 1");
    $stmtK->execute([$kampania_id]);
    $kampaniaRow = $stmtK->fetch(PDO::FETCH_ASSOC);
    if ($kampaniaRow && !empty($kampaniaRow['klient_id'])) {
        $klient_id = $kampaniaRow['klient_id'];
    }
}

// Jeśli nadal brak klienta — błąd
if (empty($klient_id)) {
    header('Location: ' . BASE_URL . '/spoty.php?msg=error_no_client');
    exit;
}

try {
    ensureSpotColumns($pdo);
    ensureEmisjeSpotowTable($pdo);
    ensureKampanieOwnershipColumns($pdo);
    $spotCols = getTableColumns($pdo, 'spoty');
    $spotOwnerId = null;

    if (normalizeRole($currentUser) === 'Handlowiec') {
        if ($kampania_id) {
            $stmtOwner = $pdo->prepare("SELECT owner_user_id FROM kampanie WHERE id = ? LIMIT 1");
            $stmtOwner->execute([$kampania_id]);
            $kampaniaOwner = $stmtOwner->fetch(PDO::FETCH_ASSOC);
            if (!$kampaniaOwner || (int)($kampaniaOwner['owner_user_id'] ?? 0) !== (int)$currentUser['id']) {
                header('Location: ' . BASE_URL . '/spoty.php?msg=error_permission');
                exit;
            }
        } else {
            if (hasColumn($spotCols, 'owner_user_id')) {
                $spotOwnerId = (int)$currentUser['id'];
            } else {
                header('Location: ' . BASE_URL . '/spoty.php?msg=error_permission');
                exit;
            }
        }
    }

    $limitErrors = validateBandLimits($pdo, $emisja, $dlugosc_s, $data_start, $data_koniec);
    if ($limitErrors) {
        $_SESSION['flash_limit_errors'] = $limitErrors;
        header('Location: ' . BASE_URL . '/spoty.php');
        exit;
    }

    // 1. Dodanie spotu
    try {
        $col = $pdo->query("SHOW COLUMNS FROM spoty LIKE 'rezerwacja'")->fetch();
        if (!$col) {
            $pdo->exec("ALTER TABLE spoty ADD COLUMN rezerwacja TINYINT(1) DEFAULT 0");
        }
    } catch (Exception $e) {
        // Nie blokujemy tworzenia spotu jeśli ALTER nie powiedzie się (może brak uprawnień)
    }

    $insertColumns = ['klient_id', 'kampania_id', 'nazwa_spotu', 'dlugosc', 'dlugosc_s', 'data_start', 'data_koniec', 'rezerwacja', 'status'];
    $insertValues = [$klient_id ?: null, $kampania_id, $nazwa_spotu, $dlugosc_s, $dlugosc_s, $data_start ?: null, $data_koniec ?: null, $rezerwacja, $status];
    if ($spotOwnerId !== null && hasColumn($spotCols, 'owner_user_id')) {
        $insertColumns[] = 'owner_user_id';
        $insertValues[] = $spotOwnerId;
    }
    if (hasColumn($spotCols, 'rotation_group')) {
        $insertColumns[] = 'rotation_group';
        $insertValues[] = $rotationGroup;
    }
    if (hasColumn($spotCols, 'rotation_mode')) {
        $insertColumns[] = 'rotation_mode';
        $insertValues[] = $rotationMode;
    }
    $stmt = $pdo->prepare("INSERT INTO spoty (" . implode(', ', $insertColumns) . ")
                           VALUES (" . implode(', ', array_fill(0, count($insertColumns), '?')) . ")");
    $stmt->execute($insertValues);

    $spot_id = $pdo->lastInsertId();
    if (hasColumn($spotCols, 'aktywny')) {
        $pdo->prepare("UPDATE spoty SET aktywny = 1 WHERE id = ?")->execute([$spot_id]);
    }

    if (!hasApprovedAudio($pdo, (int)$spot_id)) {
        header('Location: ' . BASE_URL . '/spoty.php?msg=audio_required');
        exit;
    }

    // 2. Siatka tygodniowa (emisje_spotow)
    $weeklyRows = zbudujSiatkeTygodniowa($emisja);
    if ($weeklyRows) {
        $stmtWeekly = $pdo->prepare("INSERT INTO emisje_spotow (spot_id, dow, godzina, liczba) VALUES (?, ?, ?, ?)");
        foreach ($weeklyRows as $row) {
            $stmtWeekly->execute([$spot_id, $row['dow'], $row['godzina'], $row['liczba']]);
        }
    }

    // 3. Generowanie emisji na podstawie dni tygodnia (spoty_emisje)
    $start = new DateTime($data_start);
    $koniec = new DateTime($data_koniec);

    $stmtEmisja = $pdo->prepare("INSERT INTO spoty_emisje (spot_id, data, godzina, blok) VALUES (?, ?, ?, ?)");

    for ($d = clone $start; $d <= $koniec; $d->modify('+1 day')) {
        $numerDnia = (int)$d->format('N'); // 1 (Pon) do 7 (Nd)

        foreach ($emisja as $kodDnia => $godziny) {
            $mapowanyNumer = mapujDzienTygodniaNaNumer($kodDnia);
            if ($numerDnia !== $mapowanyNumer) continue;

            foreach ($godziny as $godzina => $bloki) {
                foreach ($bloki as $blok => $value) {
                    $litera = strtoupper(substr($blok, -1)); // blokA → A
                    $godzinaFull = strlen($godzina) === 5 ? $godzina . ':00' : $godzina;
                    $stmtEmisja->execute([$spot_id, $d->format('Y-m-d'), $godzinaFull, $litera]);
                }
            }
        }
    }

    header('Location: ' . BASE_URL . '/spoty.php?msg=spot_added');
    exit;

} catch (Exception $e) {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $msg = date('Y-m-d H:i:s') . " | add_spot error: " . $e->getMessage() . " | POST: " . json_encode($_POST) . "\n";
    @file_put_contents($logDir . '/spot_errors.log', $msg, FILE_APPEND);
    header('Location: ' . BASE_URL . '/spoty.php?msg=error_db');
    exit;
}


