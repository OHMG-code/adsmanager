<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db_schema.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Wymagane logowanie.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Nie mozna zweryfikowac uzytkownika.'], JSON_UNESCAPED_UNICODE);
    exit;
}

ensureKampanieOwnershipColumns($pdo);

$kampaniaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($kampaniaId <= 0) {
    echo json_encode(['success' => false, 'error' => 'Nieprawidlowe ID kampanii.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$kampaniaCols = getTableColumns($pdo, 'kampanie');
[$ownerWhere, $ownerParams] = ownerConstraint($kampaniaCols, $currentUser, 'k');

$where = ['k.id = :id'];
$params = [':id' => $kampaniaId];
if ($ownerWhere !== '') {
    $where[] = $ownerWhere;
    $params = array_merge($params, $ownerParams);
}
if (hasColumn($kampaniaCols, 'status')) {
    $where[] = "COALESCE(k.status, 'W realizacji') <> 'Zakończona'";
}

$sql = "SELECT
            k.id,
            k.klient_id,
            k.klient_nazwa,
            k.dlugosc_spotu,
            k.data_start,
            k.data_koniec,
            COALESCE(c.name_full, c.name_short, kl.nazwa_firmy, k.klient_nazwa) AS klient_label
        FROM kampanie k
        LEFT JOIN klienci kl ON kl.id = k.klient_id
        LEFT JOIN companies c ON c.id = kl.company_id
        WHERE " . implode(' AND ', $where) . "
        LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$kampania = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$kampania) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Nie znaleziono kampanii.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$emisja = [];
try {
    $stmtE = $pdo->prepare("SELECT dzien_tygodnia, TIME_FORMAT(godzina, '%H:%i') AS godzina, ilosc
        FROM kampanie_emisje
        WHERE kampania_id = :id");
    $stmtE->execute([':id' => $kampaniaId]);
    $rows = $stmtE->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $day = strtolower(trim((string)($row['dzien_tygodnia'] ?? '')));
        $hour = trim((string)($row['godzina'] ?? ''));
        $qty = (int)($row['ilosc'] ?? 0);
        if (!in_array($day, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true) || $hour === '' || $qty <= 0) {
            continue;
        }
        if (!isset($emisja[$day])) {
            $emisja[$day] = [];
        }
        $emisja[$day][$hour] = (int)($emisja[$day][$hour] ?? 0) + $qty;
    }
} catch (Throwable $e) {
    error_log('kampania_details: cannot load kampanie_emisje: ' . $e->getMessage());
}

echo json_encode([
    'success' => true,
    'data' => [
        'id' => (int)$kampania['id'],
        'klient_id' => isset($kampania['klient_id']) ? (int)$kampania['klient_id'] : null,
        'klient_nazwa' => (string)($kampania['klient_nazwa'] ?? ''),
        'klient_label' => (string)($kampania['klient_label'] ?? ''),
        'dlugosc_spotu' => isset($kampania['dlugosc_spotu']) ? (int)$kampania['dlugosc_spotu'] : null,
        'data_start' => (string)($kampania['data_start'] ?? ''),
        'data_koniec' => (string)($kampania['data_koniec'] ?? ''),
        'emisja' => $emisja,
    ],
], JSON_UNESCAPED_UNICODE);
exit;

