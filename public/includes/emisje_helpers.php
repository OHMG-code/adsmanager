<?php
declare(strict_types=1);

if (!function_exists('normalizeRole')) {
    @require_once __DIR__ . '/auth.php';
}
require_once __DIR__ . '/db_schema.php';

function getBandForTime(string $time): string
{
    $time = trim($time);
    if ($time === '') {
        return 'Standard';
    }

    if (preg_match('/^(\\d{1,2}):(\\d{2})(?::(\\d{2}))?$/', $time, $m)) {
        $hour = (int)$m[1];
        $minute = (int)$m[2];
    } else {
        $parsed = strtotime($time);
        if ($parsed === false) {
            return 'Standard';
        }
        $hour = (int)date('G', $parsed);
        $minute = (int)date('i', $parsed);
    }

    $totalMinutes = ($hour * 60) + $minute;
    $inRange = static fn(int $fromHour, int $fromMin, int $toHour, int $toMin): bool =>
        $totalMinutes >= ($fromHour * 60 + $fromMin) && $totalMinutes <= ($toHour * 60 + $toMin);

    if ($inRange(6, 0, 9, 59) || $inRange(15, 0, 18, 59)) {
        return 'Prime';
    }
    if ($inRange(10, 0, 14, 59) || $inRange(19, 0, 22, 59)) {
        return 'Standard';
    }

    return 'Night';
}

function calculateBandUsageFromEmisjaGridForDate(array $emisja, string $date, int $spotLengthSeconds): array
{
    $day = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime($date);
    $dow = (int)$day->format('N');
    $map = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    $code = $map[$dow] ?? null;

    $bands = [
        'Prime' => ['count' => 0, 'seconds' => 0],
        'Standard' => ['count' => 0, 'seconds' => 0],
        'Night' => ['count' => 0, 'seconds' => 0],
    ];

    if (!$code || empty($emisja[$code]) || !is_array($emisja[$code])) {
        return $bands;
    }

    foreach ($emisja[$code] as $godzina => $bloki) {
        if (!is_array($bloki)) {
            continue;
        }
        $count = 0;
        foreach ($bloki as $val) {
            if ((string)$val === '0') {
                continue;
            }
            $count++;
        }
        if ($count <= 0) {
            continue;
        }
        $godzinaFull = strlen($godzina) === 5 ? $godzina . ':00' : $godzina;
        $band = getBandForTime($godzinaFull);
        $bands[$band]['count'] += $count;
        $bands[$band]['seconds'] += $count * $spotLengthSeconds;
    }

    return $bands;
}

function calculateBandUsageFromPlanRowsForDate(array $rows, string $date, int $spotLengthSeconds): array
{
    $day = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime($date);
    $dow = (int)$day->format('N');

    $bands = [
        'Prime' => ['count' => 0, 'seconds' => 0],
        'Standard' => ['count' => 0, 'seconds' => 0],
        'Night' => ['count' => 0, 'seconds' => 0],
    ];

    foreach ($rows as $row) {
        if ((int)($row['dow'] ?? 0) !== $dow) {
            continue;
        }
        $count = (int)($row['liczba'] ?? 0);
        if ($count <= 0) {
            continue;
        }
        $time = (string)($row['godzina'] ?? '');
        if ($time === '') {
            continue;
        }
        $time = strlen($time) === 5 ? $time . ':00' : $time;
        $band = getBandForTime($time);
        $bands[$band]['count'] += $count;
        $bands[$band]['seconds'] += $count * $spotLengthSeconds;
    }

    return $bands;
}

function hasApprovedAudio(PDO $pdo, int $spotId): bool
{
    try {
        ensureSpotAudioFilesTable($pdo);
        $stmt = $pdo->prepare("SELECT id FROM spot_audio_files WHERE spot_id = ? AND is_active = 1 AND production_status = 'Zaakceptowany' LIMIT 1");
        $stmt->execute([$spotId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('emisje_helpers: audio check failed: ' . $e->getMessage());
        return false;
    }
}

function canAccessSpot(PDO $pdo, int $spotId, array $user): bool
{
    if (normalizeRole($user) !== 'Handlowiec') {
        return true;
    }
    $spotCols = getTableColumns($pdo, 'spoty');
    $select = [
        's.id',
        's.kampania_id',
        hasColumn($spotCols, 'owner_user_id') ? 's.owner_user_id' : 'NULL AS owner_user_id',
        'k.owner_user_id AS kampania_owner',
    ];
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM spoty s LEFT JOIN kampanie k ON k.id = s.kampania_id WHERE s.id = ? LIMIT 1');
    $stmt->execute([$spotId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return false;
    }
    if (!empty($row['kampania_owner']) && (int)$row['kampania_owner'] === $userId) {
        return true;
    }
    if (!empty($row['owner_user_id']) && (int)$row['owner_user_id'] === $userId) {
        return true;
    }
    return false;
}

function calculateBandUsageForDate(PDO $pdo, string $date, ?array $currentUser = null, array $options = []): array
{
    try {
        ensureSpotColumns($pdo);
        ensureEmisjeSpotowTable($pdo);
        ensureKampanieOwnershipColumns($pdo);
        ensureSpotAudioFilesTable($pdo);
    } catch (Throwable $e) {
        error_log('emisje_helpers: ensure schema failed: ' . $e->getMessage());
    }

    $day = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime($date);
    $normalizedDate = $day->format('Y-m-d');
    $dow = (int)$day->format('N'); // 1 (Mon) ... 7 (Sun)

    $bands = [
        'Prime'    => ['emisje' => 0, 'suma_sekund' => 0],
        'Standard' => ['emisje' => 0, 'suma_sekund' => 0],
        'Night'    => ['emisje' => 0, 'suma_sekund' => 0],
    ];
    $total = ['emisje' => 0, 'suma_sekund' => 0];

    $spotCols = getTableColumns($pdo, 'spoty');
    $kampCols = tableExists($pdo, 'kampanie') ? getTableColumns($pdo, 'kampanie') : [];
    $clientCols = tableExists($pdo, 'klienci') ? getTableColumns($pdo, 'klienci') : [];

    $select = [
        's.id AS spot_id',
        hasColumn($spotCols, 'dlugosc_s') ? 's.dlugosc_s' : 'NULL AS dlugosc_s',
        hasColumn($spotCols, 'dlugosc') ? 's.dlugosc' : 'NULL AS dlugosc',
        hasColumn($spotCols, 'kampania_id') ? 's.kampania_id' : 'NULL AS kampania_id',
        hasColumn($spotCols, 'klient_id') ? 's.klient_id' : 'NULL AS klient_id',
    ];
    if (hasColumn($kampCols, 'owner_user_id')) {
        $select[] = 'k.owner_user_id AS kampania_owner';
    }
    if (hasColumn($clientCols, 'owner_user_id')) {
        $select[] = 'c.owner_user_id AS klient_owner';
    }

    $where = [
        '(s.data_start IS NULL OR s.data_start <= :day)',
        '(s.data_koniec IS NULL OR s.data_koniec >= :day)',
    ];
    if (hasColumn($spotCols, 'status')) {
        $where[] = "COALESCE(s.status, 'Aktywny') <> 'Nieaktywny'";
    }
    if (hasColumn($spotCols, 'aktywny')) {
        $where[] = '(s.aktywny IS NULL OR s.aktywny = 1)';
    }
    if (tableExists($pdo, 'spot_audio_files')) {
        $where[] = "EXISTS (
            SELECT 1 FROM spot_audio_files saf
            WHERE saf.spot_id = s.id
              AND saf.is_active = 1
              AND saf.production_status = 'Zaakceptowany'
        )";
    }
    if (!empty($options['exclude_spot_id'])) {
        $where[] = 's.id <> :exclude_spot_id';
    }

    $params = [':day' => $normalizedDate];
    if (!empty($options['exclude_spot_id'])) {
        $params[':exclude_spot_id'] = (int)$options['exclude_spot_id'];
    }
    $ownerFilters = [];
    if ($currentUser && function_exists('normalizeRole') && normalizeRole($currentUser) === 'Handlowiec') {
        $ownerId = (int)($currentUser['id'] ?? 0);
        if ($ownerId > 0) {
            if (hasColumn($kampCols, 'owner_user_id')) {
                $ownerFilters[] = 'k.owner_user_id = :owner_id';
            }
            if (hasColumn($spotCols, 'owner_user_id')) {
                $ownerFilters[] = 's.owner_user_id = :owner_id';
            }
            if (hasColumn($clientCols, 'owner_user_id')) {
                $ownerFilters[] = 'c.owner_user_id = :owner_id';
            }
            if ($ownerFilters) {
                $params[':owner_id'] = $ownerId;
                $where[] = '(' . implode(' OR ', $ownerFilters) . ')';
            }
        }
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM spoty s ';
    if (tableExists($pdo, 'kampanie')) {
        $sql .= 'LEFT JOIN kampanie k ON k.id = s.kampania_id ';
    }
    if (tableExists($pdo, 'klienci')) {
        $sql .= 'LEFT JOIN klienci c ON c.id = s.klient_id ';
    }
    $sql .= 'WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $spots = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!$spots) {
        foreach ($bands as &$band) {
            $band['suma_minut'] = 0.0;
        }
        $total['suma_minut'] = 0.0;
        return ['bands' => $bands, 'total' => $total];
    }

    $spotMeta = [];
    foreach ($spots as $spot) {
        $len = (int)($spot['dlugosc_s'] ?? 0);
        if ($len <= 0 && isset($spot['dlugosc'])) {
            $len = (int)$spot['dlugosc'];
        }
        if ($len <= 0) {
            $len = 30;
        }
        $spotMeta[(int)$spot['spot_id']] = $len;
    }
    $spotIds = array_keys($spotMeta);

    $placeholders = implode(',', array_fill(0, count($spotIds), '?'));
    $seenSpots = [];

    if ($placeholders !== '') {
        $sqlWeekly = "SELECT spot_id, godzina, SUM(liczba) AS total FROM emisje_spotow WHERE dow = ? AND spot_id IN ($placeholders) GROUP BY spot_id, godzina";
        $stmtWeekly = $pdo->prepare($sqlWeekly);
        $stmtWeekly->execute(array_merge([$dow], $spotIds));
        while ($row = $stmtWeekly->fetch(PDO::FETCH_ASSOC)) {
            $spotId = (int)$row['spot_id'];
            $seenSpots[$spotId] = true;
            $band = getBandForTime((string)$row['godzina']);
            $emisjeCount = (int)$row['total'];
            $seconds = $emisjeCount * ($spotMeta[$spotId] ?? 30);

            $bands[$band]['emisje'] += $emisjeCount;
            $bands[$band]['suma_sekund'] += $seconds;
            $total['emisje'] += $emisjeCount;
            $total['suma_sekund'] += $seconds;
        }

        $remaining = array_values(array_diff($spotIds, array_keys($seenSpots)));
        if ($remaining) {
            $ph = implode(',', array_fill(0, count($remaining), '?'));
            $sqlDaily = "SELECT spot_id, godzina, COUNT(*) AS total FROM spoty_emisje WHERE data = ? AND spot_id IN ($ph) GROUP BY spot_id, godzina";
            $stmtDaily = $pdo->prepare($sqlDaily);
            $stmtDaily->execute(array_merge([$normalizedDate], $remaining));
            while ($row = $stmtDaily->fetch(PDO::FETCH_ASSOC)) {
                $spotId = (int)$row['spot_id'];
                $band = getBandForTime((string)$row['godzina']);
                $emisjeCount = (int)$row['total'];
                $seconds = $emisjeCount * ($spotMeta[$spotId] ?? 30);

                $bands[$band]['emisje'] += $emisjeCount;
                $bands[$band]['suma_sekund'] += $seconds;
                $total['emisje'] += $emisjeCount;
                $total['suma_sekund'] += $seconds;
            }
        }
    }

    foreach ($bands as &$band) {
        $band['suma_minut'] = round($band['suma_sekund'] / 60, 1);
    }
    unset($band);
    $total['suma_minut'] = round($total['suma_sekund'] / 60, 1);

    return ['bands' => $bands, 'total' => $total];
}

function calculateBandOccupancy(PDO $pdo, string $date, ?array $currentUser = null): array
{
    return calculateBandUsageForDate($pdo, $date, $currentUser);
}

function runMaintenanceIfDue(PDO $pdo): void
{
    try {
        ensureSystemConfigColumns($pdo);
        ensureSpotColumns($pdo);
        ensureEmisjeSpotowTable($pdo);
        ensureKampanieOwnershipColumns($pdo);
    } catch (Throwable $e) {
        error_log('emisje_helpers: maintenance schema ensure failed: ' . $e->getMessage());
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO konfiguracja_systemu (id, maintenance_interval_minutes) VALUES (1, 10)
            ON DUPLICATE KEY UPDATE id = id");
        $stmt = $pdo->query("SELECT maintenance_last_run_at, maintenance_interval_minutes FROM konfiguracja_systemu WHERE id = 1 FOR UPDATE");
        $cfg = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
        $intervalMinutes = max(1, (int)($cfg['maintenance_interval_minutes'] ?? 10));
        $lastRunAt = $cfg['maintenance_last_run_at'] ?? null;

        $due = false;
        if (!$lastRunAt) {
            $due = true;
        } else {
            $last = new DateTime($lastRunAt);
            $next = (clone $last)->modify('+' . $intervalMinutes . ' minutes');
            $due = $next <= new DateTime();
        }

        if (!$due) {
            $pdo->commit();
            return;
        }

        $spotCols = getTableColumns($pdo, 'spoty');
        if (hasColumn($spotCols, 'data_koniec')) {
            $updates = [];
            if (hasColumn($spotCols, 'status')) {
                $updates[] = "status = 'Nieaktywny'";
            }
            if (hasColumn($spotCols, 'aktywny')) {
                $updates[] = 'aktywny = 0';
            }
            if ($updates) {
                $pdo->exec('UPDATE spoty SET ' . implode(', ', $updates) . ' WHERE data_koniec IS NOT NULL AND data_koniec < CURDATE()');
            }
        }

        if (!tableExists($pdo, 'kampanie')) {
            $pdo->prepare('UPDATE konfiguracja_systemu SET maintenance_last_run_at = NOW() WHERE id = 1')->execute();
            $pdo->commit();
            return;
        }
        $kampCols = getTableColumns($pdo, 'kampanie');
        if (!hasColumn($kampCols, 'status') || !hasColumn($spotCols, 'kampania_id')) {
            $pdo->prepare('UPDATE konfiguracja_systemu SET maintenance_last_run_at = NOW() WHERE id = 1')->execute();
            $pdo->commit();
            return;
        }

        $spotStatusFilter = hasColumn($spotCols, 'status')
            ? "COALESCE(s.status, 'Aktywny') <> 'Nieaktywny'"
            : '1=1';
        $spotActiveDate = hasColumn($spotCols, 'data_koniec')
            ? "(s.data_koniec IS NULL OR s.data_koniec >= CURDATE())"
            : '1=1';

        $sql = "
            UPDATE kampanie k
            SET k.status = 'Zakończona'
            WHERE k.status = 'W realizacji'
              AND NOT EXISTS (
                SELECT 1 FROM spoty s
                WHERE s.kampania_id = k.id
                  AND {$spotStatusFilter}
                  AND {$spotActiveDate}
            )";
        $pdo->exec($sql);

        $pdo->prepare('UPDATE konfiguracja_systemu SET maintenance_last_run_at = NOW() WHERE id = 1')->execute();
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('emisje_helpers: maintenance failed: ' . $e->getMessage());
    }
}

function runAutoDeactivate(PDO $pdo, int $throttleSeconds = 300): void
{
    // Deprecated: keep compatibility with older calls.
    runMaintenanceIfDue($pdo);
}
