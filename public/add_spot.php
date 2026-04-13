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

function normalizujGodzine(string $godzina): ?string {
    $godzina = trim($godzina);
    if ($godzina === '') {
        return null;
    }
    if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $godzina, $m)) {
        $h = (int)$m[1];
        $i = (int)$m[2];
        if ($h < 0 || $h > 23 || $i < 0 || $i > 59) {
            return null;
        }
        return sprintf('%02d:%02d', $h, $i);
    }
    return null;
}

function buildPlanCountsFromEmisjaGrid(array $emisja): array {
    $allowedDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
    $plan = [];
    foreach ($emisja as $kodDnia => $godziny) {
        $day = strtolower((string)$kodDnia);
        if (!in_array($day, $allowedDays, true) || !is_array($godziny)) {
            continue;
        }
        foreach ($godziny as $godzina => $bloki) {
            if (!is_array($bloki)) {
                continue;
            }
            $hourKey = normalizujGodzine((string)$godzina);
            if ($hourKey === null) {
                continue;
            }
            $count = 0;
            foreach ($bloki as $val) {
                if ((string)$val !== '0') {
                    $count++;
                }
            }
            if ($count > 0) {
                $plan[$day][$hourKey] = (int)($plan[$day][$hourKey] ?? 0) + $count;
            }
        }
    }
    return $plan;
}

function loadCampaignPlanCounts(PDO $pdo, int $kampaniaId): array {
    if ($kampaniaId <= 0) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT LOWER(dzien_tygodnia) AS dzien, TIME_FORMAT(godzina, '%H:%i') AS godzina, SUM(GREATEST(ilosc, 0)) AS ilosc
        FROM kampanie_emisje
        WHERE kampania_id = :id
        GROUP BY dzien, godzina");
    $stmt->execute([':id' => $kampaniaId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $plan = [];
    foreach ($rows as $row) {
        $day = strtolower((string)($row['dzien'] ?? ''));
        if (!in_array($day, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true)) {
            continue;
        }
        $hour = normalizujGodzine((string)($row['godzina'] ?? ''));
        $qty = (int)($row['ilosc'] ?? 0);
        if ($hour === null || $qty <= 0) {
            continue;
        }
        $plan[$day][$hour] = (int)($plan[$day][$hour] ?? 0) + $qty;
    }
    return $plan;
}

function buildWeeklyRowsFromPlanCounts(array $planCounts): array {
    $dowMap = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    $rows = [];
    foreach ($planCounts as $day => $hours) {
        $dow = (int)($dowMap[$day] ?? 0);
        if ($dow <= 0 || !is_array($hours)) {
            continue;
        }
        ksort($hours);
        foreach ($hours as $hour => $qty) {
            $hourKey = normalizujGodzine((string)$hour);
            $count = (int)$qty;
            if ($hourKey === null || $count <= 0) {
                continue;
            }
            $rows[] = [
                'dow' => $dow,
                'godzina' => $hourKey . ':00',
                'liczba' => $count,
            ];
        }
    }
    return $rows;
}

function buildDailyDemandFromPlanCounts(array $planCounts, string $dataStart, string $dataEnd): array {
    $start = DateTime::createFromFormat('Y-m-d', $dataStart);
    $end = DateTime::createFromFormat('Y-m-d', $dataEnd);
    if (!$start || !$end || $start > $end) {
        return [];
    }
    $dowMap = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    $demand = [];
    for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
        $code = $dowMap[(int)$d->format('N')] ?? null;
        if ($code === null || empty($planCounts[$code]) || !is_array($planCounts[$code])) {
            continue;
        }
        foreach ($planCounts[$code] as $hour => $qty) {
            $count = (int)$qty;
            $hourKey = normalizujGodzine((string)$hour);
            if ($hourKey === null || $count <= 0) {
                continue;
            }
            $fullHour = $hourKey . ':00';
            $dateKey = $d->format('Y-m-d');
            $demand[$dateKey][$fullHour] = (int)($demand[$dateKey][$fullHour] ?? 0) + $count;
        }
    }
    ksort($demand);
    foreach ($demand as &$hours) {
        ksort($hours);
    }
    unset($hours);
    return $demand;
}

function buildBlokLetters(int $liczbaBlokow): array {
    $letters = [];
    $limit = max(1, min(26, $liczbaBlokow));
    for ($i = 0; $i < $limit; $i++) {
        $letters[] = chr(65 + $i);
    }
    return $letters;
}

function loadExistingBlockUsageInRange(PDO $pdo, string $dataStart, string $dataEnd, ?int $excludeSpotId = null): array {
    $whereExclude = '';
    $params = [':start' => $dataStart, ':end' => $dataEnd];
    if ($excludeSpotId !== null) {
        $whereExclude = ' AND s.id <> :exclude_spot_id';
        $params[':exclude_spot_id'] = $excludeSpotId;
    }

    $sql = "SELECT
            se.data AS data_key,
            TIME_FORMAT(se.godzina, '%H:%i:%s') AS godzina_key,
            UPPER(COALESCE(NULLIF(TRIM(se.blok), ''), 'A')) AS blok_key,
            SUM(CASE
                WHEN COALESCE(s.dlugosc_s, 0) > 0 THEN s.dlugosc_s
                WHEN COALESCE(CAST(s.dlugosc AS UNSIGNED), 0) > 0 THEN CAST(s.dlugosc AS UNSIGNED)
                ELSE 30
            END) AS suma_sekund
        FROM spoty_emisje se
        INNER JOIN spoty s ON s.id = se.spot_id
        WHERE se.data BETWEEN :start AND :end
          AND COALESCE(s.rezerwacja, 0) = 0
          AND (s.data_start IS NULL OR s.data_start <= se.data)
          AND (s.data_koniec IS NULL OR s.data_koniec >= se.data)
          AND COALESCE(s.status, 'Aktywny') <> 'Nieaktywny'
          AND COALESCE(s.aktywny, 1) = 1
          {$whereExclude}
        GROUP BY data_key, godzina_key, blok_key";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $usage = [];
    foreach ($rows as $row) {
        $date = (string)($row['data_key'] ?? '');
        $hour = (string)($row['godzina_key'] ?? '');
        $block = (string)($row['blok_key'] ?? 'A');
        if ($date === '' || $hour === '' || $block === '') {
            continue;
        }
        $usage[$date][$hour][$block] = (int)($row['suma_sekund'] ?? 0);
    }
    return $usage;
}

function allocateBlocksForDemand(array $dailyDemand, array $existingUsage, int $spotLength, int $blockLimitSeconds, array $blockLetters): array {
    $rows = [];
    $errors = [];
    if ($spotLength <= 0) {
        return ['rows' => [], 'errors' => ['Nieprawidłowa długość spotu.']];
    }
    if ($blockLimitSeconds <= 0) {
        return ['rows' => [], 'errors' => ['Konfiguracja bloków nie pozwala na planowanie emisji (limit bloku = 0 s).']];
    }
    if (empty($blockLetters)) {
        $blockLetters = ['A'];
    }

    foreach ($dailyDemand as $date => $hours) {
        foreach ($hours as $hour => $qty) {
            $qtyInt = (int)$qty;
            if ($qtyInt <= 0) {
                continue;
            }
            for ($i = 0; $i < $qtyInt; $i++) {
                $selected = null;
                $selectedCurrent = null;
                foreach ($blockLetters as $block) {
                    $currentUsage = (int)($existingUsage[$date][$hour][$block] ?? 0);
                    if ($currentUsage + $spotLength > $blockLimitSeconds) {
                        continue;
                    }
                    if ($selected === null || $currentUsage < (int)$selectedCurrent) {
                        $selected = $block;
                        $selectedCurrent = $currentUsage;
                    }
                }
                if ($selected === null) {
                    $errors[] = sprintf(
                        '%s godz. %s: brak wolnego bloku (emisja %d/%d, limit bloku %s min).',
                        $date,
                        substr($hour, 0, 5),
                        $i + 1,
                        $qtyInt,
                        formatSecondsAsMinutes($blockLimitSeconds)
                    );
                    break;
                }

                $existingUsage[$date][$hour][$selected] = (int)($existingUsage[$date][$hour][$selected] ?? 0) + $spotLength;
                $rows[] = [
                    'data' => $date,
                    'godzina' => $hour,
                    'blok' => $selected,
                ];
            }
        }
    }

    return ['rows' => $rows, 'errors' => $errors];
}

function validateBandLimitsForWeeklyRows(PDO $pdo, array $weeklyRows, int $spotLength, string $dataStart, string $dataEnd, ?int $excludeSpotId = null): array {
    try {
        ensureSystemConfigColumns($pdo);
        $pdo->exec("INSERT INTO konfiguracja_systemu (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id");
    } catch (Throwable $e) {
        error_log('add_spot: ensure band config failed: ' . $e->getMessage());
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
        $delta = calculateBandUsageFromPlanRowsForDate($weeklyRows, $date, $spotLength);
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
        error_log('add_spot: ensure block config failed: ' . $e->getMessage());
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

if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    header('Location: ' . BASE_URL . '/spoty.php?msg=error_csrf');
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
$status       = $rezerwacja ? 'Przyszły' : 'Aktywny';
$rotationGroupRaw = $_POST['rotation_group'] ?? '';
$rotationModeRaw = $_POST['rotation_mode'] ?? '';
$rotationGroup = in_array($rotationGroupRaw, ['A', 'B'], true) ? $rotationGroupRaw : null;
$rotationMode = in_array($rotationModeRaw, ['ALT_1_1', 'PREFER_A', 'PREFER_B'], true) ? $rotationModeRaw : null;
if ($rotationGroup === null) {
    $rotationMode = null;
}

// Weryfikacja pól — wymagamy nazwy i dat; emisja może pochodzić z kampanii
if (empty($nazwa_spotu) || $dlugosc_s <= 0 || empty($data_start) || empty($data_koniec) || !is_array($emisja)) {
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
    ensureSystemConfigColumns($pdo);
    try {
        $pdo->exec("INSERT INTO konfiguracja_systemu (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id");
    } catch (Throwable $e) {
        error_log('add_spot: ensure konfiguracja_systemu row failed: ' . $e->getMessage());
    }
    $spotCols = getTableColumns($pdo, 'spoty');
    $spotOwnerId = null;

    if (normalizeRole($currentUser) === 'Handlowiec') {
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
            if (hasColumn($spotCols, 'owner_user_id')) {
                $spotOwnerId = (int)$currentUser['id'];
            }
        }
    }

    $planCounts = buildPlanCountsFromEmisjaGrid($emisja);
    if ($kampania_id) {
        $campaignPlan = loadCampaignPlanCounts($pdo, (int)$kampania_id);
        if (!empty($campaignPlan)) {
            // Kampania jest źródłem prawdy dla dni/godzin i liczby emisji.
            $planCounts = $campaignPlan;
        }
    }

    $weeklyRows = buildWeeklyRowsFromPlanCounts($planCounts);
    if (empty($weeklyRows)) {
        header('Location: ' . BASE_URL . '/spoty.php?msg=error_missing');
        exit;
    }
    $dailyDemand = buildDailyDemandFromPlanCounts($planCounts, $data_start, $data_koniec);
    if (empty($dailyDemand)) {
        header('Location: ' . BASE_URL . '/spoty.php?msg=error_missing');
        exit;
    }

    $stmtCfg = $pdo->query("SELECT liczba_blokow, block_duration_seconds, prog_procentowy FROM konfiguracja_systemu WHERE id = 1");
    $cfg = $stmtCfg ? $stmtCfg->fetch(PDO::FETCH_ASSOC) : [];
    $liczbaBlokow = max(1, (int)($cfg['liczba_blokow'] ?? 2));
    $blockLimitSeconds = resolveBlockDurationSeconds($cfg);
    $existingUsage = loadExistingBlockUsageInRange($pdo, $data_start, $data_koniec);
    $allocation = allocateBlocksForDemand(
        $dailyDemand,
        $existingUsage,
        $dlugosc_s,
        $blockLimitSeconds,
        buildBlokLetters($liczbaBlokow)
    );

    $limitErrors = validateBandLimitsForWeeklyRows($pdo, $weeklyRows, $dlugosc_s, $data_start, $data_koniec);
    $limitErrors = array_merge($limitErrors, $allocation['errors'] ?? []);
    if ($limitErrors) {
        $_SESSION['flash_limit_errors'] = $limitErrors;
        header('Location: ' . BASE_URL . '/spoty.php');
        exit;
    }
    $dailyEmisjaRows = $allocation['rows'] ?? [];

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
        $aktywnyFlag = $rezerwacja ? 0 : 1;
        $pdo->prepare("UPDATE spoty SET aktywny = ? WHERE id = ?")->execute([$aktywnyFlag, $spot_id]);
    }

    if (!hasApprovedAudio($pdo, (int)$spot_id)) {
        $_SESSION['audio_upload_error'] = 'Spot został utworzony, ale emisje nie zostały zapisane. Wgraj i zaakceptuj audio, a następnie zapisz spot ponownie.';
        header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . (int)$spot_id . '&audio_required=1');
        exit;
    }

    // 2. Siatka tygodniowa (emisje_spotow)
    $stmtWeekly = $pdo->prepare("INSERT INTO emisje_spotow (spot_id, dow, godzina, liczba) VALUES (?, ?, ?, ?)");
    foreach ($weeklyRows as $row) {
        $stmtWeekly->execute([$spot_id, $row['dow'], $row['godzina'], $row['liczba']]);
    }

    // 3. Emisje dzienne (spoty_emisje) z automatycznym wyborem wolnego bloku
    $stmtEmisja = $pdo->prepare("INSERT INTO spoty_emisje (spot_id, data, godzina, blok) VALUES (?, ?, ?, ?)");
    foreach ($dailyEmisjaRows as $row) {
        $stmtEmisja->execute([$spot_id, $row['data'], $row['godzina'], $row['blok']]);
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
