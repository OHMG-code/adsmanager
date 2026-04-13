<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/config.php';
if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}

require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/includes/emisje_helpers.php';

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    header('Location: ' . BASE_URL . '/spoty.php?msg=error_csrf');
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

function occupancyLimitSecondsPerHourFromLegacy(string $progProcentowy): int {
    $map = [
        2 => 90,
        7 => 252,
        12 => 432,
        20 => 720,
    ];
    $percent = (int)trim($progProcentowy);
    if ($percent <= 0) {
        // 0% blokuje planowanie; stosujemy bezpieczny fallback 2%.
        $percent = 2;
    }
    if (isset($map[$percent])) {
        return $map[$percent];
    }
    return (int)round(($percent / 100) * 3600);
}

function resolveBlockDurationSeconds(array $cfg): int {
    $configured = (int)($cfg['block_duration_seconds'] ?? 0);
    if ($configured > 0) {
        return $configured;
    }
    $liczbaBlokow = max(1, (int)($cfg['liczba_blokow'] ?? 2));
    $legacyHourLimit = occupancyLimitSecondsPerHourFromLegacy((string)($cfg['prog_procentowy'] ?? '0'));
    $legacyPerBlock = (int)floor($legacyHourLimit / $liczbaBlokow);
    return $legacyPerBlock > 0 ? $legacyPerBlock : 45;
}

function formatSecondsAsMinutes(int $seconds): string {
    $seconds = max(0, $seconds);
    return sprintf('%d:%02d', (int)floor($seconds / 60), $seconds % 60);
}

function calculateBlockUsageFromEmisjaGridForDate(array $emisja, string $date, int $spotLengthSeconds): array {
    $day = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime($date);
    $dow = (int)$day->format('N');
    $map = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    $code = $map[$dow] ?? null;
    $usage = [];
    if (!$code || empty($emisja[$code]) || !is_array($emisja[$code])) {
        return $usage;
    }

    foreach ($emisja[$code] as $godzina => $bloki) {
        if (!is_array($bloki)) {
            continue;
        }
        $godzinaFull = strlen($godzina) === 5 ? $godzina . ':00' : $godzina;
        foreach ($bloki as $blokRaw => $val) {
            if ((string)$val === '0') {
                continue;
            }
            $blok = strtoupper(substr((string)$blokRaw, -1));
            if ($blok === '') {
                $blok = 'A';
            }
            $key = $godzinaFull . '|' . $blok;
            $usage[$key] = (int)($usage[$key] ?? 0) + $spotLengthSeconds;
        }
    }

    return $usage;
}

function calculateExistingBlockUsageForDate(PDO $pdo, string $date, ?int $excludeSpotId = null): array {
    $whereExclude = '';
    $params = [':day' => $date];
    if ($excludeSpotId !== null) {
        $whereExclude = ' AND s.id <> :exclude_spot_id';
        $params[':exclude_spot_id'] = $excludeSpotId;
    }

    $sql = "SELECT
            TIME_FORMAT(se.godzina, '%H:%i:%s') AS godzina_key,
            UPPER(COALESCE(NULLIF(TRIM(se.blok), ''), 'A')) AS blok_key,
            SUM(CASE
                WHEN COALESCE(s.dlugosc_s, 0) > 0 THEN s.dlugosc_s
                WHEN COALESCE(CAST(s.dlugosc AS UNSIGNED), 0) > 0 THEN CAST(s.dlugosc AS UNSIGNED)
                ELSE 30
            END) AS suma_sekund
        FROM spoty_emisje se
        INNER JOIN spoty s ON s.id = se.spot_id
        WHERE se.data = :day
          AND COALESCE(s.rezerwacja, 0) = 0
          AND (s.data_start IS NULL OR s.data_start <= :day)
          AND (s.data_koniec IS NULL OR s.data_koniec >= :day)
          AND COALESCE(s.status, 'Aktywny') <> 'Nieaktywny'
          AND COALESCE(s.aktywny, 1) = 1
          {$whereExclude}
        GROUP BY godzina_key, blok_key";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $usage = [];
    foreach ($rows as $row) {
        $key = (string)$row['godzina_key'] . '|' . (string)$row['blok_key'];
        $usage[$key] = (int)($row['suma_sekund'] ?? 0);
    }

    return $usage;
}

function validateBlockLimits(PDO $pdo, array $emisja, int $spotLength, string $dataStart, string $dataEnd, ?int $excludeSpotId = null): array {
    try {
        ensureSystemConfigColumns($pdo);
        $pdo->exec("INSERT INTO konfiguracja_systemu (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id");
    } catch (Throwable $e) {
        error_log('update_spot: ensure block config failed: ' . $e->getMessage());
    }

    $stmtCfg = $pdo->query("SELECT liczba_blokow, block_duration_seconds, prog_procentowy FROM konfiguracja_systemu WHERE id = 1");
    $cfg = $stmtCfg ? $stmtCfg->fetch(PDO::FETCH_ASSOC) : [];
    $blockLimit = resolveBlockDurationSeconds($cfg);

    $errors = [];
    $dates = buildValidationDates($dataStart, $dataEnd);
    foreach ($dates as $date) {
        $base = calculateExistingBlockUsageForDate($pdo, $date, $excludeSpotId);
        $delta = calculateBlockUsageFromEmisjaGridForDate($emisja, $date, $spotLength);
        foreach ($delta as $slotKey => $deltaSeconds) {
            $afterSeconds = (int)($base[$slotKey] ?? 0) + (int)$deltaSeconds;
            if ($afterSeconds <= $blockLimit) {
                continue;
            }
            [$hourLabel, $blokLabel] = explode('|', $slotKey, 2);
            $errors[] = sprintf(
                '%s godz. %s blok %s przekroczy limit o %s min (limit %s min, po zmianie %s min).',
                $date,
                substr((string)$hourLabel, 0, 5),
                $blokLabel ?: 'A',
                formatSecondsAsMinutes($afterSeconds - $blockLimit),
                formatSecondsAsMinutes($blockLimit),
                formatSecondsAsMinutes($afterSeconds)
            );
        }
    }

    return $errors;
}

function validateBandLimits(PDO $pdo, array $emisja, int $spotLength, string $dataStart, string $dataEnd, ?int $excludeSpotId = null): array {
    try {
        ensureSystemConfigColumns($pdo);
        $pdo->exec("INSERT INTO konfiguracja_systemu (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id");
    } catch (Throwable $e) {
        error_log('update_spot: ensure config failed: ' . $e->getMessage());
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

// Walidacja
$id = $_POST['id'] ?? null;
$klient_id = $_POST['klient_id'] ?? null;
$kampania_id = isset($_POST['kampania_id']) && $_POST['kampania_id'] !== '' ? (int)$_POST['kampania_id'] : null;
$nazwa = trim($_POST['nazwa_spotu'] ?? '');
$dlugosc_s = (int)($_POST['dlugosc_s'] ?? ($_POST['dlugosc'] ?? 0));
$data_start = $_POST['data_start'] ?? '';
$data_koniec = $_POST['data_koniec'] ?? '';
$emisja = $_POST['emisja'] ?? [];

if (!$id || !$nazwa || $dlugosc_s <= 0 || !$data_start || !$data_koniec) {
    header('Location: ' . BASE_URL . '/spoty.php?msg=error_missing');
    exit;
}

// Pobierz spot do walidacji/fallbacków
$stmtSpot = $pdo->prepare("SELECT klient_id FROM spoty WHERE id = ?");
$stmtSpot->execute([$id]);
$spotRow = $stmtSpot->fetch(PDO::FETCH_ASSOC);
if (!$spotRow) {
    header('Location: ' . BASE_URL . '/spoty.php?msg=error');
    exit;
}

// Jeśli kampania określona, dopnij klienta z kampanii
if ($kampania_id) {
    $stmtK = $pdo->prepare("SELECT klient_id FROM kampanie WHERE id = ? LIMIT 1");
    $stmtK->execute([$kampania_id]);
    $kRow = $stmtK->fetch(PDO::FETCH_ASSOC);
    if ($kRow && !empty($kRow['klient_id'])) {
        $klient_id = $kRow['klient_id'];
    }
}
if (empty($klient_id)) {
    $klient_id = $spotRow['klient_id'] ?? null;
}

try {
    ensureSpotColumns($pdo);
    ensureEmisjeSpotowTable($pdo);
    ensureKampanieOwnershipColumns($pdo);

    if (normalizeRole($currentUser) === 'Handlowiec') {
        if (!canAccessSpot($pdo, (int)$id, $currentUser)) {
            header('Location: ' . BASE_URL . '/spoty.php?msg=error_permission');
            exit;
        }

        if ($kampania_id) {
            if (!canUserAccessKampania($pdo, $currentUser, (int)$kampania_id)) {
                header('Location: ' . BASE_URL . '/spoty.php?msg=error_permission');
                exit;
            }
        } else {
            $clientId = (int)$klient_id;
            if ($clientId <= 0 || !canUserAccessClient($pdo, $currentUser, $clientId)) {
                header('Location: ' . BASE_URL . '/spoty.php?msg=error_permission');
                exit;
            }
        }
    }

    if (!hasApprovedAudio($pdo, (int)$id)) {
        $_SESSION['audio_upload_error'] = 'Nie można zapisać emisji: brak zaakceptowanego pliku audio.';
        header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . (int)$id);
        exit;
    }

    $limitErrors = validateBandLimits($pdo, $emisja, $dlugosc_s, $data_start, $data_koniec, (int)$id);
    $limitErrors = array_merge($limitErrors, validateBlockLimits($pdo, $emisja, $dlugosc_s, $data_start, $data_koniec, (int)$id));
    if ($limitErrors) {
        $_SESSION['flash_limit_errors'] = $limitErrors;
        header('Location: ' . BASE_URL . '/spoty.php');
        exit;
    }

    // 1. Aktualizacja danych podstawowych
    $stmt = $pdo->prepare("UPDATE spoty SET nazwa_spotu = ?, dlugosc = ?, dlugosc_s = ?, data_start = ?, data_koniec = ?, kampania_id = ?, klient_id = ? WHERE id = ?");
    $stmt->execute([$nazwa, $dlugosc_s, $dlugosc_s, $data_start ?: null, $data_koniec ?: null, $kampania_id, $klient_id, $id]);

    // 2. Usunięcie starej siatki
    $pdo->prepare("DELETE FROM spoty_emisje WHERE spot_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM emisje_spotow WHERE spot_id = ?")->execute([$id]);

    // 3. Siatka tygodniowa emisje_spotow
    $weeklyRows = zbudujSiatkeTygodniowa($emisja);
    if ($weeklyRows) {
        $stmtWeekly = $pdo->prepare("INSERT INTO emisje_spotow (spot_id, dow, godzina, liczba) VALUES (?, ?, ?, ?)");
        foreach ($weeklyRows as $row) {
            $stmtWeekly->execute([$id, $row['dow'], $row['godzina'], $row['liczba']]);
        }
    }

    // 4. Wygenerowanie nowych dat emisji
    $start = new DateTime($data_start);
    $koniec = new DateTime($data_koniec);
    $dni_map = ['mon'=>1, 'tue'=>2, 'wed'=>3, 'thu'=>4, 'fri'=>5, 'sat'=>6, 'sun'=>7];

    $stmtEmisja = $pdo->prepare("INSERT INTO spoty_emisje (spot_id, data, godzina, blok) VALUES (?, ?, ?, ?)");

    for ($d = clone $start; $d <= $koniec; $d->modify('+1 day')) {
        $kod = array_search((int)$d->format('N'), $dni_map, true);
        if ($kod === false || !isset($emisja[$kod])) continue;

        foreach ($emisja[$kod] as $godzina => $bloki) {
            foreach ($bloki as $blok => $value) {
                $litera = strtoupper(substr($blok, -1)); // blokA => A
                $godzinaFull = strlen($godzina) === 5 ? $godzina . ':00' : $godzina;
                $stmtEmisja->execute([$id, $d->format('Y-m-d'), $godzinaFull, $litera]);
            }
        }
    }

    header('Location: ' . BASE_URL . '/spoty.php?msg=spot_updated');
    exit;

} catch (Exception $e) {
    header('Location: ' . BASE_URL . '/spoty.php?msg=error');
    exit;
}
