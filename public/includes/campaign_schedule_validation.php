<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/emisje_helpers.php';

function occupancyLimitSecondsPerHourFromLegacy(string $progProcentowy): int
{
    $map = [
        2 => 72,
        3 => 108,
        4 => 144,
        5 => 180,
        6 => 216,
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

function resolveBlockDurationSeconds(array $settings): int
{
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

function normalizeCampaignPlanHour(string $hour): ?string
{
    $hour = trim($hour);
    if ($hour === '>23:00' || $hour === '23:59' || $hour === '23:59:59') {
        return '23:59:59';
    }
    if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $hour, $m)) {
        return sprintf('%02d:%02d:%02d', (int)$m[1], (int)$m[2], isset($m[3]) ? (int)$m[3] : 0);
    }
    return null;
}

function buildCampaignPlanCountsFromGrid(array $grid): array
{
    $plan = [];
    foreach ($grid as $day => $hours) {
        $day = strtolower(trim((string)$day));
        if (!in_array($day, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true) || !is_array($hours)) {
            continue;
        }
        foreach ($hours as $hour => $qtyRaw) {
            $qty = (int)$qtyRaw;
            if ($qty <= 0) {
                continue;
            }
            $normalizedHour = normalizeCampaignPlanHour((string)$hour);
            if ($normalizedHour === null) {
                continue;
            }
            $plan[$day][$normalizedHour] = (int)($plan[$day][$normalizedHour] ?? 0) + $qty;
        }
    }
    return $plan;
}

function buildCampaignValidationDates(string $dataStart, string $dataEnd): array
{
    try {
        $start = new DateTimeImmutable($dataStart);
        $end = new DateTimeImmutable($dataEnd);
    } catch (Throwable $e) {
        return [];
    }

    if ($end < $start) {
        return [];
    }

    $today = new DateTimeImmutable('today');
    if ($start < $today) {
        $start = $today;
    }
    if ($end < $start) {
        return [];
    }

    $out = [];
    for ($day = $start; $day <= $end; $day = $day->modify('+1 day')) {
        $out[] = $day->format('Y-m-d');
    }
    return $out;
}

function buildCampaignDailyDemand(array $planCounts, string $dataStart, string $dataEnd): array
{
    $map = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    $demand = [];
    foreach (buildCampaignValidationDates($dataStart, $dataEnd) as $date) {
        $dt = new DateTimeImmutable($date);
        $dow = $map[(int)$dt->format('N')] ?? null;
        if ($dow === null || empty($planCounts[$dow]) || !is_array($planCounts[$dow])) {
            continue;
        }
        foreach ($planCounts[$dow] as $hour => $qty) {
            $count = (int)$qty;
            if ($count <= 0) {
                continue;
            }
            $demand[$date][$hour] = (int)($demand[$date][$hour] ?? 0) + $count;
        }
    }
    return $demand;
}

function loadCampaignExistingBlockUsage(PDO $pdo, string $dataStart, string $dataEnd): array
{
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
          AND COALESCE(s.status, 'Aktywny') <> 'Nieaktywny'
          AND COALESCE(s.aktywny, 1) = 1
        GROUP BY data_key, godzina_key, blok_key";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':start' => $dataStart,
        ':end' => $dataEnd,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $usage = [];
    foreach ($rows as $row) {
        $date = (string)($row['data_key'] ?? '');
        $hour = (string)($row['godzina_key'] ?? '');
        $block = (string)($row['blok_key'] ?? 'A');
        if ($date === '' || $hour === '') {
            continue;
        }
        $usage[$date][$hour][$block] = (int)($row['suma_sekund'] ?? 0);
    }
    return $usage;
}

function loadCampaignExistingBandUsage(PDO $pdo, string $date): array
{
    $base = calculateBandUsageForDate($pdo, $date);
    return $base['bands'] ?? [
        'Prime' => ['suma_sekund' => 0],
        'Standard' => ['suma_sekund' => 0],
        'Night' => ['suma_sekund' => 0],
    ];
}

function getCampaignBandForTime(string $time): string
{
    return getBandForTime($time);
}

function validateCampaignScheduleAvailability(PDO $pdo, array $grid, int $spotLengthSeconds, string $dataStart, string $dataEnd): array
{
    ensureSpotColumns($pdo);
    ensureEmisjeSpotowTable($pdo);
    ensureSystemConfigColumns($pdo);

    $spotLengthSeconds = max(1, $spotLengthSeconds);
    $planCounts = buildCampaignPlanCountsFromGrid($grid);
    if (!$planCounts) {
        return [];
    }

    $dates = buildCampaignValidationDates($dataStart, $dataEnd);
    if (!$dates) {
        try {
            new DateTimeImmutable($dataStart);
            new DateTimeImmutable($dataEnd);
            return [];
        } catch (Throwable $e) {
            return ['Nieprawidłowy zakres dat kampanii.'];
        }
    }

    $stmtCfg = $pdo->query("SELECT limit_prime_seconds_per_day, limit_standard_seconds_per_day, limit_night_seconds_per_day, liczba_blokow, block_duration_seconds, prog_procentowy
        FROM konfiguracja_systemu WHERE id = 1");
    $cfg = $stmtCfg ? ($stmtCfg->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    $limits = [
        'Prime' => (int)($cfg['limit_prime_seconds_per_day'] ?? 3600),
        'Standard' => (int)($cfg['limit_standard_seconds_per_day'] ?? 3600),
        'Night' => (int)($cfg['limit_night_seconds_per_day'] ?? 3600),
    ];
    $blockLetters = [];
    $blockCount = max(1, min(26, (int)($cfg['liczba_blokow'] ?? 2)));
    for ($i = 0; $i < $blockCount; $i++) {
        $blockLetters[] = chr(65 + $i);
    }
    $blockLimit = resolveBlockDurationSeconds($cfg);
    $existingUsage = loadCampaignExistingBlockUsage($pdo, $dataStart, $dataEnd);
    $dailyDemand = buildCampaignDailyDemand($planCounts, $dataStart, $dataEnd);

    $errors = [];

    foreach ($dates as $date) {
        $baseBandUsage = loadCampaignExistingBandUsage($pdo, $date);
        $deltaBandUsage = [
            'Prime' => 0,
            'Standard' => 0,
            'Night' => 0,
        ];

        foreach ($dailyDemand[$date] ?? [] as $hour => $qty) {
            $count = (int)$qty;
            if ($count <= 0) {
                continue;
            }

            $band = getCampaignBandForTime($hour);
            $deltaBandUsage[$band] += $count * $spotLengthSeconds;

            for ($iteration = 1; $iteration <= $count; $iteration++) {
                $selectedBlock = null;
                $selectedCurrentUsage = null;
                foreach ($blockLetters as $block) {
                    $currentUsage = (int)($existingUsage[$date][$hour][$block] ?? 0);
                    if ($currentUsage + $spotLengthSeconds > $blockLimit) {
                        continue;
                    }
                    if ($selectedBlock === null || $currentUsage < (int)$selectedCurrentUsage) {
                        $selectedBlock = $block;
                        $selectedCurrentUsage = $currentUsage;
                    }
                }

                if ($selectedBlock === null) {
                    $errors[] = sprintf(
                        '%s, slot %s: brak wolnego bloku reklamowego dla emisji %d/%d.',
                        $date,
                        substr($hour, 0, 5),
                        $iteration,
                        $count
                    );
                    break;
                }

                $existingUsage[$date][$hour][$selectedBlock] = (int)($existingUsage[$date][$hour][$selectedBlock] ?? 0) + $spotLengthSeconds;
            }
        }

        foreach ($deltaBandUsage as $band => $deltaSeconds) {
            if ($deltaSeconds <= 0) {
                continue;
            }
            $limitSeconds = (int)($limits[$band] ?? 0);
            if ($limitSeconds <= 0) {
                continue;
            }
            $afterSeconds = (int)($baseBandUsage[$band]['suma_sekund'] ?? 0) + $deltaSeconds;
            if ($afterSeconds > $limitSeconds) {
                $errors[] = sprintf(
                    '%s, pasmo %s: przekroczony limit emisji (%s po zmianie przy limicie %s).',
                    $date,
                    $band,
                    formatSecondsAsMinutes($afterSeconds),
                    formatSecondsAsMinutes($limitSeconds)
                );
            }
        }
    }

    return array_values(array_unique($errors));
}
