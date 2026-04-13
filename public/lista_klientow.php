<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/company_view_model.php';
require_once __DIR__ . '/../services/gus_queue_status.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo) ?? [];
$currentRole = normalizeRole($currentUser);
$clientColumns = getTableColumns($pdo, 'klienci');
$assignedColumn = hasColumn($clientColumns, 'assigned_user_id')
    ? 'assigned_user_id'
    : (hasColumn($clientColumns, 'owner_user_id') ? 'owner_user_id' : null);
$canSeeOwner = in_array($currentRole, ['Administrator', 'Manager'], true);
$ownerScope = $_GET['owner_scope'] ?? ($canSeeOwner ? 'all' : 'mine');
if (!$canSeeOwner) {
    $ownerScope = 'mine';
}

$pageTitle = "Lista klientów";
$csrfToken = getCsrfToken();

$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$messages = [
    'updated'          => ['type' => 'success', 'text' => 'Dane klienta zostały zaktualizowane.'],
    'deleted'          => ['type' => 'success', 'text' => 'Klient został usunięty.'],
    'error_invalid_id' => ['type' => 'danger', 'text' => 'Nieprawidłowy identyfikator klienta.'],
    'error_not_found'  => ['type' => 'warning', 'text' => 'Nie znaleziono wskazanego klienta.'],
    'error_db'         => ['type' => 'danger', 'text' => 'Wystąpił błąd bazy danych.'],
];

$toast = null;
if (isset($_GET['msg'], $messages[$_GET['msg']])) {
    $toast = $messages[$_GET['msg']];
}

$statusBadges = [
    'Nowy' => 'bg-secondary',
    'W trakcie rozmów' => 'bg-info text-dark',
    'Aktywny' => 'bg-success',
    'Zakończony' => 'bg-dark',
];

$clients = [];
$statusOptions = [];
$statusCounts = [];
$dbError = '';

try {
    $statusWhere = '';
    $statusParams = [];
    if ($assignedColumn && $ownerScope === 'mine') {
        $statusWhere = " WHERE {$assignedColumn} = :owner_id";
        $statusParams[':owner_id'] = (int)($currentUser['id'] ?? 0);
    }
    $statusStmt = $pdo->prepare("SELECT DISTINCT status FROM klienci{$statusWhere} ORDER BY status");
    $statusStmt->execute($statusParams);
    $statusOptions = $statusStmt->fetchAll(PDO::FETCH_COLUMN);

    $countStmt = $pdo->prepare("SELECT status, COUNT(*) AS total FROM klienci{$statusWhere} GROUP BY status");
    $countStmt->execute($statusParams);
    $statusCounts = $countStmt->fetchAll();

    $conditions = [];
    $params = [];

    if ($search !== '') {
        $conditions[] = "(COALESCE(c.name_full, c.name_short, k.nazwa_firmy) LIKE :search OR COALESCE(c.nip, k.nip) LIKE :search OR k.email LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }
    if ($statusFilter !== '') {
        $conditions[] = "status = :status";
        $params[':status'] = $statusFilter;
    }

    if ($assignedColumn && $ownerScope === 'mine') {
        $conditions[] = "k.{$assignedColumn} = :owner_id";
        $params[':owner_id'] = (int)($currentUser['id'] ?? 0);
    }

    $ownerSelect = '';
    $ownerJoin = '';
    if ($assignedColumn && $canSeeOwner) {
        $ownerSelect = ", k.{$assignedColumn} AS assigned_user_id, u.login AS owner_login, u.imie AS owner_imie, u.nazwisko AS owner_nazwisko";
        $ownerJoin = " LEFT JOIN uzytkownicy u ON u.id = k.{$assignedColumn}";
    }

    $sql = "SELECT
                k.id AS k_id,
                k.nazwa_firmy AS k_nazwa_firmy,
                k.nip AS k_nip,
                k.regon AS k_regon,
                k.email AS k_email,
                k.telefon AS k_telefon,
                k.miejscowosc AS k_miejscowosc,
                k.status AS k_status,
                k.data_dodania AS k_data_dodania,
                k.company_id AS k_company_id,
                c.name_full AS c_name_full,
                c.name_short AS c_name_short,
                c.nip AS c_nip,
                c.regon AS c_regon,
                c.city AS c_city,
                c.id AS c_id{$ownerSelect}
            FROM klienci k
            LEFT JOIN companies c ON c.id = k.company_id{$ownerJoin}";
    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }
    $sql .= ' ORDER BY data_dodania DESC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $clients = [];
    $companyIds = [];
    foreach ($rows as $row) {
        if (!empty($row['k_company_id'])) {
            $companyIds[] = (int)$row['k_company_id'];
        } elseif (!empty($row['c_id'])) {
            $companyIds[] = (int)$row['c_id'];
        }
    }
    $statusService = new GusQueueStatus($pdo);
    $statusMap = $statusService->enrichCompanies($companyIds);

    foreach ($rows as $row) {
        $cid = (int)($row['k_company_id'] ?? $row['c_id'] ?? 0);
        $clients[] = [
            'id' => (int)$row['k_id'],
            'email' => $row['k_email'] ?? null,
            'telefon' => $row['k_telefon'] ?? null,
            'miejscowosc' => $row['k_miejscowosc'] ?? null,
            'status' => $row['k_status'] ?? null,
            'data_dodania' => $row['k_data_dodania'] ?? null,
            'owner_login' => $row['owner_login'] ?? null,
            'owner_imie' => $row['owner_imie'] ?? null,
            'owner_nazwisko' => $row['owner_nazwisko'] ?? null,
            'assigned_user_id' => $row['assigned_user_id'] ?? null,
            'vm' => buildCompanyViewModelFromJoined($row, 'k_', 'c_', $statusMap[$cid] ?? []),
        ];
    }
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

include __DIR__ . '/includes/header.php';
?>

<main class="content-wide py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <p class="text-muted mb-0">Operacje GUS dla aktualnego widoku</p>
        </div>
        <div class="d-flex gap-2">
            <form method="post" action="handlers/gus_enqueue_bulk.php" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="mode" value="filter">
                <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
                <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter) ?>">
                <input type="hidden" name="owner_scope" value="<?= htmlspecialchars($ownerScope) ?>">
                <button type="submit" class="btn btn-outline-success btn-sm">Odśwież GUS (wynik filtra)</button>
            </form>
            <button type="submit" form="bulkSelectedForm" class="btn btn-outline-primary btn-sm">Odśwież GUS (zaznaczone)</button>
        </div>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">Lista klientów</h1>
            <p class="text-muted mb-0">Przeglądaj, filtruj i zarządzaj bazą klientów.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="dodaj_klienta.php" class="btn btn-primary btn-sm">Dodaj klienta</a>
            <a href="klienci.php" class="btn btn-outline-secondary btn-sm">Przegląd</a>
        </div>
    </div>

    <?php if ($toast): ?>
        <div class="alert alert-<?= htmlspecialchars($toast['type']) ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($toast['text']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Zamknij"></button>
        </div>
    <?php endif; ?>

    <?php if ($dbError): ?>
        <div class="alert alert-danger">Błąd bazy danych: <?= htmlspecialchars($dbError) ?></div>
    <?php endif; ?>

    <div class="mb-3 d-flex flex-wrap gap-2">
        <?php foreach ($statusCounts as $row): ?>
            <span class="badge <?= htmlspecialchars($statusBadges[$row['status']] ?? 'bg-secondary') ?>">
                <?= htmlspecialchars($row['status'] ?? 'Nieznany') ?>: <?= (int)$row['total'] ?>
            </span>
        <?php endforeach; ?>
    </div>

    <form method="get" class="row g-2 align-items-end mb-4">
        <div class="col-md-4">
            <label class="form-label">Szukaj (Nazwa, NIP, e-mail)</label>
            <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($search) ?>" placeholder="Wpisz frazę...">
        </div>
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">Wszystkie</option>
                <?php foreach ($statusOptions as $status): ?>
                    <option value="<?= htmlspecialchars($status) ?>" <?= $status === $statusFilter ? 'selected' : '' ?>>
                        <?= htmlspecialchars($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($canSeeOwner && $assignedColumn): ?>
            <div class="col-md-2">
                <label class="form-label">Widok</label>
                <select name="owner_scope" class="form-select">
                    <option value="all" <?= $ownerScope === 'all' ? 'selected' : '' ?>>Wszyscy</option>
                    <option value="mine" <?= $ownerScope === 'mine' ? 'selected' : '' ?>>Moi</option>
                </select>
            </div>
        <?php endif; ?>
        <div class="col-md-3">
            <label class="form-label">&nbsp;</label>
            <div>
                <button type="submit" class="btn btn-outline-primary">Filtruj</button>
                <a href="lista_klientow.php<?= $canSeeOwner && $assignedColumn ? '?owner_scope=' . urlencode($ownerScope) : '' ?>" class="btn btn-link">Wyczyść</a>
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-body">
            <form method="post" action="handlers/gus_enqueue_bulk.php" id="bulkSelectedForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="mode" value="selected">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><input type="checkbox" id="selectAll"></th>
                                <th>ID</th>
                                <th>NIP</th>
                                <th>Nazwa firmy</th>
                                <th>Email</th>
                                <th>Telefon</th>
                                <th>Miejscowość</th>
                                <th>Status</th>
                                <?php if ($canSeeOwner && $assignedColumn): ?>
                                    <th>Opiekun</th>
                                <?php endif; ?>
                                <th>Data dodania</th>
                                <th>Akcje</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($clients)): ?>
                            <tr>
                                <td colspan="<?= $canSeeOwner && $assignedColumn ? '11' : '10' ?>" class="text-center text-muted">Brak klientów do wyświetlenia.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($clients as $client): ?>
                                <?php
                                $ownerLabel = '—';
                                $ownerBadge = '';
                                if ($canSeeOwner && $assignedColumn) {
                                    $ownerId = (int)($client['assigned_user_id'] ?? 0);
                                    if ($ownerId > 0) {
                                    $ownerLabel = trim(($client['owner_imie'] ?? '') . ' ' . ($client['owner_nazwisko'] ?? ''));
                                    if ($ownerLabel === '') {
                                        $ownerLabel = $client['owner_login'] ?? ('User #' . $ownerId);
                                    }
                                } else {
                                    $ownerBadge = '<span class="badge badge-neutral">Nieprzypisany</span>';
                                }
                            }
                            $vm = $client['vm'] ?? [];
                            $cid = (int)($vm['company_id'] ?? 0);
                            ?>
                            <tr>
                                <td><?php if ($cid > 0): ?><input type="checkbox" name="client_ids[]" value="<?= (int)$client['id'] ?>" class="client-check"><?php endif; ?></td>
                                <td><?= (int)$client['id'] ?></td>
                                <td><?= htmlspecialchars($vm['nip_display'] ?? '') ?></td>
                                <td class="fw-semibold">
                                    <a href="klient_szczegoly.php?id=<?= (int)$client['id'] ?>">
                                        <?= htmlspecialchars($vm['company_name_display'] ?? '') ?>
                                    </a>
                                    <?php if (!empty($vm['gus_display_line'])): ?>
                                        <div class="small text-muted"><?= htmlspecialchars($vm['gus_display_line']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($client['email'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($client['telefon'] ?: '—') ?></td>
                                <td><?= htmlspecialchars($vm['address_city_display'] ?? ($client['miejscowosc'] ?: '—')) ?></td>
                                <td>
                                    <span class="badge <?= htmlspecialchars($statusBadges[$client['status']] ?? 'bg-secondary') ?>">
                                        <?= htmlspecialchars($client['status'] ?? '—') ?>
                                    </span>
                                </td>
                                <?php if ($canSeeOwner && $assignedColumn): ?>
                                    <td><?= $ownerBadge ?: htmlspecialchars($ownerLabel) ?></td>
                                <?php endif; ?>
                                <td><?= htmlspecialchars($client['data_dodania']) ?></td>
                                <td class="text-nowrap">
                                    <a href="klient_edytuj.php?id=<?= (int)$client['id'] ?>" class="btn btn-sm btn-outline-secondary">Edytuj</a>
                                    <a href="usun_klienta.php?id=<?= (int)$client['id'] ?>"
                                       class="btn btn-sm btn-outline-danger"
                                       onclick="return confirm('Czy na pewno chcesz usunąć klienta?');">
                                        Usuń
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            </form>
        </div>
    </div>
</main>

<?php include __DIR__ . '/includes/footer.php'; ?>
<script>
document.getElementById('selectAll')?.addEventListener('change', function(e) {
    document.querySelectorAll('.client-check').forEach(cb => { cb.checked = e.target.checked; });
});
</script>
