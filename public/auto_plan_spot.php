<?php
require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/emisje_helpers.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];

ensureSpotColumns($pdo);
ensureEmisjeSpotowTable($pdo);
ensureKampanieOwnershipColumns($pdo);
ensureSystemConfigColumns($pdo);
ensureIntegrationsLogsTable($pdo);

function parseWindows(?string $input): array {
    $input = trim((string)$input);
    if ($input === '') {
        return [];
    }
    $input = str_replace([';', '|'], ',', $input);
    $parts = array_filter(array_map('trim', explode(',', $input)));
    $windows = [];
    foreach ($parts as $part) {
        if (preg_match('/^(\d{1,2}):(\d{2})\s*-\s*(\d{1,2}):(\d{2})$/', $part, $m)) {
            $from = sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
            $to = sprintf('%02d:%02d', (int)$m[3], (int)$m[4]);
            $windows[] = [$from, $to];
        }
    }
    return $windows;
}

function generateTimes(array $windows, int $minGapMinutes): array {
    $times = [];
    foreach ($windows as [$from, $to]) {
        $start = DateTime::createFromFormat('H:i', $from);
        $end = DateTime::createFromFormat('H:i', $to);
        if (!$start || !$end) {
            continue;
        }
        for ($t = clone $start; $t <= $end; $t->modify('+' . $minGapMinutes . ' minutes')) {
            $times[] = $t->format('H:i:00');
        }
    }
    $times = array_values(array_unique($times));
    sort($times);
    return $times;
}

function distributeCounts(int $total, int $daysCount): array {
    if ($daysCount <= 0) {
        return [];
    }
    $base = intdiv($total, $daysCount);
    $remainder = $total % $daysCount;
    $counts = array_fill(0, $daysCount, $base);
    for ($i = 0; $i < $remainder; $i++) {
        $counts[$i] += 1;
    }
    return $counts;
}

function buildPlanRows(array $days, array $bandWindows, array $bands, int $countPerDay, int $minGapMinutes): array {
    $rows = [];
    $dowMap = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
    foreach ($days as $index => $day) {
        $count = $countPerDay;
        if ($count <= 0) {
            continue;
        }
        $candidateTimes = [];
        foreach ($bands as $band) {
            $candidateTimes = array_merge($candidateTimes, generateTimes($bandWindows[$band] ?? [], $minGapMinutes));
        }
        $candidateTimes = array_values(array_unique($candidateTimes));
        sort($candidateTimes);
        if (!$candidateTimes) {
            continue;
        }
        if ($count > count($candidateTimes)) {
            $count = count($candidateTimes);
        }
        $selected = [];
        if ($count === 1) {
            $selected[] = $candidateTimes[(int)floor(count($candidateTimes) / 2)];
        } else {
            $step = (count($candidateTimes) - 1) / ($count - 1);
            for ($i = 0; $i < $count; $i++) {
                $idx = (int)round($i * $step);
                $selected[] = $candidateTimes[$idx] ?? end($candidateTimes);
            }
        }
        $selected = array_values(array_unique($selected));
        while (count($selected) < $count) {
            foreach ($candidateTimes as $time) {
                if (!in_array($time, $selected, true)) {
                    $selected[] = $time;
                    break;
                }
            }
        }
        foreach ($selected as $time) {
            $rows[] = [
                'dow' => $dowMap[$day] ?? 0,
                'godzina' => $time,
                'liczba' => 1,
            ];
        }
    }
    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

$spotId = isset($_POST['spot_id']) ? (int)$_POST['spot_id'] : 0;
if ($spotId <= 0) {
    $_SESSION['auto_plan_error'] = 'Nieprawidłowy identyfikator spotu.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

if (!canAccessSpot($pdo, $spotId, $currentUser)) {
    $_SESSION['auto_plan_error'] = 'Brak uprawnień do tego spotu.';
    header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
    exit;
}

if (!hasApprovedAudio($pdo, $spotId)) {
    $_SESSION['auto_plan_error'] = 'Nie można zaplanować emisji: brak zaakceptowanego pliku audio.';
    header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
    exit;
}

$days = $_POST['days'] ?? [];
$bands = $_POST['bands'] ?? [];
$mode = $_POST['mode'] ?? 'daily';
$count = (int)($_POST['count'] ?? 0);
$minGap = max(15, (int)($_POST['min_gap'] ?? 60));
$startDateInput = $_POST['start_date'] ?? '';
$overwrite = !empty($_POST['overwrite']);

if (!$days || !$bands || $count <= 0) {
    $_SESSION['auto_plan_error'] = 'Uzupełnij dni tygodnia, pasma i liczbę emisji.';
    header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
    exit;
}

$windows = [
    'Prime' => parseWindows($_POST['windows_prime'] ?? ''),
    'Standard' => parseWindows($_POST['windows_standard'] ?? ''),
    'Night' => parseWindows($_POST['windows_night'] ?? ''),
];

$stmtSpot = $pdo->prepare("SELECT id, dlugosc_s, dlugosc, data_start, data_koniec, kampania_id FROM spoty WHERE id = ? LIMIT 1");
$stmtSpot->execute([$spotId]);
$spot = $stmtSpot->fetch(PDO::FETCH_ASSOC);
if (!$spot) {
    $_SESSION['auto_plan_error'] = 'Spot nie został znaleziony.';
    header('Location: ' . BASE_URL . '/spoty.php');
    exit;
}

$length = (int)($spot['dlugosc_s'] ?? 0);
if ($length <= 0 && isset($spot['dlugosc'])) {
    $length = (int)$spot['dlugosc'];
}
if ($length <= 0) {
    $length = 30;
}

$startDate = $startDateInput !== '' ? $startDateInput : ($spot['data_start'] ?? date('Y-m-d'));
$startDateObj = DateTime::createFromFormat('Y-m-d', $startDate) ?: new DateTime();
$today = new DateTime('today');
if ($startDateObj < $today) {
    $startDateObj = clone $today;
}
$endWindow = (clone $startDateObj)->modify('+13 days');

$countsPerDay = [];
if ($mode === 'weekly') {
    $countsPerDay = distributeCounts($count, count($days));
} else {
    $countsPerDay = array_fill(0, count($days), $count);
}

$planRows = [];
foreach (array_values($days) as $idx => $day) {
    $perDayCount = $countsPerDay[$idx] ?? 0;
    $planRows = array_merge($planRows, buildPlanRows([$day], $windows, $bands, $perDayCount, $minGap));
}

if (!$planRows) {
    $_SESSION['auto_plan_error'] = 'Nie udało się wygenerować planu emisji (brak godzin w oknach pasm).';
    header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
    exit;
}

// Walidacja limitów pasm (14 dni)
$stmtCfg = $pdo->query("SELECT limit_prime_seconds_per_day, limit_standard_seconds_per_day, limit_night_seconds_per_day FROM konfiguracja_systemu WHERE id = 1");
$cfg = $stmtCfg ? $stmtCfg->fetch(PDO::FETCH_ASSOC) : [];
$limits = [
    'Prime' => (int)($cfg['limit_prime_seconds_per_day'] ?? 3600),
    'Standard' => (int)($cfg['limit_standard_seconds_per_day'] ?? 3600),
    'Night' => (int)($cfg['limit_night_seconds_per_day'] ?? 3600),
];

$errors = [];
for ($d = clone $startDateObj; $d <= $endWindow; $d->modify('+1 day')) {
    $dateStr = $d->format('Y-m-d');
    $options = $overwrite ? ['exclude_spot_id' => $spotId] : [];
    $base = calculateBandUsageForDate($pdo, $dateStr, null, $options);
    $delta = calculateBandUsageFromPlanRowsForDate($planRows, $dateStr, $length);
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
            $dateStr,
            $band,
            number_format($overMinutes, 1, '.', ' '),
            number_format($limitMinutes, 1, '.', ' '),
            number_format($afterMinutes, 1, '.', ' ')
        );
    }
}

if ($errors) {
    $_SESSION['auto_plan_error'] = "Auto-plan zablokowany:\n" . implode("\n", $errors) . "\nSugestia: zmniejsz emisje dziennie.";
    header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
    exit;
}

try {
    $pdo->beginTransaction();
    if ($overwrite) {
        $pdo->prepare("DELETE FROM emisje_spotow WHERE spot_id = ?")->execute([$spotId]);
        $pdo->prepare("DELETE FROM spoty_emisje WHERE spot_id = ?")->execute([$spotId]);
    }

    $existing = [];
    if (!$overwrite) {
        $stmt = $pdo->prepare("SELECT dow, godzina, liczba FROM emisje_spotow WHERE spot_id = ?");
        $stmt->execute([$spotId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['dow'] . '_' . $row['godzina'];
            $existing[$key] = (int)$row['liczba'];
        }
    }

    $stmtIns = $pdo->prepare("INSERT INTO emisje_spotow (spot_id, dow, godzina, liczba) VALUES (?, ?, ?, ?)");
    $mergedRows = [];
    foreach ($planRows as $row) {
        $key = $row['dow'] . '_' . $row['godzina'];
        $count = (int)($row['liczba'] ?? 1);
        if (isset($existing[$key])) {
            $count += $existing[$key];
        }
        $mergedRows[$key] = ['dow' => $row['dow'], 'godzina' => $row['godzina'], 'liczba' => $count];
    }
    if (!$overwrite) {
        foreach ($existing as $key => $count) {
            if (!isset($mergedRows[$key])) {
                [$dow, $godzina] = explode('_', $key, 2);
                $mergedRows[$key] = ['dow' => (int)$dow, 'godzina' => $godzina, 'liczba' => $count];
            }
        }
    }

    foreach ($mergedRows as $row) {
        $stmtIns->execute([$spotId, $row['dow'], $row['godzina'], $row['liczba']]);
    }

    // Generowanie spoty_emisje na podstawie tygodniowego planu
    $dataStart = $spot['data_start'] ?? null;
    $dataEnd = $spot['data_koniec'] ?? null;
    if ($dataStart && $dataEnd) {
        $start = new DateTime($dataStart);
        $end = new DateTime($dataEnd);
        $stmtEmisja = $pdo->prepare("INSERT INTO spoty_emisje (spot_id, data, godzina, blok) VALUES (?, ?, ?, ?)");
        for ($d = clone $start; $d <= $end; $d->modify('+1 day')) {
            $dow = (int)$d->format('N');
            foreach ($mergedRows as $row) {
                if ((int)$row['dow'] !== $dow) {
                    continue;
                }
                $count = (int)$row['liczba'];
                for ($i = 0; $i < $count; $i++) {
                    $litera = chr(65 + ($i % 26));
                    $stmtEmisja->execute([$spotId, $d->format('Y-m-d'), $row['godzina'], $litera]);
                }
            }
        }
    }

    $stmtLog = $pdo->prepare("INSERT INTO integrations_logs (user_id, type, request_id, message) VALUES (?, 'autoplan', ?, ?)");
    $stmtLog->execute([(int)($currentUser['id'] ?? 0), 'spot_' . $spotId, 'Auto-plan emisji: ' . ($overwrite ? 'nadpisanie' : 'dodanie')]);

    $pdo->commit();
    $_SESSION['auto_plan_success'] = 'Auto-plan zapisany.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('auto_plan_spot: ' . $e->getMessage());
    $_SESSION['auto_plan_error'] = 'Nie udało się zapisać auto-planu.';
}

header('Location: ' . BASE_URL . '/edytuj_spot.php?id=' . $spotId);
exit;


