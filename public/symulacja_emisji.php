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

$spotId = isset($_GET['spot_id']) ? (int)$_GET['spot_id'] : 0;
if ($spotId <= 0) {
    echo 'Nieprawidłowy identyfikator spotu.';
    exit;
}

if (!canAccessSpot($pdo, $spotId, $currentUser)) {
    http_response_code(403);
    echo 'Brak uprawnień do tego spotu.';
    exit;
}

$stmtSpot = $pdo->prepare("SELECT s.*, k.klient_nazwa, k.id AS kampania_id FROM spoty s LEFT JOIN kampanie k ON k.id = s.kampania_id WHERE s.id = ? LIMIT 1");
$stmtSpot->execute([$spotId]);
$spot = $stmtSpot->fetch(PDO::FETCH_ASSOC);
if (!$spot) {
    echo 'Spot nie został znaleziony.';
    exit;
}

$length = (int)($spot['dlugosc_s'] ?? 0);
if ($length <= 0 && isset($spot['dlugosc'])) {
    $length = (int)$spot['dlugosc'];
}
if ($length <= 0) {
    $length = 30;
}

$dataStart = $spot['data_start'] ?? date('Y-m-d');
$dataEnd = $spot['data_koniec'] ?? date('Y-m-d');
$start = DateTime::createFromFormat('Y-m-d', $dataStart) ?: new DateTime();
$end = DateTime::createFromFormat('Y-m-d', $dataEnd) ?: new DateTime();
$today = new DateTime('today');
if ($start < $today) {
    $start = clone $today;
}
$sampleEnd = (clone $start)->modify('+13 days');
if ($end < $sampleEnd) {
    $sampleEnd = $end;
}

$stmtRows = $pdo->prepare("SELECT dow, godzina, liczba FROM emisje_spotow WHERE spot_id = ?");
$stmtRows->execute([$spotId]);
$planRows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

$bandsTotals = [
    'Prime' => ['count' => 0, 'seconds' => 0],
    'Standard' => ['count' => 0, 'seconds' => 0],
    'Night' => ['count' => 0, 'seconds' => 0],
];
$hourHistogram = [];

if ($planRows) {
    for ($d = clone $start; $d <= $sampleEnd; $d->modify('+1 day')) {
        $dateStr = $d->format('Y-m-d');
        $usage = calculateBandUsageFromPlanRowsForDate($planRows, $dateStr, $length);
        foreach ($usage as $band => $vals) {
            $bandsTotals[$band]['count'] += (int)$vals['count'];
            $bandsTotals[$band]['seconds'] += (int)$vals['seconds'];
        }
        $dow = (int)$d->format('N');
        foreach ($planRows as $row) {
            if ((int)$row['dow'] !== $dow) {
                continue;
            }
            $count = (int)($row['liczba'] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $hour = substr((string)$row['godzina'], 0, 5);
            if ($hour === '') {
                continue;
            }
            if (!isset($hourHistogram[$hour])) {
                $hourHistogram[$hour] = 0;
            }
            $hourHistogram[$hour] += $count;
        }
    }
}

arsort($hourHistogram);
$exampleHours = array_slice(array_keys($hourHistogram), 0, 12);
$titleRange = $start->format('Y-m-d') . ' – ' . $sampleEnd->format('Y-m-d');
$clientName = $spot['klient_nazwa'] ?? 'Klient';
$kampaniaLabel = !empty($spot['kampania_id']) ? ('Kampania #' . $spot['kampania_id']) : 'Kampania';
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Symulacja emisji – <?= htmlspecialchars($clientName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #fff; }
        .print-only { display: none; }
        @media print {
            .no-print { display: none; }
            .print-only { display: block; }
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div class="d-flex justify-content-between align-items-start mb-3">
        <div>
            <h1 class="h4 mb-1">Symulacja emisji – <?= htmlspecialchars($clientName) ?></h1>
            <div class="text-muted"><?= htmlspecialchars($kampaniaLabel) ?> • <?= htmlspecialchars($titleRange) ?></div>
            <div class="text-muted small">Symulacja na podstawie planu emisji (próba 14 dni).</div>
        </div>
        <div class="no-print">
            <button class="btn btn-outline-secondary" onclick="window.print()">Drukuj</button>
            <a href="edytuj_spot.php?id=<?= (int)$spotId ?>" class="btn btn-outline-primary">Wróć</a>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <?php foreach (['Prime', 'Standard', 'Night'] as $band): ?>
            <?php
            $count = $bandsTotals[$band]['count'];
            $seconds = $bandsTotals[$band]['seconds'];
            $minutes = $seconds > 0 ? round($seconds / 60, 1) : 0;
            ?>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small"><?= $band ?></div>
                        <div class="h5 mb-1"><?= (int)$count ?> emisji</div>
                        <div class="text-muted"><?= $minutes ?> min</div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card mb-4">
        <div class="card-body">
            <h5 class="card-title">Przykładowe godziny emisji</h5>
            <?php if (!$exampleHours): ?>
                <div class="text-muted">Brak danych do wyświetlenia.</div>
            <?php else: ?>
                <div><?= htmlspecialchars(implode(', ', $exampleHours)) ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-body">
            <h5 class="card-title">Histogram godzin (liczba emisji)</h5>
            <?php if (!$hourHistogram): ?>
                <div class="text-muted">Brak danych do wyświetlenia.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Godzina</th>
                                <th>Liczba emisji</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($hourHistogram as $hour => $count): ?>
                                <tr>
                                    <td><?= htmlspecialchars($hour) ?></td>
                                    <td><?= (int)$count ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>


