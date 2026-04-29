<?php
require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/emisje_helpers.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];
$pageTitle = "Podgląd zajętości pasma";
include 'includes/header.php';

function occupancyLimitSecondsPerHourFromLegacy(string $progProcentowy): int {
    $map = [
        2 => 90,
        7 => 252,
        12 => 432,
        20 => 720,
    ];
    $percent = (int)trim($progProcentowy);
    if ($percent <= 0) {
        $percent = 2;
    }
    if (isset($map[$percent])) {
        return $map[$percent];
    }
    return (int)round(($percent / 100) * 3600);
}

function resolveBlockDurationSeconds(array $settings): int {
    $configured = (int)($settings['block_duration_seconds'] ?? 0);
    if ($configured > 0) {
        return $configured;
    }
    $blocks = max(1, (int)($settings['liczba_blokow'] ?? 2));
    $legacyPerBlock = (int)floor(
        occupancyLimitSecondsPerHourFromLegacy((string)($settings['prog_procentowy'] ?? '0')) / $blocks
    );
    return $legacyPerBlock > 0 ? $legacyPerBlock : 45;
}

function normalizeHourToSlot(string $value): ?string {
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $m)) {
        return null;
    }
    $hour = (int)$m[1];
    $minute = (int)$m[2];
    if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
        return null;
    }
    return sprintf('%02d:00:00', $hour);
}

function buildBlockLetters(int $count): array {
    $letters = [];
    $limit = max(1, min(26, $count));
    for ($i = 0; $i < $limit; $i++) {
        $letters[] = chr(65 + $i);
    }
    return $letters;
}

function pickLeastLoadedBlock(array $slotSeconds, string $dateKey, string $hourSlot, array $blockLetters): string {
    $selected = $blockLetters[0] ?? 'A';
    $selectedLoad = null;
    foreach ($blockLetters as $block) {
        $key = $dateKey . '_' . $hourSlot . '_' . $block;
        $load = (int)($slotSeconds[$key] ?? 0);
        if ($selectedLoad === null || $load < $selectedLoad) {
            $selected = $block;
            $selectedLoad = $load;
        }
    }
    return $selected;
}

function addSlotUsage(array &$slotSeconds, array &$slotCounts, string $dateKey, string $hourSlot, string $blockKey, int $seconds, int $count): void {
    if ($seconds <= 0 || $count <= 0) {
        return;
    }
    $block = strtoupper(substr(trim($blockKey), 0, 1));
    if ($block === '' || !preg_match('/^[A-Z]$/', $block)) {
        $block = 'A';
    }
    $key = $dateKey . '_' . $hourSlot . '_' . $block;
    $slotSeconds[$key] = (int)($slotSeconds[$key] ?? 0) + $seconds;
    $slotCounts[$key] = (int)($slotCounts[$key] ?? 0) + $count;
}

function buildEmptyBandStats(): array {
    return [
        'bands' => [
            'Prime' => ['emisje' => 0, 'suma_sekund' => 0, 'suma_minut' => 0.0],
            'Standard' => ['emisje' => 0, 'suma_sekund' => 0, 'suma_minut' => 0.0],
            'Night' => ['emisje' => 0, 'suma_sekund' => 0, 'suma_minut' => 0.0],
        ],
        'total' => ['emisje' => 0, 'suma_sekund' => 0, 'suma_minut' => 0.0],
    ];
}

// Pobierz ustawienia systemowe
ensureSystemConfigColumns($pdo);
$stmt = $pdo->query("SELECT * FROM konfiguracja_systemu WHERE id = 1");
$ust = $stmt->fetch() ?: [];

$liczba_blokow = max(1, (int)($ust['liczba_blokow'] ?? 2));
$godzina_start = new DateTime((string)($ust['godzina_start'] ?? '07:00:00'));
$godzina_koniec = new DateTime((string)($ust['godzina_koniec'] ?? '21:00:00'));
$blockDurationSeconds = resolveBlockDurationSeconds($ust);
$blockDurationLabel = sprintf('%d:%02d', (int)floor($blockDurationSeconds / 60), $blockDurationSeconds % 60);
$limitPrimeSeconds = (int)($ust['limit_prime_seconds_per_day'] ?? 3600);
$limitStandardSeconds = (int)($ust['limit_standard_seconds_per_day'] ?? 3600);
$limitNightSeconds = (int)($ust['limit_night_seconds_per_day'] ?? 3600);

$viewMode = $_GET['tryb'] ?? 'dzien';
if (!in_array($viewMode, ['dzien', 'miesiac'], true)) {
    $viewMode = 'dzien';
}

$selectedDayInput = (string)($_GET['dzien'] ?? date('Y-m-d'));
$selectedDayObj = DateTime::createFromFormat('Y-m-d', $selectedDayInput);
if (!$selectedDayObj) {
    $selectedDayObj = new DateTime('today');
}
$selectedDay = $selectedDayObj->format('Y-m-d');

// Wybrane miesiąc i rok (stary podgląd bloków)
$miesiacInput = (string)($_GET['miesiac'] ?? date('m'));
$rokInput = (string)($_GET['rok'] ?? date('Y'));
$miesiac = preg_match('/^(0[1-9]|1[0-2])$/', $miesiacInput) ? $miesiacInput : date('m');
$rok = preg_match('/^\d{4}$/', $rokInput) ? $rokInput : date('Y');
if (!isset($_GET['miesiac']) && !isset($_GET['rok'])) {
    $miesiac = $selectedDayObj->format('m');
    $rok = $selectedDayObj->format('Y');
}

// Lista dni miesiąca
$dni = [];
$start = DateTime::createFromFormat('Y-m-d', "$rok-$miesiac-01");
if (!$start) {
    $start = new DateTime(date('Y-m-01'));
    $miesiac = $start->format('m');
    $rok = $start->format('Y');
}
$koniec = (clone $start)->modify('last day of this month');
$monthStart = $start->format('Y-m-d');
$monthEnd = $koniec->format('Y-m-d');
if ($viewMode === 'miesiac' && ($selectedDay < $monthStart || $selectedDay > $monthEnd)) {
    $selectedDay = $monthStart;
}
for ($d = clone $start; $d <= $koniec; $d->modify('+1 day')) {
    $dni[] = $d->format('Y-m-d');
}

$baseParams = [
    'dzien' => $selectedDay,
    'miesiac' => $miesiac,
    'rok' => $rok,
];
$dayLink = '?' . http_build_query(array_merge($baseParams, ['tryb' => 'dzien']));
$monthLink = '?' . http_build_query(array_merge($baseParams, ['tryb' => 'miesiac']));

// Zbuduj mapę zajętości slotów (sekundy + liczba emisji) dla miesiąca.
$slotSeconds = [];
$slotCounts = [];
$spotsWithDailyRows = [];
$maxBlockCountFromData = 0;
$query = "
    SELECT
        e.spot_id AS spot_id,
        e.data AS data_key,
        TIME_FORMAT(e.godzina, '%H:00:00') AS godzina_slot,
        UPPER(COALESCE(NULLIF(TRIM(e.blok), ''), 'A')) AS blok_key,
        COUNT(*) AS emisji_count,
    SUM(CASE 
        WHEN COALESCE(s.dlugosc_s, 0) > 0 THEN s.dlugosc_s
        WHEN COALESCE(CAST(s.dlugosc AS UNSIGNED), 0) > 0 THEN CAST(s.dlugosc AS UNSIGNED)
        ELSE 30 END) AS suma_sek
    FROM spoty_emisje e
    JOIN spoty s ON s.id = e.spot_id
    WHERE e.data BETWEEN ? AND ?
    AND (s.data_start IS NULL OR e.data >= s.data_start)
    AND (s.data_koniec IS NULL OR e.data <= s.data_koniec)
    GROUP BY e.spot_id, data_key, godzina_slot, blok_key
";
$stmt = $pdo->prepare($query);
$stmt->execute([$monthStart, $monthEnd]);

foreach ($stmt as $row) {
    $spotId = (int)($row['spot_id'] ?? 0);
    $dataKey = (string)($row['data_key'] ?? '');
    $hourKey = (string)($row['godzina_slot'] ?? '');
    $blockKey = strtoupper((string)($row['blok_key'] ?? 'A'));
    if ($dataKey === '' || $hourKey === '' || $blockKey === '') {
        continue;
    }
    if ($spotId > 0) {
        $spotsWithDailyRows[$spotId] = true;
    }
    addSlotUsage(
        $slotSeconds,
        $slotCounts,
        $dataKey,
        $hourKey,
        $blockKey,
        (int)($row['suma_sek'] ?? 0),
        (int)($row['emisji_count'] ?? 0)
    );
    if (preg_match('/^[A-Z]$/', $blockKey) === 1) {
        $maxBlockCountFromData = max($maxBlockCountFromData, ord($blockKey) - 64);
    }
}
if ($maxBlockCountFromData > $liczba_blokow) {
    $liczba_blokow = $maxBlockCountFromData;
}
$blockLetters = buildBlockLetters($liczba_blokow);

// Fallback: projektuj zajętość z planu tygodniowego/kampanii dla spotów bez emisji dziennych.
$stmtSpots = $pdo->prepare("
    SELECT
        s.id,
        s.kampania_id,
        s.data_start,
        s.data_koniec,
        CASE
            WHEN COALESCE(s.dlugosc_s, 0) > 0 THEN s.dlugosc_s
            WHEN COALESCE(CAST(s.dlugosc AS UNSIGNED), 0) > 0 THEN CAST(s.dlugosc AS UNSIGNED)
            ELSE 30
        END AS dlugosc_calc
    FROM spoty s
    WHERE COALESCE(s.status, 'Aktywny') <> 'Nieaktywny'
      AND (s.data_start IS NULL OR s.data_start <= ?)
      AND (s.data_koniec IS NULL OR s.data_koniec >= ?)
      AND (COALESCE(s.aktywny, 1) = 1 OR COALESCE(s.status, 'Aktywny') = 'Przyszły')
");
$stmtSpots->execute([$monthEnd, $monthStart]);
$allSpots = $stmtSpots->fetchAll(PDO::FETCH_ASSOC) ?: [];

$spotsToProject = [];
$spotIdsToProject = [];
$campaignIdsToProject = [];
foreach ($allSpots as $spot) {
    $spotId = (int)($spot['id'] ?? 0);
    if ($spotId <= 0 || isset($spotsWithDailyRows[$spotId])) {
        continue;
    }
    $spotsToProject[$spotId] = $spot;
    $spotIdsToProject[] = $spotId;
    $kampaniaId = (int)($spot['kampania_id'] ?? 0);
    if ($kampaniaId > 0) {
        $campaignIdsToProject[$kampaniaId] = $kampaniaId;
    }
}

$weeklyPlanBySpot = [];
if ($spotIdsToProject) {
    $ph = implode(',', array_fill(0, count($spotIdsToProject), '?'));
    $stmtWeekly = $pdo->prepare("
        SELECT spot_id, dow, TIME_FORMAT(godzina, '%H:%i') AS godzina_key, SUM(GREATEST(liczba, 0)) AS qty
        FROM emisje_spotow
        WHERE spot_id IN ($ph)
        GROUP BY spot_id, dow, godzina_key
    ");
    $stmtWeekly->execute($spotIdsToProject);
    $dowToCode = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    foreach ($stmtWeekly as $row) {
        $spotId = (int)($row['spot_id'] ?? 0);
        $dayCode = $dowToCode[(int)($row['dow'] ?? 0)] ?? null;
        $hourKey = (string)($row['godzina_key'] ?? '');
        $qty = max(0, (int)($row['qty'] ?? 0));
        if ($spotId <= 0 || $dayCode === null || $hourKey === '' || $qty <= 0) {
            continue;
        }
        $weeklyPlanBySpot[$spotId][$dayCode][$hourKey] = (int)($weeklyPlanBySpot[$spotId][$dayCode][$hourKey] ?? 0) + $qty;
    }
}

$campaignPlanById = [];
if ($campaignIdsToProject) {
    $campaignIds = array_values($campaignIdsToProject);
    $ph = implode(',', array_fill(0, count($campaignIds), '?'));
    $stmtCampaign = $pdo->prepare("
        SELECT kampania_id, LOWER(dzien_tygodnia) AS day_code, TIME_FORMAT(godzina, '%H:%i') AS godzina_key, SUM(GREATEST(ilosc, 0)) AS qty
        FROM kampanie_emisje
        WHERE kampania_id IN ($ph)
        GROUP BY kampania_id, day_code, godzina_key
    ");
    $stmtCampaign->execute($campaignIds);
    foreach ($stmtCampaign as $row) {
        $kampaniaId = (int)($row['kampania_id'] ?? 0);
        $dayCode = strtolower((string)($row['day_code'] ?? ''));
        $hourKey = (string)($row['godzina_key'] ?? '');
        $qty = max(0, (int)($row['qty'] ?? 0));
        if ($kampaniaId <= 0 || !in_array($dayCode, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true) || $hourKey === '' || $qty <= 0) {
            continue;
        }
        $campaignPlanById[$kampaniaId][$dayCode][$hourKey] = (int)($campaignPlanById[$kampaniaId][$dayCode][$hourKey] ?? 0) + $qty;
    }
}

$dowToCode = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
foreach ($spotsToProject as $spotId => $spot) {
    $spotLength = max(1, (int)($spot['dlugosc_calc'] ?? 30));
    $plan = $weeklyPlanBySpot[$spotId] ?? [];
    if (!$plan) {
        $kampaniaId = (int)($spot['kampania_id'] ?? 0);
        if ($kampaniaId > 0 && !empty($campaignPlanById[$kampaniaId])) {
            $plan = $campaignPlanById[$kampaniaId];
        }
    }
    if (!$plan) {
        continue;
    }

    $spotStart = !empty($spot['data_start']) ? max((string)$spot['data_start'], $monthStart) : $monthStart;
    $spotEnd = !empty($spot['data_koniec']) ? min((string)$spot['data_koniec'], $monthEnd) : $monthEnd;
    if ($spotStart > $spotEnd) {
        continue;
    }

    $activeStart = DateTime::createFromFormat('Y-m-d', $spotStart);
    $activeEnd = DateTime::createFromFormat('Y-m-d', $spotEnd);
    if (!$activeStart || !$activeEnd) {
        continue;
    }
    for ($d = clone $activeStart; $d <= $activeEnd; $d->modify('+1 day')) {
        $dateKey = $d->format('Y-m-d');
        $dayCode = $dowToCode[(int)$d->format('N')] ?? null;
        if ($dayCode === null || empty($plan[$dayCode]) || !is_array($plan[$dayCode])) {
            continue;
        }
        foreach ($plan[$dayCode] as $hourKey => $qty) {
            $hourSlot = normalizeHourToSlot((string)$hourKey);
            $qtyInt = max(0, (int)$qty);
            if ($hourSlot === null || $qtyInt <= 0) {
                continue;
            }
            for ($i = 0; $i < $qtyInt; $i++) {
                $block = pickLeastLoadedBlock($slotSeconds, $dateKey, $hourSlot, $blockLetters);
                addSlotUsage($slotSeconds, $slotCounts, $dateKey, $hourSlot, $block, $spotLength, 1);
            }
        }
    }
}

$emisje = $slotSeconds;

// Podsumowanie pasm liczone z tej samej mapy co tabela (brak rozjazdów).
$bandStats = buildEmptyBandStats();
$summaryLabel = 'Suma dnia (' . $selectedDay . ')';
$summaryLimitFactor = 1;
if ($viewMode === 'miesiac') {
    $summaryLabel = 'Suma miesiąca (' . $monthStart . ' - ' . $monthEnd . ')';
    $summaryLimitFactor = max(1, count($dni));
}
foreach ($slotSeconds as $slotKey => $seconds) {
    $parts = explode('_', $slotKey, 3);
    if (count($parts) !== 3) {
        continue;
    }
    [$dateKeyRaw, $hourSlot] = [$parts[0], $parts[1]];
    if (!preg_match('/^(\d{4}-\d{2}-\d{2})/', $dateKeyRaw, $dateMatch)) {
        continue;
    }
    $dateKey = $dateMatch[1];

    if ($viewMode === 'dzien' && $dateKey !== $selectedDay) {
        continue;
    }
    if ($viewMode === 'miesiac' && ($dateKey < $monthStart || $dateKey > $monthEnd)) {
        continue;
    }
    $band = getBandForTime($hourSlot);
    if (!isset($bandStats['bands'][$band])) {
        continue;
    }
    $count = (int)($slotCounts[$slotKey] ?? 0);
    $bandStats['bands'][$band]['emisje'] += $count;
    $bandStats['bands'][$band]['suma_sekund'] += (int)$seconds;
    $bandStats['total']['emisje'] += $count;
    $bandStats['total']['suma_sekund'] += (int)$seconds;
}
foreach ($bandStats['bands'] as $bandName => $data) {
    $bandStats['bands'][$bandName]['suma_minut'] = round(((int)$data['suma_sekund']) / 60, 1);
}
$bandStats['total']['suma_minut'] = round(((int)$bandStats['total']['suma_sekund']) / 60, 1);
?>

<div class="container-fluid podglad-pasma-page">
    <div class="pasmo-layout mt-3">
        <div class="pasmo-toolbar">
            <?php
            $stmtCompact = $pdo->prepare("
                SELECT s.id, s.nazwa_spotu, k.nazwa_firmy, COALESCE(s.aktywny,1) AS aktywny
                FROM spoty s
                LEFT JOIN klienci k ON k.id = s.klient_id
                WHERE (
                    (MONTH(s.data_start) = ? AND YEAR(s.data_start) = ?)
                    OR (MONTH(s.data_koniec) = ? AND YEAR(s.data_koniec) = ?)
                )
                AND COALESCE(s.status,'Aktywny') = 'Aktywny'
                ORDER BY s.nazwa_spotu
            ");
            $stmtCompact->execute([$miesiac, $rok, $miesiac, $rok]);
            $activeSpotsCompact = $stmtCompact->fetchAll(PDO::FETCH_ASSOC) ?: [];
            ?>
            <div class="pasmo-toolbar__group">
                <span class="pasmo-toolbar__label">Widok:</span>
                <a class="btn-chip <?= $viewMode === 'dzien' ? 'active' : '' ?>" href="<?= htmlspecialchars($dayLink) ?>">Dzień</a>
                <a class="btn-chip <?= $viewMode === 'miesiac' ? 'active' : '' ?>" href="<?= htmlspecialchars($monthLink) ?>">Miesiąc</a>
            </div>

            <form method="get" class="pasmo-toolbar__group pasmo-toolbar__form">
                <input type="hidden" name="tryb" value="<?= htmlspecialchars($viewMode) ?>">
                <?php if ($viewMode === 'dzien'): ?>
                    <span class="pasmo-toolbar__label">Dzień:</span>
                    <input type="date" name="dzien" value="<?= htmlspecialchars($selectedDay) ?>">
                <?php else: ?>
                    <span class="pasmo-toolbar__label">Miesiąc:</span>
                    <select name="miesiac">
                        <?php
                        $miesiacePLToolbar = [
                            1 => 'styczeń', 2 => 'luty', 3 => 'marzec', 4 => 'kwiecień',
                            5 => 'maj', 6 => 'czerwiec', 7 => 'lipiec', 8 => 'sierpień',
                            9 => 'wrzesień', 10 => 'październik', 11 => 'listopad', 12 => 'grudzień'
                        ];
                        for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ($miesiac == $m ? 'selected' : ''); ?>>
                                <?php echo $miesiacePLToolbar[$m]; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                    <span class="pasmo-toolbar__label">Rok:</span>
                    <select name="rok">
                        <?php for ($r = date('Y') - 2; $r <= date('Y') + 1; $r++): ?>
                            <option value="<?php echo $r; ?>" <?php echo ($rok == $r ? 'selected' : ''); ?>><?php echo $r; ?></option>
                        <?php endfor; ?>
                    </select>
                <?php endif; ?>
                <button type="submit" class="btn-toolbar-primary">Pokaż pasma</button>
            </form>

            <div class="pasmo-toolbar__separator"></div>

            <div class="pasmo-toolbar__group">
                <span class="pasmo-toolbar__label">Aktywne:</span>
                <strong><?= count($activeSpotsCompact) ?></strong>
            </div>

            <div class="pasmo-toolbar__separator"></div>

            <div class="pasmo-toolbar__group pasmo-summary">
                <span class="pasmo-toolbar__label">Podsumowanie:</span>
                <?php foreach (['Prime','Standard','Night'] as $band): ?>
                    <?php
                    $baseLimitSeconds = $band === 'Prime'
                        ? $limitPrimeSeconds
                        : ($band === 'Standard' ? $limitStandardSeconds : $limitNightSeconds);
                    $limitSeconds = $baseLimitSeconds * $summaryLimitFactor;
                    $usedSeconds = (int)($bandStats['bands'][$band]['suma_sekund'] ?? 0);
                    $usedMinutes = (float)($bandStats['bands'][$band]['suma_minut'] ?? 0);
                    $isOver = $limitSeconds > 0 && $usedSeconds > $limitSeconds;
                    $isWarn = !$isOver && $limitSeconds > 0 && $usedSeconds >= ($limitSeconds * 0.9);
                    $overByMinutes = $isOver ? round(($usedSeconds - $limitSeconds) / 60, 1) : 0.0;
                    $statusClass = $isOver ? 'is-danger' : ($isWarn ? 'is-warning' : 'is-ok');
                    $statusLabel = $isOver ? '+' . number_format($overByMinutes, 1, '.', ' ') . ' min' : ($isWarn ? 'Uwaga' : 'OK');
                    ?>
                    <span class="pasmo-summary__band"><?= $band ?></span>
                    <span><?= (int)($bandStats['bands'][$band]['emisje'] ?? 0) ?> / <?= number_format($usedMinutes, 1, '.', ' ') ?> min</span>
                    <span class="toolbar-badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="pasmo-top">
            <div class="blok-zakres">
                <div class="controls-bar card">
                    <div class="card-header">
                        <h3 class="card-title">Zakres widoku</h3>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div>
                                <div class="tabs">
                                    <a class="<?= $viewMode === 'dzien' ? 'active' : '' ?>" href="<?= htmlspecialchars($dayLink) ?>">Dzień</a>
                                    <a class="<?= $viewMode === 'miesiac' ? 'active' : '' ?>" href="<?= htmlspecialchars($monthLink) ?>">Miesiąc</a>
                                </div>
                            </div>
                        </div>
                        <div class="view-panels mt-3">
                            <form method="get" class="view-panel <?= $viewMode === 'dzien' ? 'is-active' : '' ?>" data-mode="dzien">
                                <input type="hidden" name="tryb" value="dzien">
                                <div class="row g-2 align-items-end">
                                    <div class="col-12 col-md-6">
                                        <label class="form-label">Dzień (pasma)</label>
                                        <input type="date" name="dzien" class="form-control" value="<?= htmlspecialchars($selectedDay) ?>">
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label">&nbsp;</label>
                                        <button type="submit" class="btn btn-primary w-100">Pokaż pasma</button>
                                    </div>
                                </div>
                            </form>
                            <form method="get" class="view-panel <?= $viewMode === 'miesiac' ? 'is-active' : '' ?>" data-mode="miesiac">
                                <input type="hidden" name="tryb" value="miesiac">
                                <div class="row g-2 align-items-end">
                                    <div class="col-12 col-md-5">
                                        <label class="form-label">Miesiąc</label>
                                        <select name="miesiac" class="form-select">
                                            <?php
                                            $miesiacePL = [
                                                1 => 'styczeń', 2 => 'luty', 3 => 'marzec', 4 => 'kwiecień',
                                                5 => 'maj', 6 => 'czerwiec', 7 => 'lipiec', 8 => 'sierpień',
                                                9 => 'wrzesień', 10 => 'październik', 11 => 'listopad', 12 => 'grudzień'
                                            ];
                                            for ($m = 1; $m <= 12; $m++): ?>
                                                <option value="<?php echo sprintf('%02d', $m); ?>" <?php echo ($miesiac == $m ? 'selected' : ''); ?>>
                                                    <?php echo $miesiacePL[$m]; ?>
                                                </option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-3">
                                        <label class="form-label">Rok</label>
                                        <select name="rok" class="form-select">
                                            <?php for ($r = date('Y') - 2; $r <= date('Y') + 1; $r++): ?>
                                                <option value="<?php echo $r; ?>" <?php echo ($rok == $r ? 'selected' : ''); ?>><?php echo $r; ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <label class="form-label">&nbsp;</label>
                                        <button type="submit" class="btn btn-primary w-100">Pokaż</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="blok-aktywne">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Aktywne spoty</h3>
                    </div>
                    <div class="card-body">
                        <div class="list-group">
                            <?php foreach ($activeSpotsCompact as $spot): ?>
                                <label class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars((string)($spot['nazwa_spotu'] ?? '')); ?></strong><br>
                                        <small><?php echo htmlspecialchars((string)($spot['nazwa_firmy'] ?? '')); ?></small>
                                    </div>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input toggle-status" type="checkbox"
                                               data-id="<?php echo $spot['id']; ?>"
                                               <?php echo $spot['aktywny'] ? 'checked' : ''; ?>>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="blok-podsumowanie">
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Podsumowanie pasm</h3>
                    </div>
                    <div class="card-body">
                        <table class="table table-sm mb-0 summary-table">
                            <thead>
                                <tr>
                                    <th class="text-start">Pasmo</th>
                                    <th class="text-end">Emisji</th>
                                    <th class="text-end">Sekundy</th>
                                    <th class="text-end">Minuty</th>
                                    <th class="text-end">Limit (min)</th>
                                    <th class="text-start">Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (['Prime','Standard','Night'] as $band): ?>
                                    <?php
                                    $baseLimitSeconds = $band === 'Prime'
                                        ? $limitPrimeSeconds
                                        : ($band === 'Standard' ? $limitStandardSeconds : $limitNightSeconds);
                                    $limitSeconds = $baseLimitSeconds * $summaryLimitFactor;
                                    $usedSeconds = (int)($bandStats['bands'][$band]['suma_sekund'] ?? 0);
                                    $limitMinutes = $limitSeconds > 0 ? round($limitSeconds / 60, 1) : 0.0;
                                    $usedMinutes = (float)($bandStats['bands'][$band]['suma_minut'] ?? 0);
                                    $isOver = $limitSeconds > 0 && $usedSeconds > $limitSeconds;
                                    $isWarn = !$isOver && $limitSeconds > 0 && $usedSeconds >= ($limitSeconds * 0.9);
                                    $overByMinutes = $isOver ? round(($usedSeconds - $limitSeconds) / 60, 1) : 0.0;
                                    $statusClass = $isOver ? 'badge-danger' : ($isWarn ? 'badge-warning' : 'badge-success');
                                    $statusLabel = $isOver ? 'Przekroczono +' . number_format($overByMinutes, 1, '.', ' ') . ' min' : ($isWarn ? 'Ostrzeżenie' : 'OK');
                                    ?>
                                    <tr>
                                        <td class="text-start fw-semibold"><?= $band ?></td>
                                        <td class="text-end"><?= (int)($bandStats['bands'][$band]['emisje'] ?? 0) ?></td>
                                        <td class="text-end"><?= $usedSeconds ?></td>
                                        <td class="text-end"><?= number_format($usedMinutes, 1, '.', ' ') ?></td>
                                        <td class="text-end"><?= number_format($limitMinutes, 1, '.', ' ') ?></td>
                                        <td class="text-start"><span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <div class="summary-footer text-muted small">
                            <?= htmlspecialchars($summaryLabel) ?>: <?= (int)($bandStats['total']['emisje'] ?? 0) ?> emisji,
                            <?= (int)($bandStats['total']['suma_sekund'] ?? 0) ?> s,
                            <?= number_format((float)($bandStats['total']['suma_minut'] ?? 0), 1, '.', ' ') ?> min
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="pasmo-table">
            <div class="card">
                <div class="card-header">Podgląd zajętości</div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        W podglądzie pasma widoczne są wszystkie spoty: emisje dzienne oraz spoty z planem tygodniowym/kampanii (nawet gdy emisje dzienne nie zostały jeszcze wygenerowane).
                        Długość bloku: <strong><?= htmlspecialchars($blockDurationLabel) ?> min</strong>,
                        liczba bloków/h: <strong><?= (int)$liczba_blokow ?></strong>.
                    </div>
                    <div class="table-legend small text-muted mb-3">
                        <span class="legend-item"><span class="legend-swatch cell-muted"></span>0s = brak emisji</span>
                        <span class="legend-item"><span class="legend-swatch cell-ok"></span>1-30s = bezpiecznie</span>
                        <span class="legend-item"><span class="legend-swatch cell-warn"></span>31-60s = ostrzeżenie</span>
                        <span class="legend-item"><span class="legend-swatch cell-hot"></span>>60s = przekroczenie</span>
                    </div>
                    <div class="table-scroll">
                        <table class="table table-bordered table-zebra occupancy-table align-middle">
                            <thead>
                                <tr>
                                    <th class="sticky-th sticky-col col-label">Godzina / Blok</th>
                                    <?php foreach ($dni as $dzien): ?>
                                        <th class="sticky-th col-day"><?php echo date('d.m', strtotime($dzien)); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                for ($czas = clone $godzina_start; $czas <= $godzina_koniec; $czas->modify('+1 hour')) {
                                    for ($b = 0; $b < $liczba_blokow; $b++) {
                                        $litera = chr(65 + $b);
                                        echo '<tr>';
                                        echo '<th class="sticky-col col-label">' . $czas->format('H:i') . ' – Blok ' . $litera . '</th>';
                                        foreach ($dni as $dzien) {
                                            $klucz = $dzien . '_' . $czas->format('H:i:s') . '_' . $litera;
                                            $suma = (int)($emisje[$klucz] ?? 0);
                                            $procent = ($blockDurationSeconds > 0) ? (int)round(($suma / $blockDurationSeconds) * 100) : 0;
                                            if ($suma === 0) {
                                                $cellClass = 'cell-muted';
                                            } elseif ($suma <= 30) {
                                                $cellClass = 'cell-ok';
                                            } elseif ($suma <= 60) {
                                                $cellClass = 'cell-warn';
                                            } else {
                                                $cellClass = 'cell-hot';
                                            }
                                            echo '<td class="' . $cellClass . ' col-day" title="' . $suma . 's | ' . $procent . '% limitu bloku">' . $suma . 's</td>';
                                        }
                                        echo '</tr>';
                                    }
                                }
                                ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

<script>
document.querySelectorAll('.toggle-status').forEach(function (checkbox) {
    checkbox.addEventListener('change', function () {
        const spotId = this.dataset.id;
        const isActive = this.checked ? 1 : 0;

        fetch('toggle_spot_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `spot_id=${spotId}&status=${isActive}`
        })
        .then(res => res.text())
        .then(resp => {
            console.log("ODPOWIEDŹ:", resp);
            window.location.reload();
        })
        .catch(err => {
            alert("Błąd podczas zmiany statusu.");
            this.checked = !isActive;
        });
    });
});

const testBtn = document.getElementById('testBtn');
if (testBtn) {
    testBtn.addEventListener('click', function () {
        fetch('toggle_spot_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'spot_id=1&status=0'
        })
        .then(response => response.text())
        .then(data => {
            document.getElementById('testOutput').textContent = "Odpowiedź serwera:\n" + data;
        })
        .catch(error => {
            document.getElementById('testOutput').textContent = "Błąd:\n" + error;
        });
    });
}
</script>
