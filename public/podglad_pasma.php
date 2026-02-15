<?php
require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/emisje_helpers.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];
$pageTitle = "Podgląd zajętości pasma";
include 'includes/header.php';

// Pobierz ustawienia systemowe
$stmt = $pdo->query("SELECT * FROM konfiguracja_systemu WHERE id = 1");
$ust = $stmt->fetch();

$liczba_blokow = (int)$ust['liczba_blokow'];
$godzina_start = new DateTime($ust['godzina_start']);
$godzina_koniec = new DateTime($ust['godzina_koniec']);
$limit_procent = (int)$ust['prog_procentowy'];
$limit_czas_sek = floor(3600 * ($limit_procent / 100));
$limitPrimeSeconds = (int)($ust['limit_prime_seconds_per_day'] ?? 3600);
$limitStandardSeconds = (int)($ust['limit_standard_seconds_per_day'] ?? 3600);
$limitNightSeconds = (int)($ust['limit_night_seconds_per_day'] ?? 3600);

$viewMode = $_GET['tryb'] ?? 'dzien';
if (!in_array($viewMode, ['dzien', 'miesiac'], true)) {
    $viewMode = 'dzien';
}

$selectedDay = $_GET['dzien'] ?? date('Y-m-d');
$bandStats = calculateBandUsageForDate($pdo, $selectedDay, $currentUser);

// Wybrane miesiąc i rok (stary podgląd bloków)
$miesiac = $_GET['miesiac'] ?? date('m');
$rok = $_GET['rok'] ?? date('Y');
$baseParams = [
    'dzien' => $selectedDay,
    'miesiac' => $miesiac,
    'rok' => $rok,
];
$dayLink = '?' . http_build_query(array_merge($baseParams, ['tryb' => 'dzien']));
$monthLink = '?' . http_build_query(array_merge($baseParams, ['tryb' => 'miesiac']));

// Lista dni miesiąca
$dni = [];
$start = DateTime::createFromFormat('Y-m-d', "$rok-$miesiac-01");
$koniec = (clone $start)->modify('last day of this month');
for ($d = clone $start; $d <= $koniec; $d->modify('+1 day')) {
    $dni[] = $d->format('Y-m-d');
}

// Pobierz dane emisji aktywnych spotów, które są w zakresie dat emisji
$emisje = [];
$kampaniaCols = getTableColumns($pdo, 'kampanie');
$klientCols = getTableColumns($pdo, 'klienci');
$isOwnerRestricted = normalizeRole($currentUser) === 'Handlowiec'
    && (hasColumn($kampaniaCols, 'owner_user_id') || hasColumn($klientCols, 'owner_user_id'));
$ownerParts = [];
$ownerParams = [$miesiac, $rok];
if (normalizeRole($currentUser) === 'Handlowiec') {
    $ownerId = (int)($currentUser['id'] ?? 0);
    if ($ownerId > 0) {
        if (hasColumn($kampaniaCols, 'owner_user_id')) {
            $ownerParts[] = 'kmp.owner_user_id = ?';
            $ownerParams[] = $ownerId;
        }
        if (hasColumn($klientCols, 'owner_user_id')) {
            $ownerParts[] = 'k.owner_user_id = ?';
            $ownerParams[] = $ownerId;
        }
    }
}

$ownerSql = $ownerParts ? ' AND (' . implode(' OR ', $ownerParts) . ')' : '';
$query = "
    SELECT e.data, e.godzina, e.blok,
    SUM(CASE 
        WHEN s.dlugosc_s IS NOT NULL THEN s.dlugosc_s
        WHEN s.dlugosc IS NOT NULL THEN s.dlugosc
        ELSE 0 END) AS suma_sek
    FROM spoty_emisje e
    JOIN spoty s ON s.id = e.spot_id
    LEFT JOIN kampanie kmp ON kmp.id = s.kampania_id
    LEFT JOIN klienci k ON k.id = s.klient_id
    WHERE MONTH(e.data) = ? AND YEAR(e.data) = ?
    AND (s.data_start IS NULL OR e.data >= s.data_start)
    AND (s.data_koniec IS NULL OR e.data <= s.data_koniec)
    AND COALESCE(s.status,'Aktywny') = 'Aktywny'
    {$ownerSql}
    GROUP BY e.data, e.godzina, e.blok
";
$stmt = $pdo->prepare($query);
$stmt->execute($ownerParams);

foreach ($stmt as $row) {
    $key = $row['data'] . '_' . $row['godzina'] . '_' . $row['blok'];
    $emisje[$key] = (int)$row['suma_sek'];
}
?>

<div class="container-fluid">
    <div class="pasmo-layout mt-3">
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
                            <?php
                            $ownerPartsList = [];
                            $ownerParamsList = [$miesiac, $rok, $miesiac, $rok];
                            if (normalizeRole($currentUser) === 'Handlowiec') {
                                $ownerId = (int)($currentUser['id'] ?? 0);
                                if ($ownerId > 0) {
                                    if (hasColumn($kampaniaCols, 'owner_user_id')) {
                                        $ownerPartsList[] = 'kmp.owner_user_id = ?';
                                        $ownerParamsList[] = $ownerId;
                                    }
                                    if (hasColumn($klientCols, 'owner_user_id')) {
                                        $ownerPartsList[] = 'k.owner_user_id = ?';
                                        $ownerParamsList[] = $ownerId;
                                    }
                                }
                            }
                            $ownerSqlList = $ownerPartsList ? ' AND (' . implode(' OR ', $ownerPartsList) . ')' : '';
                            $stmt = $pdo->prepare("
                                SELECT s.id, s.nazwa_spotu, k.nazwa_firmy, COALESCE(s.aktywny,1) AS aktywny
                                FROM spoty s
                                LEFT JOIN klienci k ON k.id = s.klient_id
                                LEFT JOIN kampanie kmp ON kmp.id = s.kampania_id
                                WHERE (
                                    (MONTH(s.data_start) = ? AND YEAR(s.data_start) = ?)
                                    OR (MONTH(s.data_koniec) = ? AND YEAR(s.data_koniec) = ?)
                                )
                                AND COALESCE(s.status,'Aktywny') = 'Aktywny'
                                {$ownerSqlList}
                                ORDER BY s.nazwa_spotu
                            ");
                            $stmt->execute($ownerParamsList);
                            foreach ($stmt as $spot):
                            ?>
                                <label class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <strong><?php echo htmlspecialchars($spot['nazwa_spotu']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($spot['nazwa_firmy']); ?></small>
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
                                    $limitSeconds = $band === 'Prime'
                                        ? $limitPrimeSeconds
                                        : ($band === 'Standard' ? $limitStandardSeconds : $limitNightSeconds);
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
                            Suma dnia: <?= (int)($bandStats['total']['emisje'] ?? 0) ?> emisji,
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
                    <?php if ($isOwnerRestricted): ?>
                        <div class="alert alert-info mb-3">
                            Widok ograniczony do kampanii/klientów przypisanych do zalogowanego handlowca.
                        </div>
                    <?php endif; ?>
                    <div class="table-legend small text-muted mb-3">
                        <span class="legend-item"><span class="legend-swatch cell-muted"></span>0s = brak emisji</span>
                        <span class="legend-item"><span class="legend-swatch cell-warn"></span>1–20s = niskie obciążenie</span>
                        <span class="legend-item"><span class="legend-swatch cell-hot"></span>>20s = podwyższone obciążenie</span>
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
                                            $procent = ($limit_czas_sek > 0) ? round(($suma / $limit_czas_sek) * 100) : 0;
                                            if ($suma === 0) {
                                                $cellClass = 'cell-muted';
                                            } elseif ($suma <= 20) {
                                                $cellClass = 'cell-warn';
                                            } else {
                                                $cellClass = 'cell-hot';
                                            }
                                            echo '<td class="' . $cellClass . ' col-day" title="' . $suma . 's (' . $procent . '%)">' . $suma . 's</td>';
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


