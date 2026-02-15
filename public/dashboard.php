<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/company_view_model.php';

// Pobranie danych — nie zmieniaj tej logiki
try {
    $campaigns_count = (int) ($pdo->query('SELECT COUNT(*) FROM kampanie_tygodniowe')->fetchColumn() ?? 0);
    $clients_count   = (int) ($pdo->query('SELECT COUNT(*) FROM klienci')->fetchColumn() ?? 0);
    $spots_count     = (int) ($pdo->query('SELECT COUNT(*) FROM spoty')->fetchColumn() ?? 0);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM kampanie_tygodniowe WHERE data_start <= CURDATE() AND data_koniec >= CURDATE()');
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
    $latest_campaigns = $pdo->query("SELECT id, klient_nazwa, data_start, data_koniec, razem_brutto FROM kampanie_tygodniowe ORDER BY id DESC LIMIT 5")->fetchAll();
    $running_spots = $pdo->query("SELECT
            s.id, s.nazwa_spotu, s.dlugosc, s.data_start, s.data_koniec,
            COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy
        FROM spoty s
        LEFT JOIN klienci k ON k.id = s.klient_id
        LEFT JOIN companies c ON c.id = k.company_id
        WHERE s.data_start <= CURDATE() AND s.data_koniec >= CURDATE()
        ORDER BY s.data_start DESC
        LIMIT 5")->fetchAll();

} catch (Exception $e) {
    echo '<div class="alert alert-danger">Błąd połączenia z bazą: ' . htmlspecialchars($e->getMessage()) . '</div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}
?>

<!-- Optional: Bootstrap Icons (header.php provides bootstrap CSS already) -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

<style>
    /* Small dashboard helpers */
    .card .display-6 { font-size: 2rem; }
    .card .fw-semibold { font-weight: 600; }
    .card .small { font-size: 0.78rem; }
    .badge { font-size: 0.75rem; }
    .table-responsive { max-height: 380px; overflow: auto; }
    @media (max-width: 575.98px) { .display-6 { font-size: 1.25rem; } }
</style>

<main class="container py-3" role="main" aria-labelledby="dashboard-heading">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 id="dashboard-heading" class="h4 mb-0">Panel główny</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Dashboard</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            <a href="kalkulator_tygodniowy.php" class="btn btn-primary btn-sm" aria-label="Kalkulator kampanii"><i class="bi bi-calculator me-1"></i>Kalkulator</a>
            <a href="klienci.php" class="btn btn-outline-secondary btn-sm" aria-label="Lista klientów"><i class="bi bi-people"></i></a>
        </div>
    </div>

    <!-- Quick actions -->
    <section class="mb-3" aria-label="Szybkie akcje">
        <div class="d-flex flex-wrap gap-2">
            <a href="dodaj_klienta.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Dodaj klienta</a>
            <a href="kalkulator_tygodniowy.php" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-plus me-1"></i>Nowa kampania</a>
            <a href="add_spot.php" class="btn btn-outline-warning btn-sm"><i class="bi bi-mic me-1"></i>Dodaj spot</a>
        </div>
    </section>

    <!-- KPI row -->
    <section class="row g-3 mb-3" aria-label="Wskaźniki">
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
                    <div class="me-3 display-6 text-info"><i class="bi bi-lightning-charge"></i></div>
                    <div>
                        <div class="small text-muted">Szybkie akcje</div>
                        <div class="h6 mb-0">Skróty</div>
                        <small class="text-muted">Dodawaj i zarządzaj szybciej</small>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Trends & quick stats -->
    <section class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card h-100">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 mb-0">Trendy kampanii</h2>
                    <div class="btn-group btn-group-sm" role="group" aria-label="Zakres danych">
                        <button class="btn btn-outline-secondary active" data-range="7">7d</button>
                        <button class="btn btn-outline-secondary" data-range="30">30d</button>
                        <button class="btn btn-outline-secondary" data-range="90">90d</button>
                    </div>
                </div>
                <div class="card-body">
                    <canvas id="campaignsChart" height="120" aria-label="Wykres kampanii" role="img"></canvas>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card h-100">
                <div class="card-header">
                    <h2 class="h6 mb-0">Metryki</h2>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between"><small class="text-muted">Przychód (miesiąc)</small><strong>—</strong></div>
                        <div class="progress" style="height:8px"><div class="progress-bar bg-success" style="width: 62%"></div></div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between"><small class="text-muted">Śr. wartość kampanii</small><strong>—</strong></div>
                        <div class="progress" style="height:8px"><div class="progress-bar bg-info" style="width: 48%"></div></div>
                    </div>
                    <div>
                        <small class="text-muted">Wskaźnik realizacji</small>
                        <div class="mt-2"><span class="badge bg-success">OK</span> <span class="text-muted small">większość emisji zaplanowana</span></div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Recent lists -->
    <section class="row g-3 mb-4">
        <div class="col-md-4">
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

        <div class="col-md-4">
            <div class="card h-100">
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
                                    <div class="fw-bold"><?= number_format((float)$k['razem_brutto'], 2) ?> zł</div>
                                    <a href="kampania_podglad.php?id=<?= (int)$k['id'] ?>" class="btn btn-sm btn-outline-primary mt-1">Podgląd</a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
                <div class="card-footer"><a href="kampanie_lista.php">Pełna lista</a></div>
            </div>
        </div>

        <div class="col-md-4">
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

    <!-- Optional extended table area -->
    <section class="card mb-4">
        <div class="card-header"><h3 class="h6 mb-0">Najnowsze kampanie — szczegóły</h3></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-sm mb-0" aria-label="Tabela najnowszych kampanii">
                    <thead class="table-light">
                        <tr>
                            <th scope="col">ID</th>
                            <th scope="col">Klient</th>
                            <th scope="col">Okres</th>
                            <th scope="col">Brutto</th>
                            <th scope="col">Akcje</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($latest_campaigns as $k): ?>
                            <tr>
                                <td><?= (int)$k['id'] ?></td>
                                <td><?= htmlspecialchars($k['klient_nazwa']) ?></td>
                                <td><?= htmlspecialchars($k['data_start']) ?> – <?= htmlspecialchars($k['data_koniec']) ?></td>
                                <td><?= number_format((float)$k['razem_brutto'],2) ?> zł</td>
                                <td><a href="kampania_podglad.php?id=<?= (int)$k['id'] ?>" class="btn btn-sm btn-outline-primary">Podgląd</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

</main>

<!-- Scripts: Chart.js init and optional helpers -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
    // Minimal Chart.js init (możesz wczytać dane AJAXem)
    const ctx = document.getElementById('campaignsChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'line',
            data: { labels: ['-'], datasets: [{ label: 'Kampanie', data: [0], borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.06)' }]},
            options: { maintainAspectRatio: false, plugins: { legend: { display: false } } }
        });
    }
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
