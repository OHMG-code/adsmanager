<?php
declare(strict_types=1);

$embeddedRaportCele = defined('EMBED_RAPORT_CELE') && EMBED_RAPORT_CELE === true;

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/lead_pipeline_helpers.php';
require_once __DIR__ . '/includes/transaction_metrics.php';
require_once __DIR__ . '/includes/tasks.php';
require_once __DIR__ . '/../config/config.php';

$pageTitle = 'Raport wykonania celów';
if ($embeddedRaportCele) {
    $embeddedViewer = fetchCurrentUser($pdo) ?? [];
    $embeddedRole = normalizeRole($embeddedViewer);
    $isEmbeddedManagerView = in_array($embeddedRole, ['Manager', 'Administrator'], true) || isAdminOverride($embeddedViewer);
    if (!$isEmbeddedManagerView) {
        return;
    }
} else {
    requireRole(['Manager', 'Administrator']);
}
$currentUser = fetchCurrentUser($pdo) ?? [];

try {
    ensureUserColumns($pdo);
    ensureSalesTargetsTable($pdo);
    ensureKampanieOwnershipColumns($pdo);
    ensureKampanieSalesValueColumn($pdo);
    ensureTransactionsTable($pdo);
} catch (Throwable $e) {
    error_log('raport_cele: ensure schema failed: ' . $e->getMessage());
}

if (!function_exists('formatOwnerLabel')) {
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
}

$selectedYear = (int)($_GET['year'] ?? date('Y'));
$selectedMonth = (int)($_GET['month'] ?? date('n'));
if ($selectedMonth < 1 || $selectedMonth > 12) {
    $selectedMonth = (int)date('n');
}
$reportViewBase = $embeddedRaportCele ? 'dashboard.php' : 'raport_cele.php';
$reportTabQuery = $embeddedRaportCele ? '&dashboard_tab=report' : '';

$monthStart = DateTime::createFromFormat('Y-n-j', $selectedYear . '-' . $selectedMonth . '-1') ?: new DateTime('first day of this month');
$monthEnd = (clone $monthStart)->modify('last day of this month');
$rangeFrom = $monthStart->format('Y-m-d');
$rangeTo = $monthEnd->format('Y-m-d');
$rangeFromDateTime = $rangeFrom . ' 00:00:00';
$rangeToDateTime = $rangeTo . ' 23:59:59';

$kampaniaCols = tableExists($pdo, 'kampanie') ? getTableColumns($pdo, 'kampanie') : [];
$leadCols = tableExists($pdo, 'leady') ? getTableColumns($pdo, 'leady') : [];

$campaignDateColumn = null;
$campaignDateFrom = $rangeFrom;
$campaignDateTo = $rangeTo;
$campaignValueExpr = '0';
$campaignStatusCondition = '1=1';
$campaignJoinsSql = '';
$campaignOwnerExpr = 'NULL';
$campaignMetricsReady = false;
$campaignFallbackInfo = false;

if ($kampaniaCols) {
    $campaignDateConfig = transactionResolveDateColumn($kampaniaCols);
    if ($campaignDateConfig !== null) {
        $campaignDateColumn = (string)$campaignDateConfig['column'];
        if (!empty($campaignDateConfig['with_time'])) {
            $campaignDateFrom = $rangeFromDateTime;
            $campaignDateTo = $rangeToDateTime;
        }

        $campaignValueExpr = transactionResolveValueExpr($kampaniaCols, 'k');
        $campaignStatusCondition = transactionResolveStatusCondition($kampaniaCols, 'k');
        $campaignAttribution = transactionResolveAttribution($pdo, $kampaniaCols, 'k');
        $campaignJoinsSql = (string)($campaignAttribution['joins_sql'] ?? '');
        $campaignOwnerExpr = (string)($campaignAttribution['owner_expr'] ?? 'NULL');
        $campaignMetricsReady = $campaignOwnerExpr !== 'NULL';
        $campaignFallbackInfo = hasColumn($kampaniaCols, 'wartosc_netto')
            && (
                hasColumn($kampaniaCols, 'razem_netto')
                || hasColumn($kampaniaCols, 'netto_spoty')
                || hasColumn($kampaniaCols, 'netto_dodatki')
            );
    }
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

if ($campaignMetricsReady) {
    $transactionStats = fetchTransactionStatsByOwner($pdo, $rangeFrom, $rangeTo, null);
    foreach ($transactionStats['by_owner'] as $ownerId => $stats) {
        $ownerId = (int)$ownerId;
        if ($ownerId <= 0) {
            continue;
        }
        $performance[$ownerId] = [
            'sales_total' => (float)($stats['netto'] ?? 0),
            'campaign_count' => (int)($stats['count'] ?? 0),
        ];
    }
}

if (tableExists($pdo, 'leady') && hasColumn($leadCols, 'owner_user_id') && hasColumn($leadCols, 'created_at')) {
    try {
        $wonStatuses = array_unique(array_merge(leadWonStatuses(), ['wygrana', 'media plan zaakceptowany', 'oferta zaakceptowana']));
        $placeholders = implode(',', array_fill(0, count($wonStatuses), '?'));
        $stmt = $pdo->prepare("SELECT owner_user_id, COUNT(*) AS total
            FROM leady
            WHERE created_at BETWEEN ? AND ?
              AND LOWER(TRIM(COALESCE(status, ''))) IN ($placeholders)
            GROUP BY owner_user_id");
        $stmt->execute(array_merge([$rangeFromDateTime, $rangeToDateTime], $wonStatuses));
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

    if ($campaignMetricsReady && $campaignDateColumn !== null) {
        $sql = "SELECT k.id,
                       k.klient_nazwa,
                       k.status,
                       k.$campaignDateColumn AS data_kampanii,
                       $campaignOwnerExpr AS owner_id,
                       $campaignValueExpr AS wartosc
                FROM kampanie k$campaignJoinsSql
                WHERE $campaignOwnerExpr IS NOT NULL
                  AND $campaignStatusCondition
                  AND k.$campaignDateColumn BETWEEN :from AND :to
                ORDER BY owner_id, k.$campaignDateColumn";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':from' => $campaignDateFrom, ':to' => $campaignDateTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $userId = (int)($row['owner_id'] ?? 0);
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
if ($drillOwnerId > 0 && $campaignMetricsReady && $campaignDateColumn !== null) {
    try {
        $sql = "SELECT k.id,
                       k.klient_nazwa,
                       k.status,
                       k.$campaignDateColumn AS data_kampanii,
                       $campaignValueExpr AS wartosc
                FROM kampanie k$campaignJoinsSql
                WHERE $campaignOwnerExpr = :owner_id
                  AND $campaignStatusCondition
                  AND k.$campaignDateColumn BETWEEN :from AND :to
                ORDER BY k.$campaignDateColumn";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':owner_id' => $drillOwnerId, ':from' => $campaignDateFrom, ':to' => $campaignDateTo]);
        $drillCampaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('raport_cele: drill campaigns failed: ' . $e->getMessage());
    }
}

$pipelineSalesKpi = [
    'leads_new' => 0,
    'leads_won' => 0,
    'conversion_percent' => 0.0,
];
$pipelineRealizationKpi = [
    'avg_prod_to_dispatch_days' => null,
    'avg_dispatch_to_final_days' => null,
    'bottleneck_production' => 0,
    'bottleneck_audio_wait' => 0,
];
$taskKpi = [
    'open' => 0,
    'overdue' => 0,
    'done' => 0,
    'per_owner' => [],
];

try {
    if (tableExists($pdo, 'leady')) {
        $stmtNew = $pdo->prepare("SELECT COUNT(*) FROM leady WHERE created_at BETWEEN :from AND :to");
        $stmtNew->execute([':from' => $rangeFromDateTime, ':to' => $rangeToDateTime]);
        $pipelineSalesKpi['leads_new'] = (int)($stmtNew->fetchColumn() ?? 0);

        $wonStatuses = array_unique(array_merge(leadWonStatuses(), ['wygrana', 'media plan zaakceptowany', 'oferta zaakceptowana']));
        $wonIn = implode(',', array_fill(0, count($wonStatuses), '?'));
        $stmtWon = $pdo->prepare("SELECT COUNT(*) FROM leady
            WHERE created_at BETWEEN ? AND ?
              AND LOWER(TRIM(COALESCE(status, ''))) IN ({$wonIn})");
        $stmtWon->execute(array_merge([$rangeFromDateTime, $rangeToDateTime], $wonStatuses));
        $pipelineSalesKpi['leads_won'] = (int)($stmtWon->fetchColumn() ?? 0);
        $pipelineSalesKpi['conversion_percent'] = $pipelineSalesKpi['leads_new'] > 0
            ? round(($pipelineSalesKpi['leads_won'] / $pipelineSalesKpi['leads_new']) * 100, 1)
            : 0.0;
    }

    if (tableExists($pdo, 'communication_events')) {
        $stmtDur = $pdo->prepare("SELECT
                AVG(CASE WHEN pd.first_dispatch IS NOT NULL THEN TIMESTAMPDIFF(DAY, ps.started_at, pd.first_dispatch) END) AS avg_prod_to_dispatch,
                AVG(CASE WHEN af.final_at IS NOT NULL AND pd.first_dispatch IS NOT NULL THEN TIMESTAMPDIFF(DAY, pd.first_dispatch, af.final_at) END) AS avg_dispatch_to_final
            FROM kampanie k
            LEFT JOIN (
                SELECT campaign_id, MIN(created_at) AS started_at
                FROM communication_events
                WHERE event_type = 'production_started' AND status IN ('logged','sent')
                GROUP BY campaign_id
            ) ps ON ps.campaign_id = k.id
            LEFT JOIN (
                SELECT campaign_id, MIN(created_at) AS first_dispatch
                FROM communication_events
                WHERE event_type = 'audio_dispatched' AND status IN ('logged','sent')
                GROUP BY campaign_id
            ) pd ON pd.campaign_id = k.id
            LEFT JOIN (
                SELECT campaign_id, MIN(created_at) AS final_at
                FROM communication_events
                WHERE event_type = 'audio_final_selected' AND status IN ('logged','sent')
                GROUP BY campaign_id
            ) af ON af.campaign_id = k.id
            WHERE k.created_at BETWEEN :from AND :to");
        $stmtDur->execute([':from' => $rangeFromDateTime, ':to' => $rangeToDateTime]);
        $dur = $stmtDur->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($dur['avg_prod_to_dispatch'] !== null) {
            $pipelineRealizationKpi['avg_prod_to_dispatch_days'] = round((float)$dur['avg_prod_to_dispatch'], 1);
        }
        if ($dur['avg_dispatch_to_final'] !== null) {
            $pipelineRealizationKpi['avg_dispatch_to_final_days'] = round((float)$dur['avg_dispatch_to_final'], 1);
        }
    }

    if (tableExists($pdo, 'kampanie') && tableExists($pdo, 'communication_events')) {
        $stmtB1 = $pdo->prepare("SELECT COUNT(*)
            FROM kampanie k
            LEFT JOIN (
                SELECT campaign_id, MAX(created_at) AS event_at
                FROM communication_events
                WHERE event_type = 'production_started' AND status IN ('logged','sent')
                GROUP BY campaign_id
            ) ce ON ce.campaign_id = k.id
            WHERE k.realization_status = 'w_produkcji'
              AND TIMESTAMPDIFF(DAY, COALESCE(ce.event_at, k.created_at), NOW()) > 7");
        $stmtB1->execute();
        $pipelineRealizationKpi['bottleneck_production'] = (int)($stmtB1->fetchColumn() ?? 0);

        $stmtB2 = $pdo->prepare("SELECT COUNT(*)
            FROM kampanie k
            LEFT JOIN (
                SELECT campaign_id, MAX(created_at) AS event_at
                FROM communication_events
                WHERE event_type = 'audio_dispatched' AND status IN ('logged','sent')
                GROUP BY campaign_id
            ) ce ON ce.campaign_id = k.id
            WHERE k.realization_status = 'wersje_wyslane'
              AND TIMESTAMPDIFF(DAY, COALESCE(ce.event_at, k.created_at), NOW()) > 5");
        $stmtB2->execute();
        $pipelineRealizationKpi['bottleneck_audio_wait'] = (int)($stmtB2->fetchColumn() ?? 0);
    }

    if (tableExists($pdo, 'crm_zadania')) {
        $stmtTask = $pdo->prepare("SELECT
                SUM(CASE WHEN status = 'OPEN' THEN 1 ELSE 0 END) AS open_total,
                SUM(CASE WHEN status = 'OPEN' AND due_at < NOW() THEN 1 ELSE 0 END) AS overdue_total,
                SUM(CASE WHEN status IN ('DONE','CANCELLED') THEN 1 ELSE 0 END) AS done_total
            FROM crm_zadania");
        $stmtTask->execute();
        $taskRow = $stmtTask->fetch(PDO::FETCH_ASSOC) ?: [];
        $taskKpi['open'] = (int)($taskRow['open_total'] ?? 0);
        $taskKpi['overdue'] = (int)($taskRow['overdue_total'] ?? 0);
        $taskKpi['done'] = (int)($taskRow['done_total'] ?? 0);
    }
} catch (Throwable $e) {
    error_log('raport_cele: extra KPI failed: ' . $e->getMessage());
}

if (!$embeddedRaportCele) {
    require __DIR__ . '/includes/header.php';
}
?>

<?php if ($embeddedRaportCele): ?>
<section class="mt-3" role="region" aria-labelledby="raport-cele-heading">
<?php else: ?>
<main class="container py-3" role="main" aria-labelledby="raport-cele-heading">
<?php endif; ?>
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 id="raport-cele-heading" class="h4 mb-0">Raport wykonania celów</h1>
            <p class="text-muted small mb-0">
                Definicja sprzedaży (MVP): priorytetowo transakcje sprzedażowe (transactions),
                z fallbackiem do legacy kampanii dla rekordów historycznych bez transakcji.
                <?php if ($campaignFallbackInfo): ?>
                    Gdy wartosc_netto jest puste lub równe 0, raport używa razem_netto albo sumy netto_spoty + netto_dodatki.
                <?php endif; ?>
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="cele.php" class="btn btn-outline-secondary btn-sm">Cele sprzedażowe</a>
            <a href="prowizje.php?year=<?= (int)$selectedYear ?>&month=<?= (int)$selectedMonth ?>" class="btn btn-outline-secondary btn-sm">Prowizje handlowców</a>
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
                    <?php if ($embeddedRaportCele): ?>
                        <input type="hidden" name="dashboard_tab" value="report">
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary w-100">Pokaż</button>
                </div>
            </form>
        </div>
    </section>

    <section class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 mb-0">Pipeline sprzedaży</h2></div>
                <div class="card-body small">
                    <div>Nowe leady: <strong><?= (int)$pipelineSalesKpi['leads_new'] ?></strong></div>
                    <div>Wygrane leady: <strong><?= (int)$pipelineSalesKpi['leads_won'] ?></strong></div>
                    <div>Konwersja: <strong><?= number_format((float)$pipelineSalesKpi['conversion_percent'], 1, '.', ' ') ?>%</strong></div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 mb-0">Pipeline realizacji</h2></div>
                <div class="card-body small">
                    <div>Śr. produkcja -> dispatch:
                        <strong><?= $pipelineRealizationKpi['avg_prod_to_dispatch_days'] !== null ? number_format((float)$pipelineRealizationKpi['avg_prod_to_dispatch_days'], 1, '.', ' ') . ' dni' : '—' ?></strong>
                    </div>
                    <div>Śr. dispatch -> final:
                        <strong><?= $pipelineRealizationKpi['avg_dispatch_to_final_days'] !== null ? number_format((float)$pipelineRealizationKpi['avg_dispatch_to_final_days'], 1, '.', ' ') . ' dni' : '—' ?></strong>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 mb-0">Wąskie gardła i taski</h2></div>
                <div class="card-body small">
                    <div>Produkcja > 7 dni: <strong><?= (int)$pipelineRealizationKpi['bottleneck_production'] ?></strong></div>
                    <div>Audio bez odpowiedzi > 5 dni: <strong><?= (int)$pipelineRealizationKpi['bottleneck_audio_wait'] ?></strong></div>
                    <div>Taski open/overdue/done:
                        <strong><?= (int)$taskKpi['open'] ?>/<?= (int)$taskKpi['overdue'] ?>/<?= (int)$taskKpi['done'] ?></strong>
                    </div>
                </div>
            </div>
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
                                    <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($reportViewBase) ?>?year=<?= (int)$selectedYear ?>&month=<?= (int)$selectedMonth ?>&owner_id=<?= (int)$row['user_id'] ?><?= $reportTabQuery ?>#kampanie">Pokaż kampanie</a>
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
<?php if ($embeddedRaportCele): ?>
</section>
<?php else: ?>
</main>
<?php endif; ?>

<?php if (!$embeddedRaportCele): ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php endif; ?>
