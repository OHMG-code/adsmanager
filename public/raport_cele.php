<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/lead_pipeline_helpers.php';
require_once __DIR__ . '/../config/config.php';

$pageTitle = 'Raport wykonania celów';
requireRole(['Manager', 'Administrator']);
$currentUser = fetchCurrentUser($pdo) ?? [];

try {
    ensureUserColumns($pdo);
    ensureSalesTargetsTable($pdo);
    ensureKampanieOwnershipColumns($pdo);
} catch (Throwable $e) {
    error_log('raport_cele: ensure schema failed: ' . $e->getMessage());
}

function formatOwnerLabel(array $user): string {
    $parts = array_filter([
        trim((string)($user['imie'] ?? '')),
        trim((string)($user['nazwisko'] ?? '')),
    ]);
    $name = $parts ? implode(' ', $parts) : '';
    $login = trim((string)($user['login'] ?? ''));
    if ($name !== '' && $login !== '') {
        return $name . ' (' . $login . ')';
    }
    return $name !== '' ? $name : ($login !== '' ? $login : '—');
}

$selectedYear = (int)($_GET['year'] ?? date('Y'));
$selectedMonth = (int)($_GET['month'] ?? date('n'));
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int)date('n');
}

$monthStart = DateTime::createFromFormat('Y-n-j', $selectedYear . '-' . $selectedMonth . '-1') ?: new DateTime('first day of this month');
$monthEnd = (clone $monthStart)->modify('last day of this month');
$rangeFrom = $monthStart->format('Y-m-d');
$rangeTo = $monthEnd->format('Y-m-d');
$rangeFromDateTime = $rangeFrom . ' 00:00:00';
$rangeToDateTime = $rangeTo . ' 23:59:59';

$kampaniaCols = tableExists($pdo, 'kampanie') ? getTableColumns($pdo, 'kampanie') : [];
$leadCols = tableExists($pdo, 'leady') ? getTableColumns($pdo, 'leady') : [];

$valueExpr = '0';
$usesFallbackValue = false;
// MVP definicja sprzedaży: kampanie w statusie Zamówiona/W realizacji/Zakończona,
// miesiąc liczony po data_start (fallback: created_at), wartość netto kampanii.
if (hasColumn($kampaniaCols, 'wartosc_netto')) {
    $valueExpr = 'COALESCE(k.wartosc_netto, 0)';
} elseif (hasColumn($kampaniaCols, 'razem_netto')) {
    $valueExpr = 'COALESCE(k.razem_netto, 0)';
} elseif (hasColumn($kampaniaCols, 'wartosc_po_rabacie')) {
    $valueExpr = 'COALESCE(k.wartosc_po_rabacie, 0)';
} elseif (hasColumn($kampaniaCols, 'netto_spoty') && hasColumn($kampaniaCols, 'netto_dodatki')) {
    $valueExpr = 'COALESCE(k.netto_spoty, 0) + COALESCE(k.netto_dodatki, 0)';
} elseif (hasColumn($kampaniaCols, 'netto_spoty')) {
    $valueExpr = 'COALESCE(k.netto_spoty, 0)';
} else {
    ensureKampanieSalesValueColumn($pdo);
    $kampaniaCols = getTableColumns($pdo, 'kampanie');
    $valueExpr = hasColumn($kampaniaCols, 'wartosc_netto') ? 'COALESCE(k.wartosc_netto, 0)' : '0';
    $usesFallbackValue = true;
}

$dateColumn = null;
$dateFrom = $rangeFrom;
$dateTo = $rangeTo;
if (hasColumn($kampaniaCols, 'data_start')) {
    $dateColumn = 'data_start';
} elseif (hasColumn($kampaniaCols, 'created_at')) {
    $dateColumn = 'created_at';
    $dateFrom = $rangeFromDateTime;
    $dateTo = $rangeToDateTime;
}

$handlowcy = [];
try {
    $stmt = $pdo->query('SELECT id, login, imie, nazwisko, rola, aktywny FROM uzytkownicy ORDER BY login');
    $users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($users as $user) {
        if ((int)($user['aktywny'] ?? 1) !== 1) {
            continue;
        }
        if (normalizeRole($user) !== 'Handlowiec') {
            continue;
        }
        $handlowcy[(int)$user['id']] = $user;
    }
} catch (Throwable $e) {
    error_log('raport_cele: cannot load users: ' . $e->getMessage());
}

$targets = [];
try {
    $stmt = $pdo->prepare('SELECT user_id, target_netto FROM cele_sprzedazowe WHERE year = :year AND month = :month');
    $stmt->execute([':year' => $selectedYear, ':month' => $selectedMonth]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $targets[(int)$row['user_id']] = (float)$row['target_netto'];
    }
} catch (Throwable $e) {
    error_log('raport_cele: cannot load targets: ' . $e->getMessage());
}

$performance = [];
$leadWins = [];

if (tableExists($pdo, 'kampanie') && $dateColumn && hasColumn($kampaniaCols, 'owner_user_id')) {
    $statusCondition = "LOWER(REPLACE(k.status, '_', ' ')) IN ('zamowiona','zamówiona','w realizacji','zakończona','zakonczona')";
    $sql = "SELECT k.owner_user_id AS owner_id,
                   COUNT(*) AS campaign_count,
                   SUM($valueExpr) AS sales_total
            FROM kampanie k
            WHERE k.owner_user_id IS NOT NULL
              AND $statusCondition
              AND k.$dateColumn BETWEEN :from AND :to
            GROUP BY k.owner_user_id";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $performance[(int)$row['owner_id']] = [
                'sales_total' => (float)$row['sales_total'],
                'campaign_count' => (int)$row['campaign_count'],
            ];
        }
    } catch (Throwable $e) {
        error_log('raport_cele: kampanie aggregate failed: ' . $e->getMessage());
    }
}

if (tableExists($pdo, 'leady') && hasColumn($leadCols, 'owner_user_id') && hasColumn($leadCols, 'created_at')) {
    try {
        $stmt = $pdo->prepare("SELECT owner_user_id, COUNT(*) AS total
            FROM leady
            WHERE created_at BETWEEN :from AND :to
              AND LOWER(status) IN ('wygrana','skonwertowany')
            GROUP BY owner_user_id");
        $stmt->execute([':from' => $rangeFromDateTime, ':to' => $rangeToDateTime]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $leadWins[(int)$row['owner_user_id']] = (int)$row['total'];
        }
    } catch (Throwable $e) {
        error_log('raport_cele: lead wins failed: ' . $e->getMessage());
    }
}

$rows = [];
foreach ($handlowcy as $userId => $user) {
    $target = $targets[$userId] ?? 0.0;
    $sales = $performance[$userId]['sales_total'] ?? 0.0;
    $campaignCount = $performance[$userId]['campaign_count'] ?? 0;
    $percent = $target > 0 ? round(($sales / $target) * 100, 1) : 0.0;
    $rows[] = [
        'user_id' => $userId,
        'user' => $user,
        'target' => $target,
        'sales' => $sales,
        'percent' => $percent,
        'campaign_count' => $campaignCount,
        'lead_wins' => $leadWins[$userId] ?? 0,
    ];
}

usort($rows, function (array $a, array $b): int {
    return $b['percent'] <=> $a['percent'];
});

$export = $_GET['export'] ?? '';
if ($export === 'csv') {
    $filename = sprintf('cele_%04d_%02d.csv', $selectedYear, $selectedMonth);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Handlowiec', 'Cel netto', 'Wykonanie netto', '% realizacji', 'Liczba kampanii', 'Wygrane leady'], ';');
    foreach ($rows as $row) {
        fputcsv($out, [
            formatOwnerLabel($row['user']),
            number_format($row['target'], 2, '.', ''),
            number_format($row['sales'], 2, '.', ''),
            number_format($row['percent'], 1, '.', ''),
            $row['campaign_count'],
            $row['lead_wins'],
        ], ';');
    }
    fclose($out);
    exit;
}

if ($export === 'details') {
    $filename = sprintf('kampanie_szczegoly_%04d_%02d.csv', $selectedYear, $selectedMonth);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Handlowiec', 'Kampania ID', 'Klient', 'Data', 'Status', 'Wartość netto'], ';');

    if (tableExists($pdo, 'kampanie') && $dateColumn && hasColumn($kampaniaCols, 'owner_user_id')) {
        $sql = "SELECT k.id, k.klient_nazwa, k.status, k.$dateColumn AS data_kampanii, k.owner_user_id, $valueExpr AS wartosc
                FROM kampanie k
                WHERE k.owner_user_id IS NOT NULL
                  AND LOWER(REPLACE(k.status, '_', ' ')) IN ('zamowiona','zamówiona','w realizacji','zakończona','zakonczona')
                  AND k.$dateColumn BETWEEN :from AND :to
                ORDER BY k.owner_user_id, k.$dateColumn";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $userId = (int)$row['owner_user_id'];
            fputcsv($out, [
                formatOwnerLabel($handlowcy[$userId] ?? ['login' => 'ID ' . $userId]),
                (int)$row['id'],
                $row['klient_nazwa'] ?? '',
                $row['data_kampanii'] ?? '',
                $row['status'] ?? '',
                number_format((float)$row['wartosc'], 2, '.', ''),
            ], ';');
        }
    }

    fclose($out);
    exit;
}

$drillOwnerId = (int)($_GET['owner_id'] ?? 0);
$drillCampaigns = [];
if ($drillOwnerId > 0 && tableExists($pdo, 'kampanie') && $dateColumn && hasColumn($kampaniaCols, 'owner_user_id')) {
    try {
        $sql = "SELECT k.id, k.klient_nazwa, k.status, k.$dateColumn AS data_kampanii, $valueExpr AS wartosc
                FROM kampanie k
                WHERE k.owner_user_id = :owner_id
                  AND LOWER(REPLACE(k.status, '_', ' ')) IN ('zamowiona','zamówiona','w realizacji','zakończona','zakonczona')
                  AND k.$dateColumn BETWEEN :from AND :to
                ORDER BY k.$dateColumn";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':owner_id' => $drillOwnerId, ':from' => $dateFrom, ':to' => $dateTo]);
        $drillCampaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('raport_cele: drill campaigns failed: ' . $e->getMessage());
    }
}

require __DIR__ . '/includes/header.php';
?>

<main class="container py-3" role="main" aria-labelledby="raport-cele-heading">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 id="raport-cele-heading" class="h4 mb-0">Raport wykonania celów</h1>
            <p class="text-muted small mb-0">
                Definicja sprzedaży (MVP): suma wartości kampanii o statusie Zamówiona/W realizacji/Zakończona,
                liczona w miesiącu startu kampanii (data_start; fallback: created_at) i wartości netto kampanii.
                <?php if ($usesFallbackValue): ?>
                    Brak jednoznacznej wartości netto w kampaniach — używany jest nowy atrybut wartosc_netto (ustawiany od teraz).
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="cele.php" class="btn btn-outline-secondary btn-sm">Zarządzaj celami</a>
            <a href="raport_cele.php?year=<?= (int)$selectedYear ?>&month=<?= (int)$selectedMonth ?>&export=csv" class="btn btn-outline-primary btn-sm">Eksport CSV</a>
            <a href="raport_cele.php?year=<?= (int)$selectedYear ?>&month=<?= (int)$selectedMonth ?>&export=details" class="btn btn-outline-secondary btn-sm">CSV kampanie</a>
        </div>
    </div>

    <section class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label class="form-label" for="year">Rok</label>
                    <input type="number" class="form-control" name="year" id="year" value="<?= (int)$selectedYear ?>" min="2020" max="2100">
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label" for="month">Miesiąc</label>
                    <select class="form-select" name="month" id="month">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?= $m ?>" <?= $m === $selectedMonth ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <button type="submit" class="btn btn-primary w-100">Pokaż</button>
                </div>
            </form>
        </div>
    </section>

    <section class="card">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead>
                        <tr>
                            <th>Handlowiec</th>
                            <th>Cel netto</th>
                            <th>Wykonanie netto</th>
                            <th>% realizacji</th>
                            <th>Kampanie</th>
                            <th>Wygrane leady</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="7" class="text-muted">Brak danych</td></tr>
                        <?php else: foreach ($rows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars(formatOwnerLabel($row['user'])) ?></td>
                                <td><?= number_format($row['target'], 2, '.', ' ') ?></td>
                                <td><?= number_format($row['sales'], 2, '.', ' ') ?></td>
                                <td><?= number_format($row['percent'], 1, '.', '') ?>%</td>
                                <td><?= (int)$row['campaign_count'] ?></td>
                                <td><?= (int)$row['lead_wins'] ?></td>
                                <td>
                                    <a class="btn btn-sm btn-outline-secondary" href="raport_cele.php?year=<?= (int)$selectedYear ?>&month=<?= (int)$selectedMonth ?>&owner_id=<?= (int)$row['user_id'] ?>#kampanie">Pokaż kampanie</a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <?php if ($drillOwnerId > 0): ?>
        <section id="kampanie" class="card mt-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Kampanie zaliczone do wyniku</h2>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Kampania</th>
                                <th>Data</th>
                                <th>Status</th>
                                <th>Wartość netto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($drillCampaigns)): ?>
                                <tr><td colspan="4" class="text-muted">Brak kampanii dla tego handlowca</td></tr>
                            <?php else: foreach ($drillCampaigns as $row): ?>
                                <tr>
                                    <td><a href="kampania_podglad.php?id=<?= (int)$row['id'] ?>"><?= htmlspecialchars($row['klient_nazwa'] ?? '') ?></a></td>
                                    <td><?= htmlspecialchars((string)($row['data_kampanii'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['status'] ?? '')) ?></td>
                                    <td><?= number_format((float)($row['wartosc'] ?? 0), 2, '.', ' ') ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
