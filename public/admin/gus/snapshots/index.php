<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/auth.php';
require_once __DIR__ . '/../../../../config/config.php';
require_once __DIR__ . '/../../../includes/gus_snapshots.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/index.php');
    exit;
}
$role = normalizeRole($currentUser);
if (!in_array($role, ['Administrator', 'Manager'], true) && !isSuperAdmin()) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}
$isAdmin = $role === 'Administrator' || isSuperAdmin();

$pageTitle = 'GUS snapshots';
$repo = new GusSnapshotRepository($pdo);

$filters = [
    'env' => isset($_GET['env']) ? trim((string)$_GET['env']) : '',
    'request_value' => isset($_GET['request_value']) ? trim((string)$_GET['request_value']) : '',
    'ok' => isset($_GET['ok']) ? trim((string)$_GET['ok']) : '',
    'date_from' => isset($_GET['date_from']) ? trim((string)$_GET['date_from']) : '',
    'date_to' => isset($_GET['date_to']) ? trim((string)$_GET['date_to']) : '',
];
if (!in_array($filters['env'], ['', 'test', 'prod'], true)) {
    $filters['env'] = '';
}
if (!in_array($filters['ok'], ['', '0', '1'], true)) {
    $filters['ok'] = '';
}

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$limit = 50;
$offset = ($page - 1) * $limit;

$total = $repo->count($filters);
$rows = $repo->search($filters, $limit, $offset);
$pages = $total > 0 ? (int)ceil($total / $limit) : 1;

require_once __DIR__ . '/../../../includes/header.php';
?>

<main class="content-wide py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Administracja techniczna</p>
            <h1 class="h4 mb-1">GUS snapshots</h1>
            <p class="text-muted mb-0">Surowe zapisy wywołań integracji GUS</p>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <div class="text-muted small">Rekordy: <?= (int)$total ?></div>
            <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/admin/index.php">Panel narzędzi</a>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-body">
            <form class="row g-3" method="get">
                <div class="col-md-2">
                    <label class="form-label">Środowisko</label>
                    <select name="env" class="form-select">
                        <option value="" <?= $filters['env'] === '' ? 'selected' : '' ?>>Wszystkie</option>
                        <option value="test" <?= $filters['env'] === 'test' ? 'selected' : '' ?>>test</option>
                        <option value="prod" <?= $filters['env'] === 'prod' ? 'selected' : '' ?>>prod</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="ok" class="form-select">
                        <option value="" <?= $filters['ok'] === '' ? 'selected' : '' ?>>Wszystkie</option>
                        <option value="1" <?= $filters['ok'] === '1' ? 'selected' : '' ?>>OK</option>
                        <option value="0" <?= $filters['ok'] === '0' ? 'selected' : '' ?>>Błąd</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Wartość (NIP/REGON/KRS)</label>
                    <input type="text" name="request_value" class="form-control" value="<?= htmlspecialchars($filters['request_value']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data od</label>
                    <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($filters['date_from']) ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Data do</label>
                    <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($filters['date_to']) ?>">
                </div>
                <div class="col-md-1 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">Filtruj</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="table-responsive">
            <table class="table table-striped table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Data</th>
                        <th>Env</th>
                        <th>Typ</th>
                        <th>Wartość</th>
                        <th>Raport</th>
                        <th>HTTP</th>
                        <th>Status</th>
                        <th>Błąd</th>
                        <th>Trace</th>
                        <?php if ($isAdmin): ?>
                            <th>RAW</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php if (!$rows): ?>
                    <tr>
                        <td colspan="<?= $isAdmin ? 11 : 10 ?>" class="text-center text-muted py-4">Brak danych do wyświetlenia.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td><?= (int)$row['id'] ?></td>
                            <td><?= htmlspecialchars($row['created_at'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['env'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['request_type'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['request_value'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['report_type'] ?? '') ?></td>
                            <td><?= htmlspecialchars((string)($row['http_code'] ?? '')) ?></td>
                            <td>
                                <?php if (!empty($row['ok'])): ?>
                                    <span class="badge bg-success">OK</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Błąd</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($row['error_message'] ?? '') ?></td>
                            <td><?= htmlspecialchars($row['correlation_id'] ?? '') ?></td>
                            <?php if ($isAdmin): ?>
                                <td>
                                    <details>
                                        <summary>RAW</summary>
                                        <div class="small text-muted mb-1">request</div>
                                        <pre style="max-height:240px;overflow:auto;white-space:pre-wrap;"><?= htmlspecialchars($row['raw_request'] ?? '') ?></pre>
                                        <div class="small text-muted mb-1">response</div>
                                        <pre style="max-height:240px;overflow:auto;white-space:pre-wrap;"><?= htmlspecialchars($row['raw_response'] ?? '') ?></pre>
                                    </details>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($pages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                    <?php
                    $query = $_GET;
                    $query['page'] = $p;
                    $link = '?' . http_build_query($query);
                    ?>
                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                        <a class="page-link" href="<?= htmlspecialchars($link) ?>"><?= $p ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</main>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
