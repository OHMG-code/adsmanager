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
ensureClientLeadColumns($pdo);
ensureSpotAudioFilesTable($pdo);
ensureIntegrationsLogsTable($pdo);

$dateFrom = $_GET['data_od'] ?? '';
$dateTo = $_GET['data_do'] ?? '';
$clientFilter = isset($_GET['klient_id']) && $_GET['klient_id'] !== '' ? (int)$_GET['klient_id'] : null;
$campaignFilter = isset($_GET['kampania_id']) && $_GET['kampania_id'] !== '' ? (int)$_GET['kampania_id'] : null;
$onlyActive = !isset($_GET['tylko_aktywne']) || $_GET['tylko_aktywne'] === '1';

$fromDate = DateTime::createFromFormat('Y-m-d', $dateFrom);
$toDate = DateTime::createFromFormat('Y-m-d', $dateTo);
if (!$fromDate || !$toDate) {
    http_response_code(400);
    echo 'Nieprawidłowy zakres dat.';
    exit;
}
if ($toDate < $fromDate) {
    http_response_code(400);
    echo 'Data do nie może być wcześniejsza niż data od.';
    exit;
}

$dateMap = [];
for ($d = clone $fromDate; $d <= $toDate; $d->modify('+1 day')) {
    $dow = (int)$d->format('N');
    $dateMap[$dow][] = $d->format('Y-m-d');
}

$spotCols = getTableColumns($pdo, 'spoty');
$kampCols = tableExists($pdo, 'kampanie') ? getTableColumns($pdo, 'kampanie') : [];
$clientCols = tableExists($pdo, 'klienci') ? getTableColumns($pdo, 'klienci') : [];

$select = [
    'e.dow',
    'e.godzina',
    'e.liczba',
    's.id AS spot_id',
    's.nazwa_spotu',
    hasColumn($spotCols, 'dlugosc_s') ? 's.dlugosc_s' : 'NULL AS dlugosc_s',
    hasColumn($spotCols, 'dlugosc') ? 's.dlugosc' : 'NULL AS dlugosc',
    hasColumn($spotCols, 'data_start') ? 's.data_start' : 'NULL AS data_start',
    hasColumn($spotCols, 'data_koniec') ? 's.data_koniec' : 'NULL AS data_koniec',
    hasColumn($spotCols, 'status') ? 's.status' : 'NULL AS status',
    hasColumn($spotCols, 'kampania_id') ? 's.kampania_id' : 'NULL AS kampania_id',
    hasColumn($spotCols, 'klient_id') ? 's.klient_id' : 'NULL AS klient_id',
    hasColumn($kampCols, 'owner_user_id') ? 'k.owner_user_id AS kampania_owner' : 'NULL AS kampania_owner',
    hasColumn($kampCols, 'klient_nazwa') ? 'k.klient_nazwa' : 'NULL AS kampania_klient_nazwa',
    hasColumn($clientCols, 'nazwa_firmy') ? 'COALESCE(co.name_full, co.name_short, c.nazwa_firmy) AS klient_nazwa' : 'NULL AS klient_nazwa',
];

$where = ['1=1'];
$params = [];

if ($onlyActive) {
    if (hasColumn($spotCols, 'status')) {
        $where[] = "COALESCE(s.status, 'Aktywny') <> 'Nieaktywny'";
    }
    if (hasColumn($spotCols, 'aktywny')) {
        $where[] = '(s.aktywny IS NULL OR s.aktywny = 1)';
    }
}

if ($clientFilter) {
    $parts = [];
    if (hasColumn($spotCols, 'klient_id')) {
        $parts[] = 's.klient_id = :client_id';
    }
    if (hasColumn($kampCols, 'klient_id')) {
        $parts[] = 'k.klient_id = :client_id';
    }
    if ($parts) {
        $where[] = '(' . implode(' OR ', $parts) . ')';
        $params[':client_id'] = $clientFilter;
    }
}

if ($campaignFilter) {
    if (hasColumn($spotCols, 'kampania_id')) {
        $where[] = 's.kampania_id = :campaign_id';
        $params[':campaign_id'] = $campaignFilter;
    }
}

if ($currentUser && normalizeRole($currentUser) === 'Handlowiec') {
    $ownerId = (int)($currentUser['id'] ?? 0);
    if ($ownerId > 0 && hasColumn($kampCols, 'owner_user_id')) {
        $where[] = 'k.owner_user_id = :owner_id';
        $params[':owner_id'] = $ownerId;
    }
}

$sql = 'SELECT ' . implode(', ', $select) . ' FROM emisje_spotow e JOIN spoty s ON s.id = e.spot_id ';
if (tableExists($pdo, 'kampanie')) {
    $sql .= 'LEFT JOIN kampanie k ON k.id = s.kampania_id ';
}
if (tableExists($pdo, 'klienci')) {
    $sql .= 'LEFT JOIN klienci c ON c.id = s.klient_id ';
    $sql .= 'LEFT JOIN companies co ON co.id = c.company_id ';
}
$sql .= "JOIN spot_audio_files saf ON saf.spot_id = s.id AND saf.is_active = 1 AND saf.production_status = 'Zaakceptowany' ";
$sql .= 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$rowsByDow = [];
foreach ($rows as $row) {
    $dow = (int)$row['dow'];
    $rowsByDow[$dow][] = $row;
}

function csvEscape(string $value): string {
    $needs = strpbrk($value, ";\n\r\"") !== false;
    $escaped = str_replace('"', '""', $value);
    return $needs ? '"' . $escaped . '"' : $escaped;
}

function csvLine(array $fields): string {
    $out = [];
    foreach ($fields as $field) {
        $out[] = csvEscape((string)$field);
    }
    return implode(';', $out) . "\r\n";
}

$filename = sprintf('raport_emisji_%s_%s.csv', $fromDate->format('Ymd'), $toDate->format('Ymd'));
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo "\xEF\xBB\xBF";
echo csvLine(['data_emisji', 'godzina', 'pasmo', 'klient', 'kampania', 'spot', 'dlugosc_s', 'liczba_emisji', 'suma_sekund']);

foreach ($dateMap as $dow => $dates) {
    if (empty($rowsByDow[$dow])) {
        continue;
    }
    foreach ($dates as $date) {
        foreach ($rowsByDow[$dow] as $row) {
            if (!empty($row['data_start']) && $date < $row['data_start']) {
                continue;
            }
            if (!empty($row['data_koniec']) && $date > $row['data_koniec']) {
                continue;
            }

            $godzina = (string)$row['godzina'];
            $godzina = strlen($godzina) >= 5 ? substr($godzina, 0, 5) : $godzina;
            $band = getBandForTime($godzina);
            $length = (int)($row['dlugosc_s'] ?? 0);
            if ($length <= 0 && isset($row['dlugosc'])) {
                $length = (int)$row['dlugosc'];
            }
            if ($length <= 0) {
                $length = 30;
            }
            $count = (int)($row['liczba'] ?? 0);
            if ($count <= 0) {
                continue;
            }

            $clientName = $row['klient_nazwa'] ?? '';
            if ($clientName === '' && !empty($row['kampania_klient_nazwa'])) {
                $clientName = $row['kampania_klient_nazwa'];
            }
            $kampaniaLabel = !empty($row['kampania_id']) ? ('#' . $row['kampania_id']) : '';
            if (!empty($row['kampania_klient_nazwa'])) {
                $kampaniaLabel = trim($kampaniaLabel . ' ' . $row['kampania_klient_nazwa']);
            }
            $spotLabel = trim((string)$row['nazwa_spotu']);
            if (!empty($row['spot_id'])) {
                $spotLabel .= ' (#' . $row['spot_id'] . ')';
            }

            $sumSeconds = $count * $length;
            echo csvLine([
                $date,
                $godzina,
                $band,
                $clientName,
                $kampaniaLabel,
                $spotLabel,
                $length,
                $count,
                $sumSeconds,
            ]);
        }
    }
}

try {
    $stmtLog = $pdo->prepare("INSERT INTO integrations_logs (user_id, type, request_id, message) VALUES (?, 'report', ?, ?)");
    $stmtLog->execute([(int)($currentUser['id'] ?? 0), 'raport_emisji', 'Raport emisji CSV ' . $dateFrom . ' - ' . $dateTo]);
} catch (Throwable $e) {
    error_log('raport_emisji_csv: log failed: ' . $e->getMessage());
}

exit;

