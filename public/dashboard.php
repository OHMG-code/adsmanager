<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/lead_pipeline_helpers.php';
requireLogin();
$pageTitle = 'Panel główny';
$pageStyles = ['dashboard'];
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/company_view_model.php';
require_once __DIR__ . '/includes/transaction_metrics.php';
require_once __DIR__ . '/includes/tasks.php';

$trendLabels = [];
$trendCounts = [];
$trendValues = [];
$currentMonthCampaigns = 0;
$currentMonthRevenue = 0.0;
$avgCampaignValue = 0.0;
$activeCampaignShare = 0.0;
$ending_campaigns = [];
$ending_spots = [];
$campaigns_ending_countdown = [];

$dashboardViewer = fetchCurrentUser($pdo) ?? [];
$dashboardViewerRole = normalizeRole($dashboardViewer);
$showManagerPanel = in_array($dashboardViewerRole, ['Manager', 'Administrator'], true) || isAdminOverride($dashboardViewer);

$managerMonthLabel = date('m/Y');
$managerMonthPlan = 0.0;
$managerMonthNetto = 0.0;
$managerMonthDelta = 0.0;
$managerMonthPercent = 0.0;
$managerMonthTransactions = 0;
$managerMonthDeltaClass = 'text-muted';
$managerMonthDeltaPrefix = '';
$myTasksOpen = [];
$myTasksOverdue = [];
$myTasksDone = [];
$myTaskCounters = ['open' => 0, 'overdue' => 0, 'done' => 0];
$simplePipelineStages = [
    'nowy' => ['label' => 'Nowy', 'count' => 0, 'items' => []],
    'w_kontakcie' => ['label' => 'W kontakcie', 'count' => 0, 'items' => []],
    'oferta' => ['label' => 'Oferta', 'count' => 0, 'items' => []],
    'wygrany' => ['label' => 'Wygrany', 'count' => 0, 'items' => []],
    'przegrany' => ['label' => 'Przegrany', 'count' => 0, 'items' => []],
];

if (!function_exists('dashboardSimplifiedLeadStage')) {
    function dashboardSimplifiedLeadStage(?string $status): string
    {
        $normalized = normalizeLeadStatus($status);
        if ($normalized === 'nowy') {
            return 'nowy';
        }
        if (in_array($normalized, ['oferta_przygotowywana', 'oferta_wyslana'], true)) {
            return 'oferta';
        }
        if (in_array($normalized, ['oferta_zaakceptowana', 'skonwertowany'], true)) {
            return 'wygrany';
        }
        if ($normalized === 'odrzucony') {
            return 'przegrany';
        }
        return 'w_kontakcie';
    }
}

// Pobranie danych — nie zmieniaj tej logiki
try {
    $campaignTable = tableExists($pdo, 'kampanie') ? 'kampanie' : 'kampanie_tygodniowe';
    $campaigns_count = (int) ($pdo->query('SELECT COUNT(*) FROM ' . $campaignTable)->fetchColumn() ?? 0);
    $clients_count   = (int) ($pdo->query('SELECT COUNT(*) FROM klienci')->fetchColumn() ?? 0);
    $spots_count     = (int) ($pdo->query('SELECT COUNT(*) FROM spoty')->fetchColumn() ?? 0);

    if (tableExists($pdo, 'leady')) {
        ensureLeadColumns($pdo);
        $leadCols = getTableColumns($pdo, 'leady');
        $pipelineWhere = [];
        $pipelineParams = [];
        foreach (buildActiveLeadConditions($leadCols, 'l') as $condition) {
            $pipelineWhere[] = $condition;
        }
        if ($dashboardViewerRole === 'Handlowiec' && hasColumn($leadCols, 'owner_user_id')) {
            $pipelineWhere[] = '(l.owner_user_id = :pipeline_owner_id OR l.owner_user_id IS NULL OR l.owner_user_id = 0)';
            $pipelineParams[':pipeline_owner_id'] = (int)($dashboardViewer['id'] ?? 0);
        }

        $pipelineSelect = ['l.id', 'l.nazwa_firmy', 'l.status'];
        $pipelineSelect[] = hasColumn($leadCols, 'next_action') ? 'l.next_action' : "'' AS next_action";
        if (hasColumn($leadCols, 'next_action_at')) {
            $pipelineSelect[] = 'l.next_action_at';
        } elseif (hasColumn($leadCols, 'next_action_date')) {
            $pipelineSelect[] = 'l.next_action_date AS next_action_at';
        } else {
            $pipelineSelect[] = 'NULL AS next_action_at';
        }

        $pipelineSql = 'SELECT ' . implode(', ', $pipelineSelect) . ' FROM leady l';
        if ($pipelineWhere) {
            $pipelineSql .= ' WHERE ' . implode(' AND ', $pipelineWhere);
        }
        $pipelineSql .= ' ORDER BY l.created_at DESC, l.id DESC LIMIT 300';
        $pipelineStmt = $pdo->prepare($pipelineSql);
        $pipelineStmt->execute($pipelineParams);
        while ($leadRow = $pipelineStmt->fetch(PDO::FETCH_ASSOC)) {
            $stage = dashboardSimplifiedLeadStage((string)($leadRow['status'] ?? ''));
            if (!isset($simplePipelineStages[$stage])) {
                $stage = 'w_kontakcie';
            }
            $simplePipelineStages[$stage]['count']++;
            if (count($simplePipelineStages[$stage]['items']) < 3) {
                $simplePipelineStages[$stage]['items'][] = $leadRow;
            }
        }
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $campaignTable . ' WHERE data_start <= CURDATE() AND data_koniec >= CURDATE()');
    $stmt->execute();
    $active_campaigns = (int) ($stmt->fetchColumn() ?? 0);

    $latestRows = $pdo->query("SELECT
            k.id AS k_id,
            k.nazwa_firmy AS k_nazwa_firmy,
            k.nip AS k_nip,
            k.regon AS k_regon,
            k.data_dodania AS k_data_dodania,
            c.name_full AS c_name_full,
            c.name_short AS c_name_short,
            c.nip AS c_nip,
            c.regon AS c_regon
        FROM klienci k
        LEFT JOIN companies c ON c.id = k.company_id
        ORDER BY k.data_dodania DESC
        LIMIT 5")->fetchAll();
    $latest_clients = [];
    foreach ($latestRows as $row) {
        $latest_clients[] = [
            'id' => (int)$row['k_id'],
            'data_dodania' => $row['k_data_dodania'] ?? null,
            'vm' => buildCompanyViewModelFromJoined($row),
        ];
    }
    $latest_campaigns = $pdo->query("SELECT id, klient_nazwa, data_start, data_koniec, razem_brutto FROM {$campaignTable} ORDER BY id DESC LIMIT 5")->fetchAll();
    $campaigns_ending_countdown = $pdo->query("SELECT
            id,
            klient_nazwa,
            data_start,
            data_koniec,
            razem_brutto,
            DATEDIFF(data_koniec, CURDATE()) AS days_left
        FROM {$campaignTable}
        WHERE data_koniec BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY days_left DESC, data_koniec DESC, id DESC
        LIMIT 50")->fetchAll();
    $running_spots = $pdo->query("SELECT
            s.id, s.nazwa_spotu, s.dlugosc, s.data_start, s.data_koniec,
            COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy
        FROM spoty s
        LEFT JOIN klienci k ON k.id = s.klient_id
        LEFT JOIN companies c ON c.id = k.company_id
        WHERE s.data_start <= CURDATE() AND s.data_koniec >= CURDATE()
        ORDER BY s.data_start DESC
        LIMIT 5")->fetchAll();

    $trendDays = 90;
    $trendFrom = (new DateTime('today'))->modify('-' . ($trendDays - 1) . ' days');
    $trendTo = new DateTime('today');
    $trendStmt = $pdo->prepare("SELECT DATE(data_start) AS day_key, COUNT(*) AS total_count, COALESCE(SUM(razem_brutto), 0) AS total_value
        FROM {$campaignTable}
        WHERE data_start BETWEEN :from AND :to
        GROUP BY DATE(data_start)
        ORDER BY DATE(data_start) ASC");
    $trendStmt->execute([
        ':from' => $trendFrom->format('Y-m-d'),
        ':to' => $trendTo->format('Y-m-d'),
    ]);
    $trendMap = [];
    while ($row = $trendStmt->fetch(PDO::FETCH_ASSOC)) {
        $key = (string)($row['day_key'] ?? '');
        if ($key === '') {
            continue;
        }
        $trendMap[$key] = [
            'count' => (int)($row['total_count'] ?? 0),
            'value' => round((float)($row['total_value'] ?? 0), 2),
        ];
    }
    for ($i = 0; $i < $trendDays; $i++) {
        $day = (clone $trendFrom)->modify('+' . $i . ' days');
        $dayKey = $day->format('Y-m-d');
        $trendLabels[] = $day->format('d.m');
        $trendCounts[] = (int)($trendMap[$dayKey]['count'] ?? 0);
        $trendValues[] = (float)($trendMap[$dayKey]['value'] ?? 0.0);
    }

    $monthStart = (new DateTime('first day of this month'))->format('Y-m-d');
    $monthEnd = (new DateTime('last day of this month'))->format('Y-m-d');
    $monthStmt = $pdo->prepare("SELECT COUNT(*) AS total_count, COALESCE(SUM(razem_brutto), 0) AS total_value
        FROM {$campaignTable}
        WHERE data_start BETWEEN :from AND :to");
    $monthStmt->execute([
        ':from' => $monthStart,
        ':to' => $monthEnd,
    ]);
    $monthSummary = $monthStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $currentMonthCampaigns = (int)($monthSummary['total_count'] ?? 0);
    $currentMonthRevenue = round((float)($monthSummary['total_value'] ?? 0), 2);
    $avgCampaignValue = $currentMonthCampaigns > 0 ? round($currentMonthRevenue / $currentMonthCampaigns, 2) : 0.0;
    $activeCampaignShare = $campaigns_count > 0 ? round(($active_campaigns / $campaigns_count) * 100, 1) : 0.0;

    if ($showManagerPanel) {
        ensureUserColumns($pdo);
        ensureSalesTargetsTable($pdo);

        $managerMonthDate = new DateTime('first day of this month');
        $managerMonthLabel = $managerMonthDate->format('m/Y');
        $managerMonthFrom = $managerMonthDate->format('Y-m-01');
        $managerMonthTo = $managerMonthDate->format('Y-m-t');
        $managerYear = (int)$managerMonthDate->format('Y');
        $managerMonthNumber = (int)$managerMonthDate->format('n');

        $handlowiecIds = [];
        $usersStmt = $pdo->query('SELECT id, rola, aktywny FROM uzytkownicy');
        $usersRows = $usersStmt ? $usersStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        foreach ($usersRows as $userRow) {
            if ((int)($userRow['aktywny'] ?? 1) !== 1) {
                continue;
            }
            if (normalizeRole($userRow) !== 'Handlowiec') {
                continue;
            }
            $handlowiecIds[] = (int)($userRow['id'] ?? 0);
        }
        $handlowiecIds = array_values(array_filter($handlowiecIds, static function (int $id): bool {
            return $id > 0;
        }));

        $targetsByUser = [];
        if ($handlowiecIds && tableExists($pdo, 'cele_sprzedazowe')) {
            $targetsStmt = $pdo->prepare('SELECT user_id, target_netto FROM cele_sprzedazowe WHERE year = :year AND month = :month');
            $targetsStmt->execute([
                ':year' => $managerYear,
                ':month' => $managerMonthNumber,
            ]);
            while ($targetRow = $targetsStmt->fetch(PDO::FETCH_ASSOC)) {
                $userId = (int)($targetRow['user_id'] ?? 0);
                if (!in_array($userId, $handlowiecIds, true)) {
                    continue;
                }
                $targetsByUser[$userId] = (float)($targetRow['target_netto'] ?? 0);
            }
        }

        $monthStats = fetchTransactionStatsByOwner($pdo, $managerMonthFrom, $managerMonthTo, null);
        foreach ($handlowiecIds as $ownerId) {
            $managerMonthPlan += (float)($targetsByUser[$ownerId] ?? 0);
            $ownerStats = $monthStats['by_owner'][$ownerId] ?? [];
            $managerMonthNetto += (float)($ownerStats['netto'] ?? 0);
            $managerMonthTransactions += (int)($ownerStats['count'] ?? 0);
        }

        $managerMonthDelta = $managerMonthNetto - $managerMonthPlan;
        $managerMonthPercent = $managerMonthPlan > 0 ? round(($managerMonthNetto / $managerMonthPlan) * 100, 1) : 0.0;
        $managerMonthDeltaClass = $managerMonthDelta > 0 ? 'text-success' : ($managerMonthDelta < 0 ? 'text-danger' : 'text-muted');
        $managerMonthDeltaPrefix = $managerMonthDelta > 0 ? '+' : '';
    }

    $ending_campaigns = $pdo->query("SELECT id, klient_nazwa, data_koniec, razem_brutto
        FROM {$campaignTable}
        WHERE data_koniec BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY)
        ORDER BY data_koniec ASC
        LIMIT 10")->fetchAll();

    $ending_spots = $pdo->query("SELECT
            s.id, s.nazwa_spotu, s.data_koniec,
            COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy
        FROM spoty s
        LEFT JOIN klienci k ON k.id = s.klient_id
        LEFT JOIN companies c ON c.id = k.company_id
        WHERE s.data_koniec BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
        ORDER BY s.data_koniec ASC
        LIMIT 10")->fetchAll();

    if (!empty($dashboardViewer['id'])) {
        $myTasksOpen = getUserTasks($pdo, (int)$dashboardViewer['id'], $dashboardViewerRole, 'open', 5);
        $myTasksOverdue = getUserTasks($pdo, (int)$dashboardViewer['id'], $dashboardViewerRole, 'overdue', 5);
        $myTasksDone = getUserTasks($pdo, (int)$dashboardViewer['id'], $dashboardViewerRole, 'done', 5);
        $myTaskCounters = [
            'open' => count(getUserTasks($pdo, (int)$dashboardViewer['id'], $dashboardViewerRole, 'open', 500)),
            'overdue' => count(getUserTasks($pdo, (int)$dashboardViewer['id'], $dashboardViewerRole, 'overdue', 500)),
            'done' => count(getUserTasks($pdo, (int)$dashboardViewer['id'], $dashboardViewerRole, 'done', 500)),
        ];
    }

} catch (Exception $e) {
    echo '<div class="alert alert-danger">Błąd połączenia z bazą: ' . htmlspecialchars($e->getMessage()) . '</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$allowedDashboardTabs = ['sales', 'ops', 'risks'];
if ($showManagerPanel) {
    $allowedDashboardTabs[] = 'report';
}
$dashboardTab = (string)($_GET['dashboard_tab'] ?? 'sales');
if (!in_array($dashboardTab, $allowedDashboardTabs, true)) {
    $dashboardTab = 'sales';
}
?>

<!-- Optional: Bootstrap Icons (header.php provides bootstrap CSS already) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

<main class="page-shell page-shell--dashboard dashboard-page" role="main" aria-labelledby="dashboard-heading">
    <div class="app-header dashboard-page-header">
        <div>
            <h1 id="dashboard-heading" class="app-title">Panel główny</h1>
            <div class="app-subtitle">Sprzedaż, operacje i ryzyka w jednym miejscu, bez zmiany obecnego workflow.</div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Dashboard</li>
                </ol>
            </nav>
        </div>
        <div class="app-header-actions">
            <a href="kalkulator_tygodniowy.php" class="btn btn-primary btn-sm" aria-label="Kalkulator kampanii"><i class="bi bi-calculator me-1"></i>Kalkulator</a>
            <a href="klienci.php" class="btn btn-outline-secondary btn-sm" aria-label="Lista klientów"><i class="bi bi-people"></i></a>
        </div>
    </div>

    <ul class="nav nav-tabs dashboard-tabs" id="dashboardMainTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $dashboardTab === 'sales' ? 'active' : '' ?>" id="dashboard-sales-tab" data-bs-toggle="tab" data-bs-target="#dashboard-sales" type="button" role="tab" aria-controls="dashboard-sales" aria-selected="<?= $dashboardTab === 'sales' ? 'true' : 'false' ?>">Sprzedaż</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $dashboardTab === 'ops' ? 'active' : '' ?>" id="dashboard-ops-tab" data-bs-toggle="tab" data-bs-target="#dashboard-ops" type="button" role="tab" aria-controls="dashboard-ops" aria-selected="<?= $dashboardTab === 'ops' ? 'true' : 'false' ?>">Operacje</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?= $dashboardTab === 'risks' ? 'active' : '' ?>" id="dashboard-risks-tab" data-bs-toggle="tab" data-bs-target="#dashboard-risks" type="button" role="tab" aria-controls="dashboard-risks" aria-selected="<?= $dashboardTab === 'risks' ? 'true' : 'false' ?>">Ryzyka</button>
        </li>
        <?php if ($showManagerPanel): ?>
            <li class="nav-item" role="presentation">
                <button class="nav-link <?= $dashboardTab === 'report' ? 'active' : '' ?>" id="dashboard-report-tab" data-bs-toggle="tab" data-bs-target="#dashboard-report" type="button" role="tab" aria-controls="dashboard-report" aria-selected="<?= $dashboardTab === 'report' ? 'true' : 'false' ?>">Raport celów</button>
            </li>
        <?php endif; ?>
    </ul>

    <div class="tab-content mb-4" id="dashboardMainTabsContent">
        <div class="tab-pane fade<?= $dashboardTab === 'sales' ? ' show active' : '' ?>" id="dashboard-sales" role="tabpanel" aria-labelledby="dashboard-sales-tab">
            <section class="card mb-3">
                <div class="card-header">
                    <h2 class="h6 mb-0">
                        <?php if ($showManagerPanel): ?>
                            Podsumowanie miesiąca <?= htmlspecialchars($managerMonthLabel) ?> (wszyscy handlowcy)
                        <?php else: ?>
                            Podsumowanie sprzedaży
                        <?php endif; ?>
                    </h2>
                </div>
                <div class="card-body">
                    <?php if ($showManagerPanel): ?>
                        <div class="kpi-grid">
                            <div class="kpi-card">
                                <div class="text-muted small">Plan netto</div>
                                <div class="kpi-value"><?= number_format($managerMonthPlan, 2, ',', ' ') ?> zł</div>
                                <div class="text-muted small">Suma celów sprzedażowych</div>
                            </div>
                            <div class="kpi-card">
                                <div class="text-muted small">Przychód netto</div>
                                <div class="kpi-value"><?= number_format($managerMonthNetto, 2, ',', ' ') ?> zł</div>
                                <div class="text-muted small">Transakcje: <?= (int)$managerMonthTransactions ?></div>
                            </div>
                            <div class="kpi-card">
                                <div class="text-muted small">Różnica (wykonanie - plan)</div>
                                <div class="kpi-value <?= $managerMonthDeltaClass ?>"><?= $managerMonthDeltaPrefix . number_format($managerMonthDelta, 2, ',', ' ') ?> zł</div>
                                <div class="text-muted small"><?= $managerMonthDelta < 0 ? 'Do planu brakuje' : 'Nadwyżka ponad plan' ?></div>
                            </div>
                            <div class="kpi-card">
                                <div class="text-muted small">Realizacja planu</div>
                                <div class="kpi-value"><?= number_format($managerMonthPercent, 1, ',', ' ') ?>%</div>
                                <div class="text-muted small">Miesiąc referencyjny: <?= htmlspecialchars($managerMonthLabel) ?></div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row g-3">
                            <div class="col-12 col-md-3">
                                <div class="small text-muted">Przychód kampanii (miesiąc)</div>
                                <div class="h5 mb-0"><?= number_format($currentMonthRevenue, 2, ',', ' ') ?> zł</div>
                            </div>
                            <div class="col-12 col-md-3">
                                <div class="small text-muted">Kampanie rozpoczęte (miesiąc)</div>
                                <div class="h5 mb-0"><?= (int)$currentMonthCampaigns ?></div>
                            </div>
                            <div class="col-12 col-md-3">
                                <div class="small text-muted">Średnia wartość kampanii (miesiąc)</div>
                                <div class="h5 mb-0"><?= number_format($avgCampaignValue, 2, ',', ' ') ?> zł</div>
                            </div>
                            <div class="col-12 col-md-3">
                                <div class="small text-muted">Aktywne kampanie</div>
                                <div class="h5 mb-0"><?= number_format($activeCampaignShare, 1, ',', ' ') ?>%</div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card mb-3 dashboard-pipeline-card">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>
                        <h3 class="h6 mb-0">Pipeline sprzedaży</h3>
                        <div class="text-muted small">Uproszczony widok: Nowy -> W kontakcie -> Oferta -> Wygrany / Przegrany</div>
                    </div>
                    <a href="lead.php" class="btn btn-sm btn-outline-secondary">Lista leadów</a>
                </div>
                <div class="card-body">
                    <div class="dashboard-pipeline">
                        <?php foreach ($simplePipelineStages as $stageKey => $stage): ?>
                            <div class="dashboard-pipeline-stage dashboard-pipeline-stage--<?= htmlspecialchars($stageKey) ?>">
                                <div class="dashboard-pipeline-stage-header">
                                    <span><?= htmlspecialchars($stage['label']) ?></span>
                                    <strong><?= (int)$stage['count'] ?></strong>
                                </div>
                                <?php if (empty($stage['items'])): ?>
                                    <div class="dashboard-pipeline-empty">Brak leadów</div>
                                <?php else: ?>
                                    <ul class="dashboard-pipeline-list">
                                        <?php foreach ($stage['items'] as $leadItem): ?>
                                            <li>
                                                <a href="lead_szczegoly.php?id=<?= (int)$leadItem['id'] ?>">
                                                    <?= htmlspecialchars((string)($leadItem['nazwa_firmy'] ?? 'Lead #' . (int)$leadItem['id'])) ?>
                                                </a>
                                                <?php if (!empty($leadItem['next_action'])): ?>
                                                    <span><?= htmlspecialchars((string)$leadItem['next_action']) ?></span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h3 class="h6 mb-0">Moje zadania</h3>
                    <a href="followup.php?tab=tasks" class="btn btn-sm btn-outline-secondary">Otwórz listę</a>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="dashboard-mini-stat border rounded p-2 h-100">
                                <div class="small text-muted">Open</div>
                                <div class="h5 mb-0"><?= (int)$myTaskCounters['open'] ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="dashboard-mini-stat border rounded p-2 h-100">
                                <div class="small text-muted">Overdue</div>
                                <div class="h5 mb-0"><?= (int)$myTaskCounters['overdue'] ?></div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="dashboard-mini-stat border rounded p-2 h-100">
                                <div class="small text-muted">Done</div>
                                <div class="h5 mb-0"><?= (int)$myTaskCounters['done'] ?></div>
                            </div>
                        </div>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="fw-semibold small mb-2">Najbliższe open</div>
                            <?php if (empty($myTasksOpen)): ?>
                                <div class="text-muted small">Brak otwartych zadań.</div>
                            <?php else: ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($myTasksOpen as $task): ?>
                                        <li class="list-group-item px-0">
                                            <div class="fw-semibold small"><?= htmlspecialchars((string)($task['tytul'] ?? 'Zadanie')) ?></div>
                                            <div class="text-muted small"><?= htmlspecialchars(formatTaskDueAt((string)($task['due_at'] ?? ''))) ?></div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <div class="fw-semibold small mb-2">Overdue</div>
                            <?php if (empty($myTasksOverdue)): ?>
                                <div class="text-muted small">Brak przeterminowanych zadań.</div>
                            <?php else: ?>
                                <ul class="list-group list-group-flush">
                                    <?php foreach ($myTasksOverdue as $task): ?>
                                        <li class="list-group-item px-0">
                                            <div class="fw-semibold small"><?= htmlspecialchars((string)($task['tytul'] ?? 'Zadanie')) ?></div>
                                            <div class="text-danger small"><?= htmlspecialchars(formatTaskDueAt((string)($task['due_at'] ?? ''))) ?></div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-3 mb-3">
                <div class="col-12">
                    <div class="card h-100">
                        <div class="card-header d-flex align-items-center justify-content-between">
                            <h2 class="h6 mb-0">Trendy kampanii</h2>
                            <div class="btn-group btn-group-sm" role="group" aria-label="Zakres danych">
                                <button class="btn btn-outline-secondary active" data-range="7">7 dni</button>
                                <button class="btn btn-outline-secondary" data-range="30">30 dni</button>
                                <button class="btn btn-outline-secondary" data-range="90">90 dni</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="dashboard-chart-frame dashboard-chart-frame--sm">
                                <canvas id="campaignsChart" aria-label="Wykres kampanii" role="img"></canvas>
                            </div>
                            <small class="text-muted d-block mt-2">Dane na podstawie daty rozpoczęcia kampanii.</small>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-3 mb-3">
                <div class="col-md-5">
                    <div class="card h-100 dashboard-campaign-block">
                        <div class="card-header"><h3 class="h6 mb-0">Ostatnie kampanie</h3></div>
                        <div class="list-group list-group-flush" role="list" aria-label="Ostatnie kampanie">
                            <?php if (count($latest_campaigns) === 0): ?>
                                <div class="list-group-item">Brak kampanii</div>
                            <?php else: foreach ($latest_campaigns as $k): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between">
                                        <div>
                                            <div class="fw-semibold"><?= htmlspecialchars($k['klient_nazwa']) ?></div>
                                            <small class="text-muted"><?= htmlspecialchars($k['data_start']) ?> – <?= htmlspecialchars($k['data_koniec']) ?></small>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-bold"><?= number_format((float)$k['razem_brutto'], 2, ',', ' ') ?> zł</div>
                                            <a href="kampania_podglad.php?id=<?= (int)$k['id'] ?>" class="btn btn-sm btn-outline-primary mt-1">Podgląd</a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <div class="card-footer"><a href="kampanie_lista.php">Pełna lista</a></div>
                    </div>
                </div>
                <div class="col-md-7">
                    <div class="card h-100 dashboard-campaign-block">
                        <div class="card-header"><h3 class="h6 mb-0">Lista kończących się kampanii</h3></div>
                        <div class="table-responsive">
                            <table class="table table-hover table-sm mb-0" aria-label="Tabela kampanii kończących się za X dni">
                                <thead>
                                    <tr>
                                        <th scope="col">Za ile dni</th>
                                        <th scope="col">Klient</th>
                                        <th scope="col">Okres</th>
                                        <th scope="col">Data końca</th>
                                        <th scope="col">Brutto</th>
                                        <th scope="col">Akcje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($campaigns_ending_countdown) === 0): ?>
                                        <tr><td colspan="6" class="text-muted">Brak kampanii kończących się w ciągu 7 dni.</td></tr>
                                    <?php else: foreach ($campaigns_ending_countdown as $k): ?>
                                        <tr>
                                            <td><span class="badge bg-warning text-dark">za <?= max(0, (int)($k['days_left'] ?? 0)) ?> dni</span></td>
                                            <td><?= htmlspecialchars($k['klient_nazwa']) ?></td>
                                            <td><?= htmlspecialchars($k['data_start']) ?> – <?= htmlspecialchars($k['data_koniec']) ?></td>
                                            <td><?= htmlspecialchars($k['data_koniec']) ?></td>
                                            <td><?= number_format((float)$k['razem_brutto'], 2, ',', ' ') ?> zł</td>
                                            <td><a href="kampania_podglad.php?id=<?= (int)$k['id'] ?>" class="btn btn-sm btn-outline-primary">Podgląd</a></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <div class="tab-pane fade<?= $dashboardTab === 'ops' ? ' show active' : '' ?>" id="dashboard-ops" role="tabpanel" aria-labelledby="dashboard-ops-tab">
            <section class="row g-3 mb-3" aria-label="Wskaźniki operacyjne">
                <div class="col-6 col-md-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body d-flex align-items-center">
                            <div class="me-3 display-6 text-primary"><i class="bi bi-graph-up"></i></div>
                            <div>
                                <div class="small text-muted">Kampanie</div>
                                <div class="h4 mb-0"><?= $campaigns_count ?></div>
                                <small class="text-success"><?= $active_campaigns ?> aktywne</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body d-flex align-items-center">
                            <div class="me-3 display-6 text-success"><i class="bi bi-people"></i></div>
                            <div>
                                <div class="small text-muted">Klienci</div>
                                <div class="h4 mb-0"><?= $clients_count ?></div>
                                <small class="text-muted">ostatnie 5 poniżej</small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body d-flex align-items-center">
                            <div class="me-3 display-6 text-warning"><i class="bi bi-play-btn"></i></div>
                            <div>
                                <div class="small text-muted">Spoty</div>
                                <div class="h4 mb-0"><?= $spots_count ?></div>
                                <small class="text-muted">dzisiaj uruchomione: <?= count($running_spots) ?></small>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-6 col-md-3">
                    <div class="card shadow-sm h-100">
                        <div class="card-body d-flex align-items-center">
                            <div class="me-3 display-6 text-info"><i class="bi bi-check2-circle"></i></div>
                            <div>
                                <div class="small text-muted">Udział aktywnych</div>
                                <div class="h4 mb-0"><?= number_format($activeCampaignShare, 1, ',', ' ') ?>%</div>
                                <small class="text-muted">wśród wszystkich kampanii</small>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><h3 class="h6 mb-0">Ostatni klienci</h3></div>
                        <div class="list-group list-group-flush" role="list" aria-label="Ostatni klienci">
                            <?php if (count($latest_clients) === 0): ?>
                                <div class="list-group-item">Brak klientów</div>
                            <?php else: foreach ($latest_clients as $c): ?>
                                <?php $vm = $c['vm'] ?? []; ?>
                                <div class="list-group-item d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($vm['company_name_display'] ?? '') ?></div>
                                        <small class="text-muted">NIP: <?= htmlspecialchars($vm['nip_display'] ?? '') ?> • <?= htmlspecialchars($c['data_dodania'] ?? '') ?></small>
                                    </div>
                                    <div class="text-end">
                                        <a href="edytuj_klienta.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary" aria-label="Edytuj klienta"><i class="bi bi-pencil"></i></a>
                                    </div>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>
                        <div class="card-footer"><a href="lista_klientow.php">Pełna lista</a></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><h3 class="h6 mb-0">Uruchomione spoty (dzisiaj)</h3></div>
                        <ul class="list-group list-group-flush" role="list" aria-label="Uruchomione spoty">
                            <?php if (count($running_spots) === 0): ?>
                                <li class="list-group-item">Brak uruchomionych spotów</li>
                            <?php else: foreach ($running_spots as $s): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($s['nazwa_spotu']) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($s['nazwa_firmy'] ?? '') ?></small>
                                    </div>
                                    <div class="text-end small">
                                        <span class="badge bg-secondary"><?= htmlspecialchars($s['dlugosc']) ?>s</span>
                                    </div>
                                </li>
                            <?php endforeach; endif; ?>
                        </ul>
                        <div class="card-footer"><a href="spoty.php">Pełna lista</a></div>
                    </div>
                </div>
            </section>
        </div>

        <div class="tab-pane fade<?= $dashboardTab === 'risks' ? ' show active' : '' ?>" id="dashboard-risks" role="tabpanel" aria-labelledby="dashboard-risks-tab">
            <section class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><h3 class="h6 mb-0">Kampanie kończące się w 14 dni</h3></div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Klient</th>
                                        <th>Data końca</th>
                                        <th>Wartość</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($ending_campaigns)): ?>
                                        <tr><td colspan="3" class="text-muted">Brak kampanii wymagających domknięcia.</td></tr>
                                    <?php else: foreach ($ending_campaigns as $campaign): ?>
                                        <tr>
                                            <td><a href="kampania_podglad.php?id=<?= (int)$campaign['id'] ?>"><?= htmlspecialchars((string)($campaign['klient_nazwa'] ?? '')) ?></a></td>
                                            <td><?= htmlspecialchars((string)($campaign['data_koniec'] ?? '')) ?></td>
                                            <td><?= number_format((float)($campaign['razem_brutto'] ?? 0), 2, ',', ' ') ?> zł</td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header"><h3 class="h6 mb-0">Spoty kończące się w 7 dni</h3></div>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Spot</th>
                                        <th>Klient</th>
                                        <th>Data końca</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($ending_spots)): ?>
                                        <tr><td colspan="3" class="text-muted">Brak spotów o podwyższonym ryzyku terminu.</td></tr>
                                    <?php else: foreach ($ending_spots as $spot): ?>
                                        <tr>
                                            <td><a href="edytuj_spot.php?id=<?= (int)$spot['id'] ?>"><?= htmlspecialchars((string)($spot['nazwa_spotu'] ?? '')) ?></a></td>
                                            <td><?= htmlspecialchars((string)($spot['nazwa_firmy'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars((string)($spot['data_koniec'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <?php if ($showManagerPanel): ?>
            <div class="tab-pane fade<?= $dashboardTab === 'report' ? ' show active' : '' ?>" id="dashboard-report" role="tabpanel" aria-labelledby="dashboard-report-tab">
                <?php
                if (!defined('EMBED_RAPORT_CELE')) {
                    define('EMBED_RAPORT_CELE', true);
                }
                require __DIR__ . '/raport_cele.php';
                ?>
            </div>
        <?php endif; ?>
    </div>

    <?php
    if ($showManagerPanel):
        if (!defined('EMBED_MANAGER_DASHBOARD')) {
            define('EMBED_MANAGER_DASHBOARD', true);
        }
        require __DIR__ . '/dashboard_manager.php';
    endif;
    ?>

</main>

<!-- Scripts: Chart.js init and optional helpers -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    (function () {
        const chartEl = document.getElementById('campaignsChart');
        if (!chartEl || typeof Chart === 'undefined') {
            return;
        }

        const css = getComputedStyle(document.documentElement);
        const textColor = (css.getPropertyValue('--text') || '').trim() || '#0F172A';
        const mutedColor = (css.getPropertyValue('--muted') || '').trim() || '#64748B';
        const borderColor = (css.getPropertyValue('--border') || '').trim() || '#E2E8F0';
        const primaryRgb = ((css.getPropertyValue('--primary-rgb') || '').trim() || '79, 70, 229');
        const successRgb = ((css.getPropertyValue('--success-rgb') || '').trim() || '22, 163, 74');
        const chartPrimaryBg = 'rgba(' + primaryRgb + ', 0.65)';
        const chartPrimaryBorder = 'rgba(' + primaryRgb + ', 1)';
        const chartSuccessBorder = 'rgba(' + successRgb + ', 1)';
        const chartSuccessBg = 'rgba(' + successRgb + ', 0.15)';

        const allLabels = <?= json_encode($trendLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const allCounts = <?= json_encode($trendCounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const allValues = <?= json_encode($trendValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const rangeButtons = document.querySelectorAll('[data-range]');

        function getSlice(data, length) {
            if (!Array.isArray(data) || !data.length) {
                return [];
            }
            const size = Math.min(length, data.length);
            return data.slice(data.length - size);
        }

        const chart = new Chart(chartEl, {
            type: 'bar',
            data: {
                labels: [],
                datasets: [
                    {
                        type: 'bar',
                        label: 'Liczba kampanii',
                        data: [],
                        backgroundColor: chartPrimaryBg,
                        borderColor: chartPrimaryBorder,
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        type: 'line',
                        label: 'Wartość brutto (zł)',
                        data: [],
                        borderColor: chartSuccessBorder,
                        backgroundColor: chartSuccessBg,
                        borderWidth: 2,
                        tension: 0.25,
                        fill: false,
                        pointRadius: 2,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: true,
                        labels: {
                            color: textColor
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function (context) {
                                if (context.datasetIndex === 1) {
                                    return context.dataset.label + ': ' + Number(context.parsed.y || 0).toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' zł';
                                }
                                return context.dataset.label + ': ' + Number(context.parsed.y || 0).toLocaleString('pl-PL');
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Liczba kampanii', color: mutedColor },
                        grid: { color: borderColor },
                        ticks: {
                            color: textColor,
                            precision: 0
                        }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { drawOnChartArea: false, color: borderColor },
                        title: { display: true, text: 'Wartość brutto (zł)', color: mutedColor },
                        ticks: {
                            color: textColor,
                            callback: function (value) {
                                return Number(value || 0).toLocaleString('pl-PL');
                            }
                        }
                    },
                    x: {
                        grid: { color: borderColor },
                        ticks: { color: textColor }
                    }
                }
            }
        });

        function renderRange(range) {
            const numericRange = range > 0 ? range : 30;
            chart.data.labels = getSlice(allLabels, numericRange);
            chart.data.datasets[0].data = getSlice(allCounts, numericRange);
            chart.data.datasets[1].data = getSlice(allValues, numericRange);
            chart.update();
        }

        for (let i = 0; i < rangeButtons.length; i++) {
            rangeButtons[i].addEventListener('click', function () {
                for (let j = 0; j < rangeButtons.length; j++) {
                    rangeButtons[j].classList.remove('active');
                }
                this.classList.add('active');
                const selectedRange = parseInt(this.getAttribute('data-range') || '30', 10);
                renderRange(selectedRange);
            });
        }

        renderRange(30);
    })();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
