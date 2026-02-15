<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui_helpers.php';

$pageTitle = "Leady";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$leadStatuses = [
    'nowy'            => 'Nowy',
    'w_kontakcie'     => 'W kontakcie',
    'oferta_wyslana'  => 'Oferta wysłana',
    'odrzucony'       => 'Odrzucony',
    'zakonczony'      => 'Zakończony',
    'skonwertowany'   => 'Skonwertowany',
];

$leadStatusBadges = [
    'nowy'            => 'bg-secondary',
    'w_kontakcie'     => 'bg-info text-dark',
    'oferta_wyslana'  => 'bg-warning text-dark',
    'odrzucony'       => 'bg-danger',
    'zakonczony'      => 'bg-dark',
    'skonwertowany'   => 'bg-success',
];

$leadSources = [
    'telefon'        => 'Telefon',
    'email'          => 'Email',
    'formularz_www'  => 'Formularz WWW',
    'maps_api'       => 'Google Maps / API',
    'polecenie'      => 'Polecenie',
    'inne'           => 'Inne',
];

ensureLeadInfra($pdo);
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}
$leadColumns = getTableColumns($pdo, 'leady');

$totalLeads = $convertedLeads = $activeLeads = $newThisWeek = $followUpsToday = $overdueFollowUps = 0;
$statusBreakdown = $sourceBreakdown = $recentLeads = $upcomingActions = $staleLeads = [];
$dbError = '';
$nextActionColumn = hasColumn($leadColumns, 'next_action_at') ? 'next_action_at' : 'next_action_date';
$nextActionIsDateTime = $nextActionColumn === 'next_action_at';

try {
    [$ownerClause, $ownerParams] = ownerConstraint($leadColumns, $currentUser);
    $ownerWhere = $ownerClause ? ' WHERE ' . $ownerClause : '';

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leady{$ownerWhere}");
    $stmt->execute($ownerParams);
    $totalLeads = (int) ($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leady WHERE status = 'skonwertowany'" . ($ownerClause ? ' AND ' . $ownerClause : ''));
    $stmt->execute($ownerParams);
    $convertedLeads = (int) ($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leady WHERE status IN ('nowy','w_kontakcie','oferta_wyslana')" . ($ownerClause ? ' AND ' . $ownerClause : ''));
    $stmt->execute($ownerParams);
    $activeLeads = (int) ($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leady WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)" . ($ownerClause ? ' AND ' . $ownerClause : ''));
    $stmt->execute($ownerParams);
    $newThisWeek = (int) ($stmt->fetchColumn() ?? 0);

    $todayCondition = $nextActionIsDateTime
        ? "DATE($nextActionColumn) = CURDATE()"
        : "$nextActionColumn = CURDATE()";
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leady WHERE {$nextActionColumn} IS NOT NULL AND {$todayCondition}" . ($ownerClause ? ' AND ' . $ownerClause : ''));
    $stmt->execute($ownerParams);
    $followUpsToday = (int) ($stmt->fetchColumn() ?? 0);

    $overdueCondition = $nextActionIsDateTime
        ? "{$nextActionColumn} < NOW()"
        : "{$nextActionColumn} < CURDATE()";
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM leady WHERE {$nextActionColumn} IS NOT NULL AND {$overdueCondition}" . ($ownerClause ? ' AND ' . $ownerClause : ''));
    $stmt->execute($ownerParams);
    $overdueFollowUps = (int) ($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->prepare("SELECT status, COUNT(*) AS total FROM leady" . $ownerWhere . " GROUP BY status");
    $stmt->execute($ownerParams);
    $statusBreakdown = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT zrodlo, COUNT(*) AS total FROM leady" . $ownerWhere . " GROUP BY zrodlo ORDER BY total DESC");
    $stmt->execute($ownerParams);
    $sourceBreakdown = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT id, nazwa_firmy, status, zrodlo, created_at, next_action, {$nextActionColumn} AS next_action_at FROM leady" . $ownerWhere . " ORDER BY created_at DESC LIMIT 8");
    $stmt->execute($ownerParams);
    $recentLeads = $stmt->fetchAll();

    $upcomingCondition = $nextActionIsDateTime
        ? "{$nextActionColumn} >= NOW()"
        : "{$nextActionColumn} >= CURDATE()";
    $stmt = $pdo->prepare("SELECT id, nazwa_firmy, next_action, {$nextActionColumn} AS next_action_at, status, telefon FROM leady WHERE {$nextActionColumn} IS NOT NULL AND {$upcomingCondition}" . ($ownerClause ? ' AND ' . $ownerClause : '') . " ORDER BY {$nextActionColumn} ASC LIMIT 6");
    $stmt->execute($ownerParams);
    $upcomingActions = $stmt->fetchAll();

    $staleCondition = $nextActionIsDateTime
        ? "{$nextActionColumn} < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        : "{$nextActionColumn} < DATE_SUB(CURDATE(), INTERVAL 30 DAY)";
    $stmt = $pdo->prepare("SELECT id, nazwa_firmy, status, telefon, next_action, {$nextActionColumn} AS next_action_at, updated_at FROM leady WHERE ({$nextActionColumn} IS NULL OR {$staleCondition}) AND status NOT IN ('zakonczony','skonwertowany')" . ($ownerClause ? ' AND ' . $ownerClause : '') . " ORDER BY updated_at ASC LIMIT 6");
    $stmt->execute($ownerParams);
    $staleLeads = $stmt->fetchAll();
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

include __DIR__ . '/includes/header.php';

function ensureLeadInfra(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    ensureLeadColumns($pdo);
    ensureLeadActivityTable($pdo);
    $ready = true;
}
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

<main class="container">
    <div class="app-header">
        <div>
            <h1 class="app-title">Centrum leadów</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb small mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                    <li class="breadcrumb-item active">Leady</li>
                </ol>
            </nav>
        </div>
        <div class="app-header-actions">
            <a href="dodaj_lead.php" class="btn btn-primary btn-sm"><i class="bi bi-clipboard-plus me-1"></i>Dodaj lead</a>
            <a href="lead.php" class="btn btn-ghost btn-sm"><i class="bi bi-list-task me-1"></i>Lista</a>
            <a href="generator_leadow.php" class="btn btn-secondary btn-sm"><i class="bi bi-map me-1"></i>Generator</a>
        </div>
    </div>

    <?php if ($dbError): ?>
        <div class="alert alert-danger">Błąd pobierania danych: <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <section class="kpi-grid mb-4" aria-label="Statystyki leadów">
        <div class="kpi-card">
            <div class="text-muted small">Łącznie leadów</div>
            <div class="kpi-value"><?= $totalLeads ?></div>
            <div class="text-success small">+<?= $newThisWeek ?> w tym tygodniu</div>
        </div>
        <div class="kpi-card">
            <div class="text-muted small">Aktywne rozmowy</div>
            <div class="kpi-value text-info"><?= $activeLeads ?></div>
            <div class="small text-muted">w trakcie kontaktu</div>
        </div>
        <div class="kpi-card">
            <div class="text-muted small">Skonwertowane</div>
            <div class="kpi-value text-success"><?= $convertedLeads ?></div>
            <div class="small text-muted">
                <?php
                $conversionRate = $totalLeads > 0 ? round(($convertedLeads / $totalLeads) * 100) : 0;
                echo $conversionRate . '% skuteczność';
                ?>
            </div>
        </div>
        <div class="kpi-card">
            <div class="text-muted small">Follow-up</div>
            <div class="kpi-value text-warning"><?= $followUpsToday ?></div>
            <div class="small text-muted"><?= $overdueFollowUps ?> zaległych</div>
        </div>
    </section>

    <section class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Statusy leadów</span>
                    <a href="lead.php" class="small">przegląd</a>
                </div>
                <ul class="list-group list-group-flush lead-scroll small">
                    <?php if (empty($statusBreakdown)): ?>
                        <li class="list-group-item">Brak danych</li>
                    <?php else: ?>
                        <?php foreach ($statusBreakdown as $row):
                            $percent = $totalLeads > 0 ? round(($row['total'] / $totalLeads) * 100) : 0;
                            $badge = $leadStatusBadges[$row['status']] ?? 'bg-secondary';
                            ?>
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <span class="fw-semibold"><?= htmlspecialchars($leadStatuses[$row['status']] ?? ucfirst($row['status'])) ?></span>
                                    <span class="badge bg-light text-dark"><?= $row['total'] ?> (<?= $percent ?>%)</span>
                                </div>
                                <div class="progress" style="height:6px">
                                    <div class="progress-bar <?= htmlspecialchars($badge) ?>" style="width: <?= $percent ?>%"></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">Nadchodzące działania</div>
                <ul class="list-group list-group-flush lead-scroll small">
                    <?php if (empty($upcomingActions)): ?>
                        <li class="list-group-item">Brak zaplanowanych follow-upów.</li>
                    <?php else: ?>
                        <?php foreach ($upcomingActions as $action): ?>
                            <?php
                            $actionDate = '';
                            if (!empty($action['next_action_at'])) {
                                try {
                                    $actionDate = (new DateTime($action['next_action_at']))->format('d.m.Y H:i');
                                } catch (Throwable $e) {
                                    $actionDate = $action['next_action_at'];
                                }
                            }
                            ?>
                            <li class="list-group-item d-flex justify-content-between">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($action['nazwa_firmy']) ?></div>
                                    <div class="text-muted">
                                        Tel: <?= htmlspecialchars($action['telefon'] ?: '—') ?>
                                        <?php if (!empty($action['next_action'])): ?>
                                            • <?= htmlspecialchars($action['next_action']) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <?= renderBadge('lead_status', (string)($leadStatuses[$action['status']] ?? $action['status'])) ?>
                                    <div><?= htmlspecialchars($actionDate ?: '—') ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                <div class="card-footer text-end">
                    <a href="lead.php" class="btn btn-sm btn-outline-primary">Zarządzaj działaniami</a>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">Źródła leadów</div>
                <div class="card-body lead-scroll small">
                    <?php if (empty($sourceBreakdown)): ?>
                        <p class="text-muted mb-0">Brak danych o źródłach.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                            <?php foreach ($sourceBreakdown as $row): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?= htmlspecialchars($leadSources[$row['zrodlo']] ?? ucfirst($row['zrodlo'])) ?>
                                    <span class="badge bg-light text-dark"><?= $row['total'] ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
                <div class="card-footer text-end">
                    <a href="generator_leadow.php" class="btn btn-sm btn-outline-success">Generator leadów</a>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-3">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Ostatnie leady</span>
                    <a href="lead.php" class="small">Pełna lista</a>
                </div>
                <div class="table-responsive lead-scroll">
                    <table class="table table-zebra table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Nazwa</th>
                                <th>Status</th>
                                <th>Źródło</th>
                                <th>Utworzono</th>
                                <th>Nast. krok</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($recentLeads)): ?>
                            <tr><td colspan="6" class="text-center text-muted">Brak dodanych leadów.</td></tr>
                        <?php else: ?>
                            <?php foreach ($recentLeads as $lead): ?>
                                <?php
                                $recentAction = '';
                                if (!empty($lead['next_action_at'])) {
                                    try {
                                        $recentAction = (new DateTime($lead['next_action_at']))->format('d.m.Y H:i');
                                    } catch (Throwable $e) {
                                        $recentAction = $lead['next_action_at'];
                                    }
                                }
                                ?>
                                <tr>
                                    <td>
                                        <a href="lead_szczegoly.php?id=<?= (int)$lead['id'] ?>">
                                            <?= htmlspecialchars($lead['nazwa_firmy']) ?>
                                        </a>
                                    </td>
                                    <td>
                                        <?= renderBadge('lead_status', (string)($leadStatuses[$lead['status']] ?? $lead['status'])) ?>
                                    </td>
                                    <td><?= htmlspecialchars($leadSources[$lead['zrodlo']] ?? $lead['zrodlo']) ?></td>
                                    <td><?= htmlspecialchars($lead['created_at']) ?></td>
                                    <td><?= $recentAction ?: '—' ?></td>
                                    <td class="text-end table-actions">
                                        <a href="lead_edytuj.php?id=<?= (int)$lead['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">Wymagają uwagi</div>
                <ul class="list-group list-group-flush lead-scroll small">
                    <?php if (empty($staleLeads)): ?>
                        <li class="list-group-item">Brak zaległych leadów.</li>
                    <?php else: ?>
                        <?php foreach ($staleLeads as $lead): ?>
                            <?php
                            $staleAction = '';
                            if (!empty($lead['next_action_at'])) {
                                try {
                                    $staleAction = (new DateTime($lead['next_action_at']))->format('d.m.Y H:i');
                                } catch (Throwable $e) {
                                    $staleAction = $lead['next_action_at'];
                                }
                            }
                            ?>
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($lead['nazwa_firmy']) ?></div>
                                        <div class="text-muted">Tel: <?= htmlspecialchars($lead['telefon'] ?: '—') ?></div>
                                    </div>
                                    <div class="text-end">
                                        <small class="text-muted">Aktualizacja</small><br>
                                        <strong><?= htmlspecialchars($lead['updated_at']) ?></strong>
                                    </div>
                                </div>
                                <div class="mt-1">
                                    <?= renderBadge('lead_status', (string)($leadStatuses[$lead['status']] ?? $lead['status'])) ?>
                                    <?php if ($staleAction): ?>
                                        <small class="ms-2">Nast. krok: <?= htmlspecialchars($staleAction) ?></small>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                <div class="card-footer text-end">
                    <a href="lead.php?lead_status=w_kontakcie" class="btn btn-outline-danger btn-sm">Działaj teraz</a>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>


