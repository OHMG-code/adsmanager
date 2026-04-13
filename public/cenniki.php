<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin();

$user = currentUser();
$role = normalizeRole($user);
$isAllowedPricingRole = in_array($role, ['Administrator', 'Manager'], true) || !empty($user['is_admin_override']);
if (!$isAllowedPricingRole) {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<h1>403 Forbidden</h1><p>Brak uprawnień do modułu cenników.</p>';
    exit;
}

$pageTitle = "Oferta i rozliczenia - Cenniki oferty";
include 'includes/header.php';
require_once '../config/config.php';

function oblicz_brutto($netto, $vat) {
    return round($netto * (1 + $vat / 100), 2);
}

function ensureCennikPatronatTable(PDO $pdo): void
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cennik_patronat (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pozycja VARCHAR(255) NOT NULL,
                stawka_netto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                stawka_vat DECIMAL(5,2) NOT NULL DEFAULT 23.00,
                data_modyfikacji TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('cenniki.php: cannot ensure cennik_patronat: ' . $e->getMessage());
    }
}

ensureCennikPatronatTable($pdo);
$csrfToken = getCsrfToken();
?>

<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Oferta i rozliczenia</p>
            <h1 class="h3 mb-2">Cenniki oferty</h1>
            <p class="text-muted mb-0">Konfiguracja oferty i stawek dostępna dla managera i administratora.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm <?= is_active('cenniki.php') ? 'btn-primary' : 'btn-outline-secondary' ?>" href="cenniki.php">Cenniki oferty</a>
            <a class="btn btn-sm <?= is_active('cele.php') ? 'btn-primary' : 'btn-outline-secondary' ?>" href="cele.php">Cele sprzedażowe</a>
            <a class="btn btn-sm <?= is_active('prowizje.php') ? 'btn-primary' : 'btn-outline-secondary' ?>" href="prowizje.php">Prowizje handlowców</a>
        </div>
    </div>

    <div class="alert alert-secondary small">
        Cenniki pozostają konfiguracją oferty i rozliczeń, a nie modułem codziennej sprzedaży.
    </div>

      <?php
$wiadomosci = [
    'saved' => ['success', '✅ Dane zostały zapisane.'],
    'deleted' => ['info', '🗑️ Pozycja została usunięta.'],
    'added' => ['success', '✅ Nowa pozycja została dodana.'],
    'error' => ['danger', '❌ Wystąpił błąd przy operacji.'],
];

if (isset($_GET['msg'], $wiadomosci[$_GET['msg']])) {
    [$typ, $tekst] = $wiadomosci[$_GET['msg']];
    echo "<div class=\"alert alert-$typ\">$tekst</div>";
}
?>
    <ul class="nav nav-tabs mt-4" id="tabCenniki" role="tablist">
        <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#spoty">🎧 Spoty audio</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#sygnaly">📻 Sygnały sponsorskie</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#wywiady">🎙️ Wywiady</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#display">🌐 Kampanie display</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#social">📱 Social media</a></li>
        <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#patronat">🤝 Patronat medialny</a></li>
    </ul>

    <div class="tab-content mt-4">
        <!-- Zakładka: Spoty audio -->
        <div class="tab-pane fade show active" id="spoty">
            <form method="post" action="<?= BASE_URL ?>/zapisz_cennik.php?typ=spoty">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <table class="table table-bordered align-middle text-center">
                    <thead>
                        <tr>
                            <th>Długość</th>
                            <th>Pasmo emisji</th>
                            <th>Netto</th>
                            <th>VAT (%)</th>
                            <th>Brutto</th>
                            <th>🗑️</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $spoty = $pdo->query("SELECT * FROM cennik_spoty ORDER BY dlugosc, pasmo")->fetchAll();
                        foreach ($spoty as $s):
                            $brutto = oblicz_brutto($s['stawka_netto'], $s['stawka_vat']);
                        ?>
                            <tr>
                                <td><?= $s['dlugosc'] ?></td>
                                <td><?= $s['pasmo'] ?></td>
                                <td><input type="text" name="netto[<?= $s['id'] ?>]" value="<?= $s['stawka_netto'] ?>" class="form-control"></td>
                                <td><input type="text" name="vat[<?= $s['id'] ?>]" value="<?= $s['stawka_vat'] ?>" class="form-control"></td>
                                <td><strong><?= number_format($brutto, 2, ',', ' ') ?> zł</strong></td>
                                <td>
                                    <form method="post" action="<?= BASE_URL ?>/usun_cennik.php?typ=spoty" onsubmit="return confirm('Usunąć pozycję?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-end mb-3">
                    <button class="btn btn-primary">💾 Zapisz zmiany</button>
                </div>
            </form>

            <!-- Dodawanie -->
            <h6>➕ Dodaj nową pozycję</h6>
            <form method="post" action="<?= BASE_URL ?>/dodaj_cennik.php?typ=spoty" class="row g-2 align-items-end mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="col-md-2">
                    <label class="form-label">Długość</label>
                    <select name="dlugosc" class="form-select" required>
                        <option value="15">15</option>
                        <option value="20">20</option>
                        <option value="30">30</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Pasmo emisji</label>
                    <select name="pasmo" class="form-select" required>
                        <option value="Prime Time">Prime Time</option>
                        <option value="Standard Time">Standard Time</option>
                        <option value="Night Time">Night Time</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Netto</label>
                    <input type="number" name="netto" class="form-control" step="0.01" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">VAT (%)</label>
                    <input type="number" name="vat" class="form-control" step="0.01" value="23.00">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-success w-100">Dodaj</button>
                </div>
            </form>
        </div>
        <!-- Zakładka: Sygnały sponsorskie -->
        <div class="tab-pane fade" id="sygnaly">
            <form method="post" action="<?= BASE_URL ?>/zapisz_cennik.php?typ=sygnaly">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <table class="table table-bordered align-middle text-center">
                    <thead>
                        <tr>
                            <th>Typ programu</th>
                            <th>Netto</th>
                            <th>VAT (%)</th>
                            <th>Brutto</th>
                            <th>🗑️</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sygnaly = $pdo->query("SELECT * FROM cennik_sygnaly ORDER BY typ_programu")->fetchAll();
                        foreach ($sygnaly as $s):
                            $brutto = oblicz_brutto($s['stawka_netto'], $s['stawka_vat']);
                        ?>
                            <tr>
                                <td><?= $s['typ_programu'] ?></td>
                                <td><input type="text" name="netto[<?= $s['id'] ?>]" value="<?= $s['stawka_netto'] ?>" class="form-control"></td>
                                <td><input type="text" name="vat[<?= $s['id'] ?>]" value="<?= $s['stawka_vat'] ?>" class="form-control"></td>
                                <td><strong><?= number_format($brutto, 2, ',', ' ') ?> zł</strong></td>
                                <td>
                                    <form method="post" action="<?= BASE_URL ?>/usun_cennik.php?typ=sygnaly" onsubmit="return confirm('Usunąć pozycję?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-end mb-3">
                    <button class="btn btn-primary">💾 Zapisz zmiany</button>
                </div>
            </form>

            <!-- Dodawanie -->
            <h6>➕ Dodaj nową pozycję</h6>
            <form method="post" action="<?= BASE_URL ?>/dodaj_cennik.php?typ=sygnaly" class="row g-2 align-items-end mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="col-md-4">
                    <label class="form-label">Typ programu</label>
                    <select name="typ_programu" class="form-select" required>
                        <option value="Prognoza pogody">Prognoza pogody</option>
                        <option value="Serwis drogowy">Serwis drogowy</option>
                        <option value="Sponsor ogólny">Sponsor ogólny</option>
                        <option value="Sponsor programu">Sponsor programu</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Netto</label>
                    <input type="number" name="netto" step="0.01" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">VAT (%)</label>
                    <input type="number" name="vat" step="0.01" class="form-control" value="23.00">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-success w-100">Dodaj</button>
                </div>
            </form>
        </div>

        <!-- Zakładka: Wywiady sponsorowane -->
        <div class="tab-pane fade" id="wywiady">
            <form method="post" action="<?= BASE_URL ?>/zapisz_cennik.php?typ=wywiady">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <table class="table table-bordered align-middle text-center">
                    <thead>
                        <tr>
                            <th>Nazwa</th>
                            <th>Opis</th>
                            <th>Netto</th>
                            <th>VAT (%)</th>
                            <th>Brutto</th>
                            <th>🗑️</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $wywiady = $pdo->query("SELECT * FROM cennik_wywiady")->fetchAll();
                        foreach ($wywiady as $w):
                            $brutto = oblicz_brutto($w['stawka_netto'], $w['stawka_vat']);
                        ?>
                            <tr>
                                <td><input type="text" name="nazwa[<?= $w['id'] ?>]" value="<?= $w['nazwa'] ?>" class="form-control"></td>
                                <td><textarea name="opis[<?= $w['id'] ?>]" class="form-control"><?= $w['opis'] ?></textarea></td>
                                <td><input type="text" name="netto[<?= $w['id'] ?>]" value="<?= $w['stawka_netto'] ?>" class="form-control"></td>
                                <td><input type="text" name="vat[<?= $w['id'] ?>]" value="<?= $w['stawka_vat'] ?>" class="form-control"></td>
                                <td><strong><?= number_format($brutto, 2, ',', ' ') ?> zł</strong></td>
                                <td>
                                    <form method="post" action="<?= BASE_URL ?>/usun_cennik.php?typ=wywiady" onsubmit="return confirm('Usunąć pozycję?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= $w['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-end mb-3">
                    <button class="btn btn-primary">💾 Zapisz zmiany</button>
                </div>
            </form>

            <!-- Dodawanie -->
            <h6>➕ Dodaj nową pozycję</h6>
            <form method="post" action="<?= BASE_URL ?>/dodaj_cennik.php?typ=wywiady" class="row g-2 align-items-end mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="col-md-3">
                    <label class="form-label">Nazwa</label>
                    <input type="text" name="nazwa" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Opis</label>
                    <input type="text" name="opis" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Netto</label>
                    <input type="number" name="netto" step="0.01" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">VAT (%)</label>
                    <input type="number" name="vat" step="0.01" class="form-control" value="23.00">
                </div>
                <div class="col-md-1">
                    <button class="btn btn-success w-100">Dodaj</button>
                </div>
            </form>
        </div>
        <!-- Zakładka: Kampanie display -->
        <div class="tab-pane fade" id="display">
            <form method="post" action="<?= BASE_URL ?>/zapisz_cennik.php?typ=display">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <table class="table table-bordered align-middle text-center">
                    <thead>
                        <tr>
                            <th>Format</th>
                            <th>Opis</th>
                            <th>Netto</th>
                            <th>VAT (%)</th>
                            <th>Brutto</th>
                            <th>🗑️</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $display = $pdo->query("SELECT * FROM cennik_display")->fetchAll();
                        foreach ($display as $d):
                            $brutto = oblicz_brutto($d['stawka_netto'], $d['stawka_vat']);
                        ?>
                            <tr>
                                <td><input type="text" name="format[<?= $d['id'] ?>]" value="<?= $d['format'] ?>" class="form-control"></td>
                                <td><textarea name="opis[<?= $d['id'] ?>]" class="form-control"><?= $d['opis'] ?></textarea></td>
                                <td><input type="text" name="netto[<?= $d['id'] ?>]" value="<?= $d['stawka_netto'] ?>" class="form-control"></td>
                                <td><input type="text" name="vat[<?= $d['id'] ?>]" value="<?= $d['stawka_vat'] ?>" class="form-control"></td>
                                <td><strong><?= number_format($brutto, 2, ',', ' ') ?> zł</strong></td>
                                <td>
                                    <form method="post" action="<?= BASE_URL ?>/usun_cennik.php?typ=display" onsubmit="return confirm('Usunąć pozycję?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-end mb-3">
                    <button class="btn btn-primary">💾 Zapisz zmiany</button>
                </div>
            </form>

            <!-- Dodawanie -->
            <h6>➕ Dodaj nową pozycję</h6>
            <form method="post" action="<?= BASE_URL ?>/dodaj_cennik.php?typ=display" class="row g-2 align-items-end mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="col-md-3">
                    <label class="form-label">Format</label>
                    <input type="text" name="format" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Opis</label>
                    <input type="text" name="opis" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Netto</label>
                    <input type="number" name="netto" step="0.01" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">VAT (%)</label>
                    <input type="number" name="vat" step="0.01" class="form-control" value="23.00">
                </div>
                <div class="col-md-1">
                    <button class="btn btn-success w-100">Dodaj</button>
                </div>
            </form>
        </div>

        <!-- Zakładka: Social media -->
        <div class="tab-pane fade" id="social">
            <form method="post" action="<?= BASE_URL ?>/zapisz_cennik.php?typ=social">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <table class="table table-bordered align-middle text-center">
                    <thead>
                        <tr>
                            <th>Platforma</th>
                            <th>Rodzaj postu</th>
                            <th>Netto</th>
                            <th>VAT (%)</th>
                            <th>Brutto</th>
                            <th>🗑️</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $social = $pdo->query("SELECT * FROM cennik_social")->fetchAll();
                        foreach ($social as $s):
                            $brutto = oblicz_brutto($s['stawka_netto'], $s['stawka_vat']);
                        ?>
                            <tr>
                                <td><input type="text" name="platforma[<?= $s['id'] ?>]" value="<?= $s['platforma'] ?>" class="form-control"></td>
                                <td><input type="text" name="rodzaj_postu[<?= $s['id'] ?>]" value="<?= $s['rodzaj_postu'] ?>" class="form-control"></td>
                                <td><input type="text" name="netto[<?= $s['id'] ?>]" value="<?= $s['stawka_netto'] ?>" class="form-control"></td>
                                <td><input type="text" name="vat[<?= $s['id'] ?>]" value="<?= $s['stawka_vat'] ?>" class="form-control"></td>
                                <td><strong><?= number_format($brutto, 2, ',', ' ') ?> zł</strong></td>
                                <td>
                                    <form method="post" action="<?= BASE_URL ?>/usun_cennik.php?typ=social" onsubmit="return confirm('Usunąć pozycję?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= $s['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-end mb-3">
                    <button class="btn btn-primary">💾 Zapisz zmiany</button>
                </div>
            </form>

            <!-- Dodawanie -->
            <h6>➕ Dodaj nową pozycję</h6>
            <form method="post" action="<?= BASE_URL ?>/dodaj_cennik.php?typ=social" class="row g-2 align-items-end mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="col-md-3">
                    <label class="form-label">Platforma</label>
                    <input type="text" name="platforma" class="form-control" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Rodzaj postu</label>
                    <input type="text" name="rodzaj_postu" class="form-control">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Netto</label>
                    <input type="number" name="netto" step="0.01" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">VAT (%)</label>
                    <input type="number" name="vat" step="0.01" class="form-control" value="23.00">
                </div>
                <div class="col-md-1">
                    <button class="btn btn-success w-100">Dodaj</button>
                </div>
            </form>
        </div>

        <!-- Zakładka: Patronat medialny -->
        <div class="tab-pane fade" id="patronat">
            <form method="post" action="<?= BASE_URL ?>/zapisz_cennik.php?typ=patronat">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <table class="table table-bordered align-middle text-center">
                    <thead>
                        <tr>
                            <th>Pozycja</th>
                            <th>Netto</th>
                            <th>VAT (%)</th>
                            <th>Brutto</th>
                            <th>🗑️</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $patronatRows = $pdo->query("SELECT * FROM cennik_patronat ORDER BY pozycja")->fetchAll();
                        foreach ($patronatRows as $p):
                            $brutto = oblicz_brutto($p['stawka_netto'], $p['stawka_vat']);
                        ?>
                            <tr>
                                <td><input type="text" name="pozycja[<?= $p['id'] ?>]" value="<?= htmlspecialchars((string)$p['pozycja']) ?>" class="form-control" required></td>
                                <td><input type="text" name="netto[<?= $p['id'] ?>]" value="<?= htmlspecialchars((string)$p['stawka_netto']) ?>" class="form-control"></td>
                                <td><input type="text" name="vat[<?= $p['id'] ?>]" value="<?= htmlspecialchars((string)$p['stawka_vat']) ?>" class="form-control"></td>
                                <td><strong><?= number_format($brutto, 2, ',', ' ') ?> zł</strong></td>
                                <td>
                                    <form method="post" action="<?= BASE_URL ?>/usun_cennik.php?typ=patronat" onsubmit="return confirm('Usunąć pozycję?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger">🗑️</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="text-end mb-3">
                    <button class="btn btn-primary">💾 Zapisz zmiany</button>
                </div>
            </form>

            <h6>➕ Dodaj nową pozycję</h6>
            <form method="post" action="<?= BASE_URL ?>/dodaj_cennik.php?typ=patronat" class="row g-2 align-items-end mb-4">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <div class="col-md-5">
                    <label class="form-label">Pozycja</label>
                    <input type="text" name="pozycja" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Netto</label>
                    <input type="number" name="netto" step="0.01" class="form-control" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">VAT (%)</label>
                    <input type="number" name="vat" step="0.01" class="form-control" value="23.00">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-success w-100">Dodaj</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
