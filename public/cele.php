<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/../config/config.php';

$pageTitle = 'Oferta i rozliczenia - Cele sprzedażowe';
requireRole(['Manager', 'Administrator']);
$currentUser = fetchCurrentUser($pdo) ?? [];

try {
    ensureUserColumns($pdo);
    ensureSalesTargetsTable($pdo);
    ensureSystemLogsTable($pdo);
} catch (Throwable $e) {
    error_log('cele: ensure schema failed: ' . $e->getMessage());
}

function parseDecimal(?string $value): float {
    $value = trim((string)$value);
    if ($value === '') {
        return 0.0;
    }
    $normalized = str_replace(',', '.', $value);
    return (float)$normalized;
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
    error_log('cele: cannot load users: ' . $e->getMessage());
}

$targets = [];
try {
    $stmt = $pdo->prepare('SELECT user_id, target_netto FROM cele_sprzedazowe WHERE year = :year AND month = :month');
    $stmt->execute([':year' => $selectedYear, ':month' => $selectedMonth]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $targets[(int)$row['user_id']] = (float)$row['target_netto'];
    }
} catch (Throwable $e) {
    error_log('cele: cannot load targets: ' . $e->getMessage());
}

$flash = null;
$csrfToken = getCsrfToken();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $flash = 'Niepoprawny token CSRF.';
    } else {
        $selectedYear = (int)($_POST['year'] ?? $selectedYear);
        $selectedMonth = (int)($_POST['month'] ?? $selectedMonth);
        if ($selectedMonth < 1 || $selectedMonth > 12) {
            $selectedMonth = (int)date('n');
        }

        $applyAll = isset($_POST['set_all']);
        $allValue = parseDecimal($_POST['set_all_value'] ?? '');
        $targetsInput = $_POST['targets'] ?? [];

        try {
            $pdo->beginTransaction();
            $upsert = $pdo->prepare('INSERT INTO cele_sprzedazowe (year, month, user_id, target_netto, created_by_user_id) VALUES (:year, :month, :user_id, :target_netto, :created_by)
                ON DUPLICATE KEY UPDATE target_netto = VALUES(target_netto), created_by_user_id = VALUES(created_by_user_id)');
            $logStmt = $pdo->prepare('INSERT INTO system_logs (user_id, action, message) VALUES (:user_id, :action, :message)');

            foreach ($handlowcy as $userId => $user) {
                $newValue = $applyAll ? $allValue : parseDecimal($targetsInput[$userId] ?? '');
                $currentValue = $targets[$userId] ?? null;
                $upsert->execute([
                    ':year' => $selectedYear,
                    ':month' => $selectedMonth,
                    ':user_id' => $userId,
                    ':target_netto' => $newValue,
                    ':created_by' => (int)($currentUser['id'] ?? 0),
                ]);

                if ($currentValue === null || abs($currentValue - $newValue) > 0.0001) {
                    $logStmt->execute([
                        ':user_id' => (int)($currentUser['id'] ?? 0),
                        ':action' => 'sales_target_update',
                        ':message' => sprintf('Cel sprzedażowy: user_id=%d, %04d-%02d, kwota=%.2f', $userId, $selectedYear, $selectedMonth, $newValue),
                    ]);
                }
            }

            $pdo->commit();
            $flash = 'Cele zapisane.';

            $targets = [];
            $stmt = $pdo->prepare('SELECT user_id, target_netto FROM cele_sprzedazowe WHERE year = :year AND month = :month');
            $stmt->execute([':year' => $selectedYear, ':month' => $selectedMonth]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $targets[(int)$row['user_id']] = (float)$row['target_netto'];
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $flash = 'Nie udało się zapisać celów.';
            error_log('cele: save failed: ' . $e->getMessage());
        }
    }
}

require __DIR__ . '/includes/header.php';
?>

<main class="container py-3" role="main" aria-labelledby="cele-heading">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <p class="text-uppercase text-muted fw-semibold small mb-1">Oferta i rozliczenia</p>
            <h1 id="cele-heading" class="h3 mb-2">Cele sprzedażowe</h1>
            <p class="text-muted small mb-0">
                Definicja sprzedaży (MVP): suma wartości kampanii o statusie Zamówiona/W realizacji/Zakończona,
                liczona w miesiącu startu kampanii (data_start; fallback: created_at) i wartości netto kampanii.
            </p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-sm <?= is_active('cenniki.php') ? 'btn-primary' : 'btn-outline-secondary' ?>" href="cenniki.php">Cenniki oferty</a>
            <a class="btn btn-sm <?= is_active('cele.php') ? 'btn-primary' : 'btn-outline-secondary' ?>" href="cele.php">Cele sprzedażowe</a>
            <a class="btn btn-sm <?= is_active('prowizje.php') ? 'btn-primary' : 'btn-outline-secondary' ?>" href="prowizje.php">Prowizje handlowców</a>
            <a href="raport_cele.php" class="btn btn-outline-secondary btn-sm">Raport wykonania</a>
        </div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-info"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>

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
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="year" value="<?= (int)$selectedYear ?>">
                <input type="hidden" name="month" value="<?= (int)$selectedMonth ?>">

                <div class="row g-2 align-items-end mb-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label" for="set_all_value">Ustaw wszystkim (netto)</label>
                        <input type="text" class="form-control" name="set_all_value" id="set_all_value" placeholder="np. 25000,00">
                    </div>
                    <div class="col-12 col-md-3">
                        <button type="submit" name="set_all" class="btn btn-outline-primary w-100">Ustaw wszystkim</button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Handlowiec</th>
                                <th>Cel netto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($handlowcy)): ?>
                                <tr><td colspan="2" class="text-muted">Brak handlowców</td></tr>
                            <?php else: foreach ($handlowcy as $userId => $user): ?>
                                <tr>
                                    <td><?= htmlspecialchars(formatOwnerLabel($user)) ?></td>
                                    <td style="max-width: 180px;">
                                        <input type="text" class="form-control form-control-sm" name="targets[<?= (int)$userId ?>]" value="<?= number_format((float)($targets[$userId] ?? 0), 2, '.', '') ?>">
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" name="save_targets" class="btn btn-success">Zapisz cele</button>
            </form>
        </div>
    </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
