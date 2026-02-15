<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/../config/config.php';

$pageTitle = 'Prowizje';
requireRole(['Manager', 'Administrator']);
$currentUser = fetchCurrentUser($pdo) ?? [];

try {
    ensureUserColumns($pdo);
    ensureKampanieOwnershipColumns($pdo);
    ensureKampanieSalesValueColumn($pdo);
    ensureCommissionSettlementsTable($pdo);
    ensureSystemLogsTable($pdo);
} catch (Throwable $e) {
    error_log('prowizje: ensure schema failed: ' . $e->getMessage());
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

$monthStart = DateTime::createFromFormat('Y-n-j', $selectedYear . '-' . $selectedMonth . '-1') ?: new DateTime('first day of this month');
$monthEnd = (clone $monthStart)->modify('last day of this month');
$rangeFrom = $monthStart->format('Y-m-d');
$rangeTo = $monthEnd->format('Y-m-d');
$rangeFromDateTime = $rangeFrom . ' 00:00:00';
$rangeToDateTime = $rangeTo . ' 23:59:59';

$kampaniaCols = tableExists($pdo, 'kampanie') ? getTableColumns($pdo, 'kampanie') : [];
$dateColumn = null;
$dateFrom = $rangeFrom;
$dateTo = $rangeTo;
if (hasColumn($kampaniaCols, 'data_start')) {
    $dateColumn = 'data_start';
} elseif (hasColumn($kampaniaCols, 'created_at')) {
    $dateColumn = 'created_at';
    $dateFrom = $rangeFromDateTime;
    $dateTo = $rangeToDateTime;
}

// MVP definicja podstawy prowizji: kampanie o statusie Zamówiona/W realizacji/Zakończona,
// miesiąc liczony po data_start (fallback: created_at), wartość z wartosc_netto.
$statusCondition = "LOWER(REPLACE(k.status, '_', ' ')) IN ('zamowiona','zamówiona','w realizacji','zakończona','zakonczona')";

$users = [];
try {
    $stmt = $pdo->query('SELECT id, login, imie, nazwisko, rola, aktywny, commission_enabled, commission_rate_percent FROM uzytkownicy ORDER BY login');
    $users = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
} catch (Throwable $e) {
    error_log('prowizje: cannot load users: ' . $e->getMessage());
}

$commissionUsers = [];
foreach ($users as $user) {
    if ((int)($user['aktywny'] ?? 1) !== 1) {
        continue;
    }
    if ((int)($user['commission_enabled'] ?? 0) !== 1) {
        continue;
    }
    $commissionUsers[(int)$user['id']] = $user;
}

$aggregates = [];
if ($dateColumn && tableExists($pdo, 'kampanie') && hasColumn($kampaniaCols, 'owner_user_id')) {
    $sql = "SELECT k.owner_user_id AS owner_id,
                   COUNT(*) AS campaign_count,
                   SUM(CASE WHEN k.wartosc_netto IS NULL OR k.wartosc_netto <= 0 THEN 0 ELSE k.wartosc_netto END) AS base_netto,
                   SUM(CASE WHEN k.wartosc_netto IS NULL OR k.wartosc_netto <= 0 THEN 1 ELSE 0 END) AS missing_count
            FROM kampanie k
            WHERE k.owner_user_id IS NOT NULL
              AND $statusCondition
              AND k.$dateColumn BETWEEN :from AND :to
            GROUP BY k.owner_user_id";
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $aggregates[(int)$row['owner_id']] = [
                'campaign_count' => (int)$row['campaign_count'],
                'base_netto' => (float)$row['base_netto'],
                'missing_count' => (int)$row['missing_count'],
            ];
        }
    } catch (Throwable $e) {
        error_log('prowizje: aggregate failed: ' . $e->getMessage());
    }
}

$settlements = [];
try {
    $stmt = $pdo->prepare('SELECT * FROM prowizje_rozliczenia WHERE year = :year AND month = :month');
    $stmt->execute([':year' => $selectedYear, ':month' => $selectedMonth]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settlements[(int)$row['user_id']] = $row;
    }
} catch (Throwable $e) {
    error_log('prowizje: cannot load settlements: ' . $e->getMessage());
}

$alerts = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetUserId = (int)($_POST['user_id'] ?? 0);

    if (!isset($commissionUsers[$targetUserId])) {
        $alerts[] = ['type' => 'danger', 'msg' => 'Nie znaleziono użytkownika do rozliczenia.'];
    } else {
        $currentRate = (float)($commissionUsers[$targetUserId]['commission_rate_percent'] ?? 0.0);
        $currentAgg = $aggregates[$targetUserId] ?? ['campaign_count' => 0, 'base_netto' => 0.0, 'missing_count' => 0];
        $currentBase = (float)$currentAgg['base_netto'];
        $currentCommission = round($currentBase * $currentRate / 100, 2);
        $existing = $settlements[$targetUserId] ?? null;

        if ($action === 'calculate') {
            if ($existing && ($existing['status'] ?? '') === 'Wypłacone') {
                $alerts[] = ['type' => 'warning', 'msg' => 'Rozliczenie jest wypłacone i nie może być przeliczone.'];
            } else {
                try {
                    $stmt = $pdo->prepare('INSERT INTO prowizje_rozliczenia (year, month, user_id, rate_percent, base_netto, commission_netto, status, calculated_at)
                        VALUES (:year, :month, :user_id, :rate_percent, :base_netto, :commission_netto, :status, NOW())
                        ON DUPLICATE KEY UPDATE
                            rate_percent = VALUES(rate_percent),
                            base_netto = VALUES(base_netto),
                            commission_netto = VALUES(commission_netto),
                            status = VALUES(status),
                            calculated_at = VALUES(calculated_at),
                            paid_at = NULL');
                    $stmt->execute([
                        ':year' => $selectedYear,
                        ':month' => $selectedMonth,
                        ':user_id' => $targetUserId,
                        ':rate_percent' => $currentRate,
                        ':base_netto' => $currentBase,
                        ':commission_netto' => $currentCommission,
                        ':status' => 'Należne',
                    ]);

                    $log = $pdo->prepare('INSERT INTO system_logs (user_id, action, message) VALUES (:user_id, :action, :message)');
                    $log->execute([
                        ':user_id' => (int)($currentUser['id'] ?? 0),
                        ':action' => 'commission_recalculate',
                        ':message' => sprintf('Przeliczono prowizje: user_id=%d, %04d-%02d, base=%.2f, rate=%.2f', $targetUserId, $selectedYear, $selectedMonth, $currentBase, $currentRate),
                    ]);

                    $alerts[] = ['type' => 'success', 'msg' => 'Przeliczono prowizję.'];
                } catch (Throwable $e) {
                    $alerts[] = ['type' => 'danger', 'msg' => 'Nie udało się przeliczyć prowizji.'];
                    error_log('prowizje: calculate failed: ' . $e->getMessage());
                }
            }
        } elseif ($action === 'mark_paid') {
            if (!$existing) {
                $alerts[] = ['type' => 'warning', 'msg' => 'Najpierw przelicz prowizję.'];
            } elseif (($existing['status'] ?? '') === 'Wypłacone') {
                $alerts[] = ['type' => 'info', 'msg' => 'Prowizja została już oznaczona jako wypłacona.'];
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE prowizje_rozliczenia
                        SET status = 'Wypłacone', paid_at = NOW()
                        WHERE year = :year AND month = :month AND user_id = :user_id");
                    $stmt->execute([
                        ':year' => $selectedYear,
                        ':month' => $selectedMonth,
                        ':user_id' => $targetUserId,
                    ]);

                    $log = $pdo->prepare('INSERT INTO system_logs (user_id, action, message) VALUES (:user_id, :action, :message)');
                    $log->execute([
                        ':user_id' => (int)($currentUser['id'] ?? 0),
                        ':action' => 'commission_paid',
                        ':message' => sprintf('Oznaczono wypłatę prowizji: user_id=%d, %04d-%02d', $targetUserId, $selectedYear, $selectedMonth),
                    ]);

                    $alerts[] = ['type' => 'success', 'msg' => 'Oznaczono jako wypłacone.'];
                } catch (Throwable $e) {
                    $alerts[] = ['type' => 'danger', 'msg' => 'Nie udało się oznaczyć wypłaty.'];
                    error_log('prowizje: mark paid failed: ' . $e->getMessage());
                }
            }
        }
    }

    if ($alerts) {
        try {
            $stmt = $pdo->prepare('SELECT * FROM prowizje_rozliczenia WHERE year = :year AND month = :month');
            $stmt->execute([':year' => $selectedYear, ':month' => $selectedMonth]);
            $settlements = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settlements[(int)$row['user_id']] = $row;
            }
        } catch (Throwable $e) {
            error_log('prowizje: reload settlements failed: ' . $e->getMessage());
        }
    }
}

$rows = [];
foreach ($commissionUsers as $userId => $user) {
    $agg = $aggregates[$userId] ?? ['campaign_count' => 0, 'base_netto' => 0.0, 'missing_count' => 0];
    $settlement = $settlements[$userId] ?? null;
    $rate = $settlement ? (float)$settlement['rate_percent'] : (float)($user['commission_rate_percent'] ?? 0.0);
    $base = $settlement ? (float)$settlement['base_netto'] : (float)$agg['base_netto'];
    $commission = $settlement ? (float)$settlement['commission_netto'] : round($base * $rate / 100, 2);
    $status = $settlement['status'] ?? 'Należne';
    $rows[] = [
        'user_id' => $userId,
        'user' => $user,
        'rate' => $rate,
        'base' => $base,
        'commission' => $commission,
        'status' => $status,
        'campaign_count' => (int)$agg['campaign_count'],
        'missing_count' => (int)$agg['missing_count'],
        'settlement' => $settlement,
    ];
}

usort($rows, function (array $a, array $b): int {
    return strcmp($a['user']['login'] ?? '', $b['user']['login'] ?? '');
});

$export = $_GET['export'] ?? '';
if ($export === 'summary') {
    $filename = sprintf('prowizje_%04d_%02d.csv', $selectedYear, $selectedMonth);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Handlowiec', 'Stawka %', 'Podstawa netto', 'Prowizja netto', 'Status', 'Kampanie', 'Brak wartosc_netto'], ';');
    foreach ($rows as $row) {
        fputcsv($out, [
            formatOwnerLabel($row['user']),
            number_format($row['rate'], 2, '.', ''),
            number_format($row['base'], 2, '.', ''),
            number_format($row['commission'], 2, '.', ''),
            $row['status'],
            $row['campaign_count'],
            $row['missing_count'],
        ], ';');
    }
    fclose($out);
    exit;
}

if ($export === 'details') {
    $filename = sprintf('prowizje_szczegoly_%04d_%02d.csv', $selectedYear, $selectedMonth);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Handlowiec', 'Kampania ID', 'Klient', 'Data', 'Status', 'Wartosc netto', 'Brak wartosci'], ';');

    if ($dateColumn && tableExists($pdo, 'kampanie') && hasColumn($kampaniaCols, 'owner_user_id')) {
        $sql = "SELECT k.id, k.klient_nazwa, k.status, k.$dateColumn AS data_kampanii, k.owner_user_id, k.wartosc_netto
                FROM kampanie k
                WHERE k.owner_user_id IS NOT NULL
                  AND $statusCondition
                  AND k.$dateColumn BETWEEN :from AND :to
                ORDER BY k.owner_user_id, k.$dateColumn";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':from' => $dateFrom, ':to' => $dateTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $userId = (int)$row['owner_user_id'];
            if (!isset($commissionUsers[$userId])) {
                continue;
            }
            $missing = ($row['wartosc_netto'] === null || (float)$row['wartosc_netto'] <= 0) ? 'TAK' : '';
            fputcsv($out, [
                formatOwnerLabel($commissionUsers[$userId]),
                (int)$row['id'],
                $row['klient_nazwa'] ?? '',
                $row['data_kampanii'] ?? '',
                $row['status'] ?? '',
                number_format((float)($row['wartosc_netto'] ?? 0), 2, '.', ''),
                $missing,
            ], ';');
        }
    }

    fclose($out);
    exit;
}

$drillOwnerId = (int)($_GET['owner_id'] ?? 0);
$includedCampaigns = [];
$missingCampaigns = [];
if ($drillOwnerId > 0 && isset($commissionUsers[$drillOwnerId]) && $dateColumn && tableExists($pdo, 'kampanie')) {
    try {
        $sql = "SELECT k.id, k.klient_nazwa, k.status, k.$dateColumn AS data_kampanii, k.wartosc_netto
                FROM kampanie k
                WHERE k.owner_user_id = :owner_id
                  AND $statusCondition
                  AND k.$dateColumn BETWEEN :from AND :to
                ORDER BY k.$dateColumn";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':owner_id' => $drillOwnerId, ':from' => $dateFrom, ':to' => $dateTo]);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $missing = ($row['wartosc_netto'] === null || (float)$row['wartosc_netto'] <= 0);
            if ($missing) {
                $missingCampaigns[] = $row;
            } else {
                $includedCampaigns[] = $row;
            }
        }
    } catch (Throwable $e) {
        error_log('prowizje: drill campaigns failed: ' . $e->getMessage());
    }
}

require __DIR__ . '/includes/header.php';
?>

<main class="container py-3" role="main" aria-labelledby="prowizje-heading">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
            <h1 id="prowizje-heading" class="h4 mb-0">Prowizje handlowców</h1>
            <p class="text-muted small mb-0">
                Podstawa prowizji (MVP): wartosc_netto kampanii o statusie Zamówiona/W realizacji/Zakończona,
                miesiąc liczony po data_start (fallback: created_at). Kampanie bez wartości netto mają prowizję 0.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="prowizje.php?year=<?= (int)$selectedYear ?>&month=<?= (int)$selectedMonth ?>&export=summary" class="btn btn-outline-primary btn-sm">Eksport CSV (podsumowanie)</a>
            <a href="prowizje.php?year=<?= (int)$selectedYear ?>&month=<?= (int)$selectedMonth ?>&export=details" class="btn btn-outline-secondary btn-sm">Eksport CSV (szczegóły)</a>
        </div>
    </div>

    <?php foreach ($alerts as $alert): ?>
        <div class="alert alert-<?= htmlspecialchars($alert['type']) ?>"><?= htmlspecialchars($alert['msg']) ?></div>
    <?php endforeach; ?>

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
            <div class="table-responsive">
                <table class="table table-sm table-striped">
                    <thead>
                        <tr>
                            <th>Handlowiec</th>
                            <th>Stawka %</th>
                            <th>Podstawa netto</th>
                            <th>Prowizja netto</th>
                            <th>Status</th>
                            <th>Kampanie</th>
                            <th>Brak wartosc_netto</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="8" class="text-muted">Brak handlowców z prowizją aktywną</td></tr>
                        <?php else: foreach ($rows as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars(formatOwnerLabel($row['user'])) ?></td>
                                <td><?= number_format($row['rate'], 2, '.', '') ?>%</td>
                                <td><?= number_format($row['base'], 2, '.', ' ') ?></td>
                                <td><?= number_format($row['commission'], 2, '.', ' ') ?></td>
                                <td><?= htmlspecialchars($row['status']) ?></td>
                                <td><?= (int)$row['campaign_count'] ?></td>
                                <td><?= (int)$row['missing_count'] ?></td>
                                <td>
                                    <div class="d-flex gap-2">
                                        <form method="post">
                                            <input type="hidden" name="action" value="calculate">
                                            <input type="hidden" name="user_id" value="<?= (int)$row['user_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" <?= $row['status'] === 'Wypłacone' ? 'disabled' : '' ?>>Przelicz</button>
                                        </form>
                                        <form method="post">
                                            <input type="hidden" name="action" value="mark_paid">
                                            <input type="hidden" name="user_id" value="<?= (int)$row['user_id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-success" <?= $row['status'] === 'Wypłacone' ? 'disabled' : '' ?>>Oznacz jako wypłacone</button>
                                        </form>
                                        <a class="btn btn-sm btn-outline-secondary" href="prowizje.php?year=<?= (int)$selectedYear ?>&month=<?= (int)$selectedMonth ?>&owner_id=<?= (int)$row['user_id'] ?>#szczegoly">Szczegóły</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <?php if ($drillOwnerId > 0 && isset($commissionUsers[$drillOwnerId])): ?>
        <section id="szczegoly" class="card mt-3">
            <div class="card-header">
                <h2 class="h6 mb-0">Szczegóły kampanii: <?= htmlspecialchars(formatOwnerLabel($commissionUsers[$drillOwnerId])) ?></h2>
            </div>
            <div class="card-body">
                <h3 class="h6">Kampanie zaliczone do prowizji</h3>
                <div class="table-responsive mb-3">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Kampania</th>
                                <th>Data</th>
                                <th>Status</th>
                                <th>Wartosc netto</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($includedCampaigns)): ?>
                                <tr><td colspan="4" class="text-muted">Brak kampanii z wartością netto</td></tr>
                            <?php else: foreach ($includedCampaigns as $row): ?>
                                <tr>
                                    <td><a href="kampania_podglad.php?id=<?= (int)$row['id'] ?>"><?= htmlspecialchars($row['klient_nazwa'] ?? '') ?></a></td>
                                    <td><?= htmlspecialchars((string)($row['data_kampanii'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['status'] ?? '')) ?></td>
                                    <td><?= number_format((float)($row['wartosc_netto'] ?? 0), 2, '.', ' ') ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>

                <h3 class="h6">Kampanie pominięte (brak wartosc_netto)</h3>
                <p class="text-muted small">Uzupełnij wartosc_netto, aby kampania weszła do prowizji.</p>
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
                        <thead>
                            <tr>
                                <th>Kampania</th>
                                <th>Data</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($missingCampaigns)): ?>
                                <tr><td colspan="3" class="text-muted">Brak kampanii bez wartości netto</td></tr>
                            <?php else: foreach ($missingCampaigns as $row): ?>
                                <tr>
                                    <td><a href="kampania_podglad.php?id=<?= (int)$row['id'] ?>"><?= htmlspecialchars($row['klient_nazwa'] ?? '') ?></a></td>
                                    <td><?= htmlspecialchars((string)($row['data_kampanii'] ?? '')) ?></td>
                                    <td><?= htmlspecialchars((string)($row['status'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
