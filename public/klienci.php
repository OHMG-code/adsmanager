<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/transaction_metrics.php';
require_once __DIR__ . '/includes/ui_helpers.php';
require_once __DIR__ . '/includes/company_view_model.php';

$requestedAction = $_GET['action'] ?? '';
if ($requestedAction === 'dodaj') {
    header('Location: ' . BASE_URL . '/dodaj_klienta.php');
    exit;
}

$pageTitle = "Klienci";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$flashMessage = $_SESSION['flash'] ?? null;
if ($flashMessage) {
    unset($_SESSION['flash']);
}

$currentUser = getCurrentUser($pdo);
$currentRole = $currentUser ? normalizeRole($currentUser) : '';
$isSalesUser = $currentRole === 'Handlowiec' && !empty($currentUser['id']);
$salesPlanYear = (int)date('Y');
$salesPlanMonth = (int)date('n');
$salesPlanNetto = 0.0;
$salesPlanCurrentNetto = 0.0;
$salesPlanDeltaNetto = 0.0;
$salesPlanConfigured = false;
$monthLabels = [
    1 => 'styczen',
    2 => 'luty',
    3 => 'marzec',
    4 => 'kwiecien',
    5 => 'maj',
    6 => 'czerwiec',
    7 => 'lipiec',
    8 => 'sierpien',
    9 => 'wrzesien',
    10 => 'pazdziernik',
    11 => 'listopad',
    12 => 'grudzien',
];
$salesPlanPeriodLabel = ($monthLabels[$salesPlanMonth] ?? ('miesiac ' . $salesPlanMonth)) . ' ' . $salesPlanYear;

$totalClients = $newThisMonth = $activeClients = $remindersCount = 0;
$statusBreakdown = $latestClients = $upcomingReminders = $topPotential = $industryBreakdown = $staleContacts = [];
$dbError = '';

try {
    $totalClients = (int) ($pdo->query("SELECT COUNT(*) FROM klienci")->fetchColumn() ?? 0);

    $stmt = $pdo->query("SELECT COUNT(*) FROM klienci WHERE YEAR(data_dodania) = YEAR(CURDATE()) AND MONTH(data_dodania) = MONTH(CURDATE())");
    $newThisMonth = (int) ($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->query("SELECT COUNT(*) FROM klienci WHERE status IN ('Aktywny','W trakcie rozmów')");
    $activeClients = (int) ($stmt->fetchColumn() ?? 0);

    $stmt = $pdo->query("SELECT COUNT(*) FROM klienci WHERE przypomnienie IS NOT NULL AND przypomnienie >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)");
    $remindersCount = (int) ($stmt->fetchColumn() ?? 0);

    $statusBreakdown = $pdo->query("SELECT status, COUNT(*) AS total FROM klienci GROUP BY status ORDER BY total DESC")->fetchAll();

    $latestRows = $pdo->query("SELECT
            k.id AS k_id,
            k.nazwa_firmy AS k_nazwa_firmy,
            k.nip AS k_nip,
            k.regon AS k_regon,
            k.telefon AS k_telefon,
            k.status AS k_status,
            k.data_dodania AS k_data_dodania,
            c.name_full AS c_name_full,
            c.name_short AS c_name_short,
            c.nip AS c_nip,
            c.regon AS c_regon
        FROM klienci k
        LEFT JOIN companies c ON c.id = k.company_id
        ORDER BY k.data_dodania DESC
        LIMIT 8")->fetchAll();
    $latestClients = [];
    foreach ($latestRows as $row) {
        $latestClients[] = [
            'id' => (int)$row['k_id'],
            'telefon' => $row['k_telefon'] ?? null,
            'status' => $row['k_status'] ?? null,
            'data_dodania' => $row['k_data_dodania'] ?? null,
            'vm' => buildCompanyViewModelFromJoined($row),
        ];
    }

    $reminderRows = $pdo->query("SELECT
            k.id AS k_id,
            k.nazwa_firmy AS k_nazwa_firmy,
            k.nip AS k_nip,
            k.regon AS k_regon,
            k.przypomnienie AS k_przypomnienie,
            k.telefon AS k_telefon,
            k.status AS k_status,
            c.name_full AS c_name_full,
            c.name_short AS c_name_short,
            c.nip AS c_nip,
            c.regon AS c_regon
        FROM klienci k
        LEFT JOIN companies c ON c.id = k.company_id
        WHERE k.przypomnienie IS NOT NULL
          AND k.przypomnienie >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
        ORDER BY k.przypomnienie ASC
        LIMIT 6")->fetchAll();
    $upcomingReminders = [];
    foreach ($reminderRows as $row) {
        $upcomingReminders[] = [
            'id' => (int)$row['k_id'],
            'przypomnienie' => $row['k_przypomnienie'] ?? null,
            'telefon' => $row['k_telefon'] ?? null,
            'status' => $row['k_status'] ?? null,
            'vm' => buildCompanyViewModelFromJoined($row),
        ];
    }

    $topRows = $pdo->query("SELECT
            k.id AS k_id,
            k.nazwa_firmy AS k_nazwa_firmy,
            k.nip AS k_nip,
            k.regon AS k_regon,
            k.potencjal AS k_potencjal,
            k.status AS k_status,
            c.name_full AS c_name_full,
            c.name_short AS c_name_short,
            c.nip AS c_nip,
            c.regon AS c_regon
        FROM klienci k
        LEFT JOIN companies c ON c.id = k.company_id
        WHERE k.potencjal IS NOT NULL AND k.potencjal > 0
        ORDER BY k.potencjal DESC
        LIMIT 5")->fetchAll();
    $topPotential = [];
    foreach ($topRows as $row) {
        $topPotential[] = [
            'id' => (int)$row['k_id'],
            'potencjal' => $row['k_potencjal'] ?? null,
            'status' => $row['k_status'] ?? null,
            'vm' => buildCompanyViewModelFromJoined($row),
        ];
    }

    $industryBreakdown = $pdo->query("SELECT branza, COUNT(*) AS total FROM klienci WHERE branza IS NOT NULL AND branza <> '' GROUP BY branza ORDER BY total DESC LIMIT 5")->fetchAll();

    $staleRows = $pdo->query("SELECT
            k.id AS k_id,
            k.nazwa_firmy AS k_nazwa_firmy,
            k.nip AS k_nip,
            k.regon AS k_regon,
            k.ostatni_kontakt AS k_ostatni_kontakt,
            k.telefon AS k_telefon,
            c.name_full AS c_name_full,
            c.name_short AS c_name_short,
            c.nip AS c_nip,
            c.regon AS c_regon
        FROM klienci k
        LEFT JOIN companies c ON c.id = k.company_id
        WHERE (k.ostatni_kontakt IS NULL OR k.ostatni_kontakt < DATE_SUB(CURDATE(), INTERVAL 60 DAY))
        ORDER BY k.ostatni_kontakt IS NULL DESC, k.ostatni_kontakt ASC
        LIMIT 5")->fetchAll();
    $staleContacts = [];
    foreach ($staleRows as $row) {
        $staleContacts[] = [
            'id' => (int)$row['k_id'],
            'ostatni_kontakt' => $row['k_ostatni_kontakt'] ?? null,
            'telefon' => $row['k_telefon'] ?? null,
            'vm' => buildCompanyViewModelFromJoined($row),
        ];
    }

    if ($isSalesUser) {
        $salesUserId = (int)$currentUser['id'];
        ensureSalesTargetsTable($pdo);

        $stmt = $pdo->prepare(
            'SELECT target_netto
             FROM cele_sprzedazowe
             WHERE year = :year AND month = :month AND user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            ':year' => $salesPlanYear,
            ':month' => $salesPlanMonth,
            ':user_id' => $salesUserId,
        ]);
        $planValue = $stmt->fetchColumn();
        if ($planValue !== false) {
            $salesPlanConfigured = true;
            $salesPlanNetto = (float)$planValue;
        }

        $monthFrom = sprintf('%04d-%02d-01', $salesPlanYear, $salesPlanMonth);
        $monthTo = date('Y-m-t', strtotime($monthFrom));
        $transactionStats = fetchTransactionStatsByOwner($pdo, $monthFrom, $monthTo, $salesUserId);
        $salesPlanCurrentNetto = (float)($transactionStats['totals']['netto'] ?? 0);

        $salesPlanDeltaNetto = $salesPlanCurrentNetto - $salesPlanNetto;
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

$statusBadges = [
    'Nowy' => 'bg-secondary',
    'W trakcie rozmów' => 'bg-info text-dark',
    'Aktywny' => 'bg-success',
    'Zakończony' => 'bg-dark',
];

include __DIR__ . '/includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<style>
    .client-card .display-6 { font-size: 2rem; }
    .client-card small { font-size: 0.8rem; }
    .client-scroll { max-height: 320px; overflow-y: auto; }
    .status-indicator { width: 32px; height: 32px; border-radius: 8px; }
    @media (max-width: 575.98px) {
        .client-card .display-6 { font-size: 1.4rem; }
    }
</style>

<main class="container py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between mb-3">
        <div>
            <h1 class="h4 mb-1">Centrum klientów</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb small mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                    <li class="breadcrumb-item active">Klienci</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="dodaj_klienta.php" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Dodaj klienta</a>
            <a href="lista_klientow.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul me-1"></i>Lista</a>
        </div>
    </div>

    <?php if ($flashMessage): ?>
        <div class="alert alert-<?= htmlspecialchars($flashMessage['type'] ?? 'info') ?> alert-dismissible fade show" role="alert">
            <?= !empty($flashMessage['raw']) ? ($flashMessage['message'] ?? '') : htmlspecialchars($flashMessage['message'] ?? '') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
        </div>
    <?php endif; ?>

    <?php if ($dbError): ?>
        <div class="alert alert-danger">Błąd pobierania danych: <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <section class="row g-3 mb-4 client-card" aria-label="Statystyki klientów">
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Wszyscy klienci</div>
                    <div class="display-6"><?= $totalClients ?></div>
                    <div class="text-success small">+<?= $newThisMonth ?> w tym miesiącu</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Aktywni / w rozmowach</div>
                    <div class="display-6 text-success"><?= $activeClients ?></div>
                    <div class="small text-muted">w trakcie współpracy</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <div class="text-muted small">Planowane przypomnienia</div>
                    <div class="display-6 text-warning"><?= $remindersCount ?></div>
                    <div class="small text-muted">najbliższe w tabeli obok</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card shadow-sm h-100">
                <div class="card-body">
                    <?php if ($isSalesUser): ?>
                        <?php
                        $deltaPositive = $salesPlanDeltaNetto > 0;
                        $deltaNegative = $salesPlanDeltaNetto < 0;
                        $deltaClass = $deltaPositive ? 'text-success' : ($deltaNegative ? 'text-danger' : 'text-muted');
                        $deltaSign = $deltaPositive ? '+' : ($deltaNegative ? '-' : '');
                        ?>
                        <div class="text-muted small">Plan miesieczny (<?= htmlspecialchars($salesPlanPeriodLabel) ?>)</div>
                        <div class="small">Plan: <strong><?= htmlspecialchars(number_format($salesPlanNetto, 2, ',', ' ')) ?> zł</strong></div>
                        <div class="small">Transakcje netto: <strong><?= htmlspecialchars(number_format($salesPlanCurrentNetto, 2, ',', ' ')) ?> zł</strong></div>
                        <div class="small <?= htmlspecialchars($deltaClass) ?>">
                            Różnica: <strong><?= htmlspecialchars($deltaSign . number_format(abs($salesPlanDeltaNetto), 2, ',', ' ')) ?> zł</strong>
                        </div>
                        <?php if (!$salesPlanConfigured): ?>
                            <div class="small text-warning">Brak ustawionego planu na ten miesiac.</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="text-muted small">Średni potencjał</div>
                        <div class="display-6 text-info">
                            <?php
                            if (!empty($topPotential)) {
                                $avgPotential = array_sum(array_column($topPotential, 'potencjal')) / count($topPotential);
                                echo htmlspecialchars(number_format((float)$avgPotential, 0, ',', ' ')) . ' zł';
                            } else {
                                echo '—';
                            }
                            ?>
                        </div>
                        <div class="small text-muted">na podstawie top 5</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-3 mb-4">
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Struktura statusów</span>
                    <a href="lista_klientow.php" class="small">filtruj</a>
                </div>
                <div class="list-group list-group-flush client-scroll small">
                    <?php if (empty($statusBreakdown)): ?>
                        <div class="list-group-item">Brak danych</div>
                    <?php else: ?>
                        <?php foreach ($statusBreakdown as $row):
                            $percent = $totalClients > 0 ? round(($row['total'] / $totalClients) * 100) : 0;
                            $badge = $statusBadges[$row['status']] ?? 'bg-secondary';
                            ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($row['status'] ?: 'Nieznany') ?></div>
                                    <div class="progress" style="height:6px">
                                        <div class="progress-bar <?= htmlspecialchars($badge) ?>" style="width: <?= $percent ?>%"></div>
                                    </div>
                                </div>
                                <span class="badge bg-light text-dark"><?= $row['total'] ?> (<?= $percent ?>%)</span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">Nadchodzące przypomnienia</div>
                <ul class="list-group list-group-flush client-scroll small">
                    <?php if (empty($upcomingReminders)): ?>
                        <li class="list-group-item">Brak zaplanowanych przypomnień.</li>
                    <?php else: ?>
                        <?php foreach ($upcomingReminders as $item): ?>
                            <?php $vm = $item['vm'] ?? []; ?>
                            <li class="list-group-item d-flex justify-content-between">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($vm['company_name_display'] ?? '') ?></div>
                                    <div class="text-muted">Tel: <?= htmlspecialchars($item['telefon'] ?: '—') ?></div>
                                </div>
                                <div class="text-end">
                                    <span class="badge <?= htmlspecialchars($statusBadges[$item['status']] ?? 'bg-secondary') ?>">
                                        <?= htmlspecialchars($item['status'] ?? '—') ?>
                                    </span>
                                    <div><?= htmlspecialchars($item['przypomnienie']) ?></div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                <div class="card-footer text-end">
                    <a href="lista_klientow.php?status=Nowy" class="btn btn-sm btn-outline-primary">Pracuj z leadami</a>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">Branże i potencjał</div>
                <div class="card-body client-scroll">
                    <h6 class="text-muted text-uppercase small">Top branże</h6>
                    <?php if (empty($industryBreakdown)): ?>
                        <p class="text-muted small">Brak danych o branżach.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush mb-3">
                            <?php foreach ($industryBreakdown as $row): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?= htmlspecialchars($row['branza']) ?>
                                    <span class="badge bg-light text-dark"><?= $row['total'] ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <h6 class="text-muted text-uppercase small">Najwyższy potencjał</h6>
                    <?php if (empty($topPotential)): ?>
                        <p class="text-muted small mb-0">Uzupełnij potencjał klientów w formularzu dodawania.</p>
                    <?php else: ?>
                        <ul class="list-group list-group-flush">
                        <?php foreach ($topPotential as $row): ?>
                            <?php $vm = $row['vm'] ?? []; ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold"><?= htmlspecialchars($vm['company_name_display'] ?? '') ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($row['status'] ?? '') ?></small>
                                </div>
                                <span class="badge bg-success">
                                    <?= htmlspecialchars(number_format((float)$row['potencjal'], 0, ',', ' ')) ?> zł
                                </span>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="row g-3">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Ostatnio dodani klienci</span>
                    <a href="lista_klientow.php" class="small">Zobacz wszystkich</a>
                </div>
                <div class="table-responsive client-scroll">
                    <table class="table table-zebra table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Nazwa</th>
                                <th>NIP</th>
                                <th>Status</th>
                                <th>Telefon</th>
                                <th>Data dodania</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($latestClients)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">Brak klientów do wyświetlenia.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($latestClients as $client): ?>
                                <?php $vm = $client['vm'] ?? []; ?>
                                <tr>
                                    <td>
                                        <a href="klient_szczegoly.php?id=<?= (int)$client['id'] ?>">
                                            <?= htmlspecialchars($vm['company_name_display'] ?? '') ?>
                                        </a>
                                    </td>
                                    <td><?= htmlspecialchars($vm['nip_display'] ?? '') ?></td>
                                    <td>
                                        <?= renderBadge('client_status', (string)($client['status'] ?? '—')) ?>
                                    </td>
                                    <td><?= htmlspecialchars($client['telefon'] ?: '—') ?></td>
                                    <td><?= htmlspecialchars($client['data_dodania']) ?></td>
                                    <td class="text-end table-actions">
                                        <a href="klient_edytuj.php?id=<?= (int)$client['id'] ?>" class="btn btn-sm btn-ghost">
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
                <div class="card-header">Wymagają kontaktu</div>
                <ul class="list-group list-group-flush client-scroll small">
                    <?php if (empty($staleContacts)): ?>
                        <li class="list-group-item">Wszyscy klienci mają świeże kontakty.</li>
                    <?php else: ?>
                        <?php foreach ($staleContacts as $client): ?>
                            <?php $vm = $client['vm'] ?? []; ?>
                            <li class="list-group-item">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <div class="fw-semibold"><?= htmlspecialchars($vm['company_name_display'] ?? '') ?></div>
                                        <div class="text-muted">Tel: <?= htmlspecialchars($client['telefon'] ?: '—') ?></div>
                                    </div>
                                    <div class="text-end">
                                        <small class="text-muted">Ostatni kontakt</small><br>
                                        <strong><?= $client['ostatni_kontakt'] ? htmlspecialchars($client['ostatni_kontakt']) : 'Brak danych' ?></strong>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </ul>
                <div class="card-footer text-end">
                    <a href="lista_klientow.php?q=&status=" class="btn btn-outline-secondary btn-sm">Przefiltruj</a>
                </div>
            </div>
        </div>
    </section>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
