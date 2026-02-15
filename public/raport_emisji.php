<?php
require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];
$pageTitle = "Raport emisji";
include 'includes/header.php';

ensureSpotColumns($pdo);
ensureEmisjeSpotowTable($pdo);
ensureKampanieOwnershipColumns($pdo);
ensureClientLeadColumns($pdo);

$dateFrom = $_GET['data_od'] ?? date('Y-m-d');
$dateTo = $_GET['data_do'] ?? date('Y-m-d');
$clientFilter = isset($_GET['klient_id']) && $_GET['klient_id'] !== '' ? (int)$_GET['klient_id'] : null;
$campaignFilter = isset($_GET['kampania_id']) && $_GET['kampania_id'] !== '' ? (int)$_GET['kampania_id'] : null;
$onlyActive = !isset($_GET['tylko_aktywne']) || $_GET['tylko_aktywne'] === '1';

// Lista klientów (z ograniczeniem dla handlowca)
$klienci = [];
if (tableExists($pdo, 'klienci')) {
    $klientCols = getTableColumns($pdo, 'klienci');
    [$klientOwnerWhere, $klientOwnerParams] = ownerConstraint($klientCols, $currentUser, 'k');
    $sqlKlienci = 'SELECT k.id,
        COALESCE(c.name_full, c.name_short, k.nazwa_firmy) AS nazwa_firmy,
        COALESCE(c.nip, k.nip) AS nip
        FROM klienci k
        LEFT JOIN companies c ON c.id = k.company_id';
    if ($klientOwnerWhere) {
        $sqlKlienci .= ' WHERE ' . $klientOwnerWhere;
    }
    $sqlKlienci .= ' ORDER BY nazwa_firmy';
    $stmtK = $pdo->prepare($sqlKlienci);
    $stmtK->execute($klientOwnerParams);
    $klienci = $stmtK->fetchAll(PDO::FETCH_ASSOC);
}

// Lista kampanii (z ograniczeniem dla handlowca)
$kampanie = [];
if (tableExists($pdo, 'kampanie')) {
    $kampCols = getTableColumns($pdo, 'kampanie');
    [$kampOwnerWhere, $kampOwnerParams] = ownerConstraint($kampCols, $currentUser, 'k');
    $sqlKamp = 'SELECT k.id, k.klient_nazwa, k.data_start, k.data_koniec FROM kampanie k';
    if ($kampOwnerWhere) {
        $sqlKamp .= ' WHERE ' . $kampOwnerWhere;
    }
    $sqlKamp .= ' ORDER BY k.data_koniec DESC';
    $stmtKamp = $pdo->prepare($sqlKamp);
    $stmtKamp->execute($kampOwnerParams);
    $kampanie = $stmtKamp->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0">Raport emisji (CSV)</h1>
    </div>

    <div class="card shadow-sm">
        <div class="card-body">
            <form method="get" action="<?= BASE_URL ?>/raport_emisji_csv.php" class="row g-3">
                <div class="col-md-3">
                    <label for="data_od" class="form-label">Data od</label>
                    <input type="date" name="data_od" id="data_od" class="form-control" required value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="col-md-3">
                    <label for="data_do" class="form-label">Data do</label>
                    <input type="date" name="data_do" id="data_do" class="form-control" required value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="col-md-3">
                    <label for="klient_id" class="form-label">Klient</label>
                    <select name="klient_id" id="klient_id" class="form-select">
                        <option value="">— wszyscy —</option>
                        <?php foreach ($klienci as $k): ?>
                            <option value="<?= (int)$k['id'] ?>" <?= $clientFilter === (int)$k['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($k['nazwa_firmy'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="kampania_id" class="form-label">Kampania</label>
                    <select name="kampania_id" id="kampania_id" class="form-select">
                        <option value="">— wszystkie —</option>
                        <?php foreach ($kampanie as $k): ?>
                            <option value="<?= (int)$k['id'] ?>" <?= $campaignFilter === (int)$k['id'] ? 'selected' : '' ?>>
                                #<?= (int)$k['id'] ?> <?= htmlspecialchars($k['klient_nazwa'] ?? '') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex align-items-center">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" id="tylko_aktywne" name="tylko_aktywne" value="1" <?= $onlyActive ? 'checked' : '' ?>>
                        <label class="form-check-label" for="tylko_aktywne">Tylko aktywne spoty</label>
                    </div>
                </div>
                <div class="col-md-8 text-end d-flex align-items-end justify-content-end">
                    <button type="submit" class="btn btn-primary">⬇️ Generuj CSV</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>

