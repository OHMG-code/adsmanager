<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/lead_pipeline_helpers.php';
require_once __DIR__ . '/includes/emisje_helpers.php';
require_once __DIR__ . '/includes/ui_helpers.php';
require_once __DIR__ . '/../config/config.php';

$pageTitle = 'Dashboard Managera';

requireRole(['Manager', 'Administrator']);
$currentUser = fetchCurrentUser($pdo) ?? [];

try {
    ensureUserColumns($pdo);
    ensureLeadColumns($pdo);
    ensureLeadActivityTable($pdo);
    ensureClientLeadColumns($pdo);
    ensureKampanieOwnershipColumns($pdo);
    ensureSpotColumns($pdo);
    ensureEmisjeSpotowTable($pdo);
    ensureSpotAudioFilesTable($pdo);
    ensureDocumentsTables($pdo);
} catch (Throwable $e) {
    error_log('dashboard_manager: ensure schema failed: ' . $e->getMessage());
}

function parseDateInput(string $value, DateTime $fallback): DateTime {
    $value = trim($value);
    if ($value !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        if ($dt instanceof DateTime) {
            return $dt;
        }
    }
    return $fallback;
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

function normalizeCampaignStatusLabel(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    $lower = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $lower = str_replace(['_', '-'], ' ', $lower);
    $lower = preg_replace('/\s+/', ' ', $lower) ?? $lower;
    if (in_array($lower, ['zamowiona', 'zamówiona'], true)) {
        return 'Zamówiona';
    }
    if (in_array($lower, ['w realizacji', 'w_realizacji'], true)) {
        return 'W realizacji';
    }
    if (in_array($lower, ['zakonczona', 'zakończona'], true)) {
        return 'Zakończona';
    }
    return $value;
}

$defaultFrom = new DateTime('first day of this month');
$defaultTo = new DateTime('today');

$dateFrom = parseDateInput((string)($_GET['date_from'] ?? ''), $defaultFrom);
$dateTo = parseDateInput((string)($_GET['date_to'] ?? ''), $defaultTo);
if ($dateFrom > $dateTo) {
    $tmp = $dateFrom;
    $dateFrom = $dateTo;
    $dateTo = $tmp;
}

$selectedOwnerId = (int)($_GET['owner_user_id'] ?? 0);

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
    error_log('dashboard_manager: cannot load users: ' . $e->getMessage());
}

if ($selectedOwnerId > 0 && !isset($handlowcy[$selectedOwnerId])) {
    $selectedOwnerId = 0;
}

$cacheKey = 'dashboard_manager:' . md5($dateFrom->format('Y-m-d') . '|' . $dateTo->format('Y-m-d') . '|' . $selectedOwnerId);
$cacheTtl = 300;
$cacheHit = false;
$dashboard = [];

if (!empty($_SESSION['dashboard_cache'][$cacheKey])) {
    $cached = $_SESSION['dashboard_cache'][$cacheKey];
    if (isset($cached['stored_at'], $cached['payload']) && (time() - (int)$cached['stored_at']) <= $cacheTtl) {
        $dashboard = $cached['payload'];
        $cacheHit = true;
    }
}

if (!$cacheHit) {
    $dateFromSql = $dateFrom->format('Y-m-d');
    $dateToSql = $dateTo->format('Y-m-d');
    $dateFromDateTime = $dateFromSql . ' 00:00:00';
    $dateToDateTime = $dateToSql . ' 23:59:59';
    $today = (new DateTime('today'))->format('Y-m-d');

    $leadCols = getTableColumns($pdo, 'leady');
    $leadOwnerClause = '';
    $leadOwnerParams = [];
    if ($selectedOwnerId > 0 && hasColumn($leadCols, 'owner_user_id')) {
        $leadOwnerClause = ' AND owner_user_id = :owner_id';
        $leadOwnerParams[':owner_id'] = $selectedOwnerId;
    }

    $newLeads = 0;
    $offerLeads = 0;
    $overdueLeads = 0;
    $wonLeads = 0;
    $lostLeads = 0;
    $conversions = 0;

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM leady WHERE created_at BETWEEN :from AND :to' . $leadOwnerClause);
        $stmt->execute(array_merge([':from' => $dateFromDateTime, ':to' => $dateToDateTime], $leadOwnerParams));
        $newLeads = (int)($stmt->fetchColumn() ?? 0);

        $statusSql = "LOWER(status) IN ('oferta wysłana','oferta wyslana','oferta_wyslana')";
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM leady WHERE created_at BETWEEN :from AND :to AND ' . $statusSql . $leadOwnerClause);
        $stmt->execute(array_merge([':from' => $dateFromDateTime, ':to' => $dateToDateTime], $leadOwnerParams));
        $offerLeads = (int)($stmt->fetchColumn() ?? 0);

        $nextActionColumn = null;
        $nextActionRangeFrom = $dateFromDateTime;
        $nextActionRangeTo = $dateToDateTime;
        if (hasColumn($leadCols, 'next_action_at')) {
            $nextActionColumn = 'next_action_at';
        } elseif (hasColumn($leadCols, 'next_action_date')) {
            $nextActionColumn = 'next_action_date';
            $nextActionRangeFrom = $dateFromSql;
            $nextActionRangeTo = $dateToSql;
        }
        if ($nextActionColumn) {
            $overdueSql = "{$nextActionColumn} IS NOT NULL AND {$nextActionColumn} < :today AND {$nextActionColumn} BETWEEN :from AND :to";
            $overdueSql .= " AND LOWER(status) NOT IN ('wygrana','przegrana','skonwertowany','odrzucony','zakonczony')";
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM leady WHERE ' . $overdueSql . $leadOwnerClause);
            $params = array_merge([
                ':today' => $today,
                ':from' => $nextActionRangeFrom,
                ':to' => $nextActionRangeTo,
            ], $leadOwnerParams);
            $stmt->execute($params);
            $overdueLeads = (int)($stmt->fetchColumn() ?? 0);
        }

        if (tableExists($pdo, 'leady_aktywnosci')) {
            $activitySql = "SELECT la.opis FROM leady_aktywnosci la JOIN leady l ON l.id = la.lead_id WHERE la.typ = 'status_change' AND la.created_at BETWEEN :from AND :to";
            if ($selectedOwnerId > 0 && hasColumn($leadCols, 'owner_user_id')) {
                $activitySql .= ' AND l.owner_user_id = :owner_id';
            }
            $stmt = $pdo->prepare($activitySql);
            $stmt->execute(array_merge([':from' => $dateFromDateTime, ':to' => $dateToDateTime], $leadOwnerParams));
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $desc = (string)($row['opis'] ?? '');
                if (preg_match('/(→|->)\s*Wygrana/i', $desc)) {
                    $wonLeads++;
                }
                if (preg_match('/(→|->)\s*Przegrana/i', $desc)) {
                    $lostLeads++;
                }
            }
        }

        if (($wonLeads + $lostLeads) === 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leady WHERE created_at BETWEEN :from AND :to AND LOWER(status) IN ('wygrana','skonwertowany')" . $leadOwnerClause);
            $stmt->execute(array_merge([':from' => $dateFromDateTime, ':to' => $dateToDateTime], $leadOwnerParams));
            $wonLeads = (int)($stmt->fetchColumn() ?? 0);

            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leady WHERE created_at BETWEEN :from AND :to AND LOWER(status) IN ('przegrana','odrzucony','zakonczony')" . $leadOwnerClause);
            $stmt->execute(array_merge([':from' => $dateFromDateTime, ':to' => $dateToDateTime], $leadOwnerParams));
            $lostLeads = (int)($stmt->fetchColumn() ?? 0);
        }

        if (hasColumn($leadCols, 'converted_at')) {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM leady WHERE converted_at BETWEEN :from AND :to' . $leadOwnerClause);
            $stmt->execute(array_merge([':from' => $dateFromDateTime, ':to' => $dateToDateTime], $leadOwnerParams));
            $conversions = (int)($stmt->fetchColumn() ?? 0);
        }
    } catch (Throwable $e) {
        error_log('dashboard_manager: lead queries failed: ' . $e->getMessage());
    }

    $conversionRate = $newLeads > 0 ? round(($conversions / $newLeads) * 100, 1) : 0.0;

    $kampanieCounts = [
        'Zamówiona' => 0,
        'W realizacji' => 0,
        'Zakończona' => 0,
    ];

    if (tableExists($pdo, 'kampanie')) {
        $kampCols = getTableColumns($pdo, 'kampanie');
        $kampDateColumn = hasColumn($kampCols, 'created_at') ? 'created_at' : (hasColumn($kampCols, 'data_start') ? 'data_start' : null);
        $kampOwnerClause = '';
        $kampOwnerParams = [];
        if ($selectedOwnerId > 0 && hasColumn($kampCols, 'owner_user_id')) {
            $kampOwnerClause = ' AND owner_user_id = :owner_id';
            $kampOwnerParams[':owner_id'] = $selectedOwnerId;
        }

        try {
            if ($kampDateColumn) {
                $stmt = $pdo->prepare('SELECT status, COUNT(*) AS total FROM kampanie WHERE ' . $kampDateColumn . ' BETWEEN :from AND :to' . $kampOwnerClause . ' GROUP BY status');
                $stmt->execute(array_merge([':from' => $dateFromDateTime, ':to' => $dateToDateTime], $kampOwnerParams));
            } else {
                $stmt = $pdo->prepare('SELECT status, COUNT(*) AS total FROM kampanie WHERE 1=1' . $kampOwnerClause . ' GROUP BY status');
                $stmt->execute($kampOwnerParams);
            }
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $label = normalizeCampaignStatusLabel((string)($row['status'] ?? ''));
                if (isset($kampanieCounts[$label])) {
                    $kampanieCounts[$label] += (int)($row['total'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            error_log('dashboard_manager: kampanie queries failed: ' . $e->getMessage());
        }
    }

    $spotCols = tableExists($pdo, 'spoty') ? getTableColumns($pdo, 'spoty') : [];
    $kampCols = tableExists($pdo, 'kampanie') ? getTableColumns($pdo, 'kampanie') : [];

    $spotWhere = [];
    $spotParams = [':from' => $dateFromSql, ':to' => $dateToSql];
    if (hasColumn($spotCols, 'data_start')) {
        $spotWhere[] = '(s.data_start IS NULL OR s.data_start <= :to)';
    }
    if (hasColumn($spotCols, 'data_koniec')) {
        $spotWhere[] = '(s.data_koniec IS NULL OR s.data_koniec >= :from)';
    }
    if (hasColumn($spotCols, 'status')) {
        $spotWhere[] = "COALESCE(s.status, 'Aktywny') <> 'Nieaktywny'";
    }
    if (hasColumn($spotCols, 'aktywny')) {
        $spotWhere[] = '(s.aktywny IS NULL OR s.aktywny = 1)';
    }
    if (!$spotWhere) {
        $spotWhere[] = '1=1';
    }

    $spotOwnerClause = '';
    if ($selectedOwnerId > 0) {
        $ownerParts = [];
        if (hasColumn($kampCols, 'owner_user_id')) {
            $ownerParts[] = 'k.owner_user_id = :owner_id';
        }
        if (hasColumn($spotCols, 'owner_user_id')) {
            $ownerParts[] = 's.owner_user_id = :owner_id';
        }
        if ($ownerParts) {
            $spotOwnerClause = ' AND (' . implode(' OR ', $ownerParts) . ')';
            $spotParams[':owner_id'] = $selectedOwnerId;
        }
    }

    $activeSpots = 0;
    try {
        $sql = 'SELECT COUNT(DISTINCT s.id) FROM spoty s ';
        if (tableExists($pdo, 'kampanie')) {
            $sql .= 'LEFT JOIN kampanie k ON k.id = s.kampania_id ';
        }
        $sql .= 'WHERE ' . implode(' AND ', $spotWhere) . $spotOwnerClause;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($spotParams);
        $activeSpots = (int)($stmt->fetchColumn() ?? 0);
    } catch (Throwable $e) {
        error_log('dashboard_manager: spot counts failed: ' . $e->getMessage());
    }

    $usageUser = $selectedOwnerId > 0 ? ['id' => $selectedOwnerId, 'rola' => 'Handlowiec'] : null;
    $usageBands = ['Prime' => 0.0, 'Standard' => 0.0, 'Night' => 0.0];
    $usageEstimate = false;
    $usageDays = 0;
    try {
        $rangeDays = (int)$dateFrom->diff($dateTo)->format('%a') + 1;
        $step = $rangeDays > 31 ? (int)ceil($rangeDays / 31) : 1;
        if ($step > 1) {
            $usageEstimate = true;
        }
        $sampleDates = [];
        for ($i = 0; $i < $rangeDays; $i += $step) {
            $dt = (clone $dateFrom)->modify('+' . $i . ' day');
            $sampleDates[$dt->format('Y-m-d')] = true;
        }
        $sampleDates[$dateTo->format('Y-m-d')] = true;

        foreach (array_keys($sampleDates) as $dateStr) {
            $usage = calculateBandUsageForDate($pdo, $dateStr, $usageUser);
            foreach ($usageBands as $band => $val) {
                $usageBands[$band] += (float)($usage['bands'][$band]['suma_minut'] ?? 0.0);
            }
            $usageDays++;
        }
        if ($usageDays > 0) {
            foreach ($usageBands as $band => $val) {
                $usageBands[$band] = round($val / $usageDays, 1);
            }
        }
    } catch (Throwable $e) {
        error_log('dashboard_manager: usage sampling failed: ' . $e->getMessage());
    }

    $audioMissing = 0;
    $audioPending = 0;
    if (tableExists($pdo, 'spot_audio_files')) {
        try {
            $sqlMissing = 'SELECT COUNT(DISTINCT s.id) FROM spoty s ';
            if (tableExists($pdo, 'kampanie')) {
                $sqlMissing .= 'LEFT JOIN kampanie k ON k.id = s.kampania_id ';
            }
            $sqlMissing .= 'WHERE ' . implode(' AND ', $spotWhere) . $spotOwnerClause;
            $sqlMissing .= " AND NOT EXISTS (SELECT 1 FROM spot_audio_files saf WHERE saf.spot_id = s.id AND saf.is_active = 1 AND saf.production_status = 'Zaakceptowany')";
            $stmt = $pdo->prepare($sqlMissing);
            $stmt->execute($spotParams);
            $audioMissing = (int)($stmt->fetchColumn() ?? 0);

            $sqlPending = 'SELECT COUNT(DISTINCT s.id) FROM spoty s ';
            if (tableExists($pdo, 'kampanie')) {
                $sqlPending .= 'LEFT JOIN kampanie k ON k.id = s.kampania_id ';
            }
            $sqlPending .= 'WHERE ' . implode(' AND ', $spotWhere) . $spotOwnerClause;
            $sqlPending .= " AND EXISTS (SELECT 1 FROM spot_audio_files saf WHERE saf.spot_id = s.id AND saf.is_active = 1 AND saf.production_status = 'Do akceptacji')";
            $stmt = $pdo->prepare($sqlPending);
            $stmt->execute($spotParams);
            $audioPending = (int)($stmt->fetchColumn() ?? 0);
        } catch (Throwable $e) {
            error_log('dashboard_manager: audio readiness failed: ' . $e->getMessage());
        }
    }

    $documentsCount = 0;
    try {
        $docSql = 'SELECT COUNT(*) FROM dokumenty d';
        $docParams = [':from' => $dateFromDateTime, ':to' => $dateToDateTime];
        if (tableExists($pdo, 'kampanie')) {
            $docSql .= ' LEFT JOIN kampanie k ON k.id = d.kampania_id';
        }
        $docSql .= ' WHERE d.created_at BETWEEN :from AND :to';
        if ($selectedOwnerId > 0) {
            if (tableExists($pdo, 'kampanie') && hasColumn($kampCols, 'owner_user_id')) {
                $docSql .= ' AND (k.owner_user_id = :owner_id OR d.created_by_user_id = :owner_id)';
                $docParams[':owner_id'] = $selectedOwnerId;
            } else {
                $docSql .= ' AND d.created_by_user_id = :owner_id';
                $docParams[':owner_id'] = $selectedOwnerId;
            }
        }
        $stmt = $pdo->prepare($docSql);
        $stmt->execute($docParams);
        $documentsCount = (int)($stmt->fetchColumn() ?? 0);
    } catch (Throwable $e) {
        error_log('dashboard_manager: documents count failed: ' . $e->getMessage());
    }

    $overdueTable = [];
    $audioRiskTable = [];
    $closeCampaignsTable = [];

    try {
        $nextActionColumn = null;
        $nextActionRangeFrom = $dateFromDateTime;
        $nextActionRangeTo = $dateToDateTime;
        if (hasColumn($leadCols, 'next_action_at')) {
            $nextActionColumn = 'next_action_at';
        } elseif (hasColumn($leadCols, 'next_action_date')) {
            $nextActionColumn = 'next_action_date';
            $nextActionRangeFrom = $dateFromSql;
            $nextActionRangeTo = $dateToSql;
        }
        if ($nextActionColumn) {
            $sql = "SELECT l.id, l.nazwa_firmy, l.owner_user_id, l.priority, l.status, l.{$nextActionColumn} AS next_action_at,
                        u.login, u.imie, u.nazwisko
                    FROM leady l
                    LEFT JOIN uzytkownicy u ON u.id = l.owner_user_id
                    WHERE l.{$nextActionColumn} IS NOT NULL
                      AND l.{$nextActionColumn} < :today
                      AND l.{$nextActionColumn} BETWEEN :from AND :to
                      AND LOWER(l.status) NOT IN ('wygrana','przegrana','skonwertowany','odrzucony','zakonczony')";
            $params = [
                ':today' => $today,
                ':from' => $nextActionRangeFrom,
                ':to' => $nextActionRangeTo,
            ];
            if ($selectedOwnerId > 0 && hasColumn($leadCols, 'owner_user_id')) {
                $sql .= ' AND l.owner_user_id = :owner_id';
                $params[':owner_id'] = $selectedOwnerId;
            }
            $sql .= ' ORDER BY l.' . $nextActionColumn . ' ASC LIMIT 10';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $overdueTable = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log('dashboard_manager: overdue table failed: ' . $e->getMessage());
    }

    if (tableExists($pdo, 'spot_audio_files')) {
        try {
            $sql = 'SELECT s.id, s.nazwa_spotu, s.kampania_id, k.klient_nazwa, k.owner_user_id,
                        u.login, u.imie, u.nazwisko,
                        saf.production_status
                    FROM spoty s
                    LEFT JOIN kampanie k ON k.id = s.kampania_id
                    LEFT JOIN uzytkownicy u ON u.id = k.owner_user_id
                    LEFT JOIN spot_audio_files saf ON saf.spot_id = s.id AND saf.is_active = 1
                    WHERE ' . implode(' AND ', $spotWhere) . $spotOwnerClause .
                    " AND (saf.id IS NULL OR saf.production_status <> 'Zaakceptowany')
                    ORDER BY s.data_start ASC
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($spotParams);
            $audioRiskTable = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('dashboard_manager: audio risk table failed: ' . $e->getMessage());
        }
    }

    if (tableExists($pdo, 'kampanie')) {
        try {
            $closeSql = "SELECT k.id, k.klient_nazwa, k.data_koniec, k.owner_user_id, u.login, u.imie, u.nazwisko
                         FROM kampanie k
                         LEFT JOIN uzytkownicy u ON u.id = k.owner_user_id
                         WHERE (LOWER(k.status) = 'w realizacji' OR LOWER(k.status) = 'w_realizacji')
                           AND (k.data_koniec < :today OR NOT EXISTS (
                                SELECT 1 FROM spoty s
                                WHERE s.kampania_id = k.id
                                  AND " . implode(' AND ', $spotWhere) .
                            '))';
            $params = [
                ':today' => $today,
                ':from' => $dateFromSql,
                ':to' => $dateToSql,
            ];
            if (hasColumn($kampCols, 'data_start')) {
                $closeSql .= ' AND k.data_start <= :to';
            }
            if (hasColumn($kampCols, 'data_koniec')) {
                $closeSql .= ' AND k.data_koniec >= :from';
            }
            if ($selectedOwnerId > 0 && hasColumn($kampCols, 'owner_user_id')) {
                $closeSql .= ' AND k.owner_user_id = :owner_id';
                $params[':owner_id'] = $selectedOwnerId;
            }
            $closeSql .= ' ORDER BY k.data_koniec ASC LIMIT 10';
            $stmt = $pdo->prepare($closeSql);
            $stmt->execute($params);
            $closeCampaignsTable = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('dashboard_manager: close campaigns table failed: ' . $e->getMessage());
        }
    }

    $dashboard = [
        'kpi' => [
            'new_leads' => $newLeads,
            'offer_leads' => $offerLeads,
            'overdue_leads' => $overdueLeads,
            'won_leads' => $wonLeads,
            'lost_leads' => $lostLeads,
            'conversions' => $conversions,
            'conversion_rate' => $conversionRate,
            'kampanie' => $kampanieCounts,
            'active_spots' => $activeSpots,
            'usage_bands' => $usageBands,
            'usage_estimate' => $usageEstimate,
            'audio_missing' => $audioMissing,
            'audio_pending' => $audioPending,
            'documents' => $documentsCount,
        ],
        'tables' => [
            'overdue' => $overdueTable,
            'audio_risk' => $audioRiskTable,
            'close_campaigns' => $closeCampaignsTable,
        ],
    ];

    $_SESSION['dashboard_cache'][$cacheKey] = [
        'stored_at' => time(),
        'payload' => $dashboard,
    ];
}

require __DIR__ . '/includes/header.php';
?>

<main class="container" role="main" aria-labelledby="dashboard-manager-heading">
    <div class="app-header">
        <div>
            <h1 id="dashboard-manager-heading" class="app-title">Dashboard Managera</h1>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Dashboard Managera</li>
                </ol>
            </nav>
        </div>
        <span class="badge badge-neutral">KPI sprzedaży i realizacji</span>
    </div>

    <section class="card mb-3">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label class="form-label" for="date_from">Data od</label>
                    <input type="date" class="form-control" id="date_from" name="date_from" value="<?= htmlspecialchars($dateFrom->format('Y-m-d')) ?>">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label" for="date_to">Data do</label>
                    <input type="date" class="form-control" id="date_to" name="date_to" value="<?= htmlspecialchars($dateTo->format('Y-m-d')) ?>">
                </div>
                <div class="col-12 col-md-4">
                    <label class="form-label" for="owner_user_id">Handlowiec</label>
                    <select class="form-select" id="owner_user_id" name="owner_user_id">
                        <option value="0">Wszyscy</option>
                        <?php foreach ($handlowcy as $id => $user): ?>
                            <option value="<?= (int)$id ?>" <?= $selectedOwnerId === (int)$id ? 'selected' : '' ?>>
                                <?= htmlspecialchars(formatOwnerLabel($user)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-primary w-100">Zastosuj</button>
                </div>
            </form>
        </div>
    </section>

    <section class="kpi-grid mb-3" aria-label="KPI">
        <div class="kpi-card">
            <div class="text-muted small">Nowe leady</div>
            <div class="kpi-value"><?= (int)($dashboard['kpi']['new_leads'] ?? 0) ?></div>
            <div class="text-muted small">Status: Oferta wysłana: <?= (int)($dashboard['kpi']['offer_leads'] ?? 0) ?></div>
        </div>
        <div class="kpi-card">
            <div class="text-muted small">Przeterminowane follow-upy</div>
            <div class="kpi-value"><?= (int)($dashboard['kpi']['overdue_leads'] ?? 0) ?></div>
            <div class="text-muted small">Wygrane: <?= (int)($dashboard['kpi']['won_leads'] ?? 0) ?> • Przegrane: <?= (int)($dashboard['kpi']['lost_leads'] ?? 0) ?></div>
        </div>
        <div class="kpi-card">
            <div class="text-muted small">Konwersje lead → klient</div>
            <div class="kpi-value"><?= (int)($dashboard['kpi']['conversions'] ?? 0) ?></div>
            <div class="text-muted small">Współczynnik: <?= number_format((float)($dashboard['kpi']['conversion_rate'] ?? 0), 1) ?>%</div>
        </div>
        <div class="kpi-card">
            <div class="text-muted small">Kampanie (status)</div>
            <div class="kpi-value"><?= (int)($dashboard['kpi']['kampanie']['Zamówiona'] ?? 0) ?></div>
            <div class="text-muted small">W realizacji: <?= (int)($dashboard['kpi']['kampanie']['W realizacji'] ?? 0) ?> • Zakończona: <?= (int)($dashboard['kpi']['kampanie']['Zakończona'] ?? 0) ?></div>
        </div>
        <div class="kpi-card">
            <div class="text-muted small">Aktywne spoty</div>
            <div class="kpi-value"><?= (int)($dashboard['kpi']['active_spots'] ?? 0) ?></div>
            <div class="text-muted small">Średnia zajętość pasm: Prime <?= number_format((float)($dashboard['kpi']['usage_bands']['Prime'] ?? 0), 1) ?> min</div>
        </div>
        <div class="kpi-card">
            <div class="text-muted small">Zajętość pasm (średnio/dzień)</div>
            <div class="kpi-value"><?= number_format((float)($dashboard['kpi']['usage_bands']['Standard'] ?? 0), 1) ?> min</div>
            <div class="text-muted small">Night: <?= number_format((float)($dashboard['kpi']['usage_bands']['Night'] ?? 0), 1) ?> min<?= !empty($dashboard['kpi']['usage_estimate']) ? ' • estymacja' : '' ?></div>
        </div>
        <div class="kpi-card">
            <div class="text-muted small">Audio readiness</div>
            <div class="kpi-value"><?= (int)($dashboard['kpi']['audio_missing'] ?? 0) ?></div>
            <div class="text-muted small">Do akceptacji: <?= (int)($dashboard['kpi']['audio_pending'] ?? 0) ?></div>
        </div>
        <div class="kpi-card">
            <div class="text-muted small">Dokumenty</div>
            <div class="kpi-value"><?= (int)($dashboard['kpi']['documents'] ?? 0) ?></div>
            <div class="text-muted small">Umowy/aneksy w zakresie dat</div>
        </div>
    </section>

    <section class="row g-3 mb-4">
        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 mb-0">Najbardziej przeterminowane follow-upy</h2></div>
                <div class="table-responsive">
                    <table class="table table-zebra table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Lead</th>
                                <th>Owner</th>
                                <th>Next action</th>
                                <th>Priorytet</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dashboard['tables']['overdue'])): ?>
                                <tr><td colspan="5" class="text-muted">Brak przeterminowanych follow-upów</td></tr>
                            <?php else: foreach ($dashboard['tables']['overdue'] as $row): ?>
                                <tr>
                                    <td><a href="lead.php?lead_edit_id=<?= (int)$row['id'] ?>#lead-form"><?= htmlspecialchars($row['nazwa_firmy'] ?? '') ?></a></td>
                                    <td><?= htmlspecialchars(formatOwnerLabel($row)) ?></td>
                                    <td><?= htmlspecialchars((string)($row['next_action_at'] ?? '')) ?></td>
                                    <td><?= renderBadge('priority', (string)($row['priority'] ?? '')) ?></td>
                                    <td><?= renderBadge('lead_status', normalizeLeadStatus((string)($row['status'] ?? ''))) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 mb-0">Ryzyka emisji (audio)</h2></div>
                <div class="table-responsive">
                    <table class="table table-zebra table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Spot</th>
                                <th>Kampania</th>
                                <th>Owner</th>
                                <th>Status audio</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dashboard['tables']['audio_risk'])): ?>
                                <tr><td colspan="4" class="text-muted">Brak ryzyk audio w wybranym zakresie</td></tr>
                            <?php else: foreach ($dashboard['tables']['audio_risk'] as $row): ?>
                                <tr>
                                    <td><a href="edytuj_spot.php?id=<?= (int)$row['id'] ?>"><?= htmlspecialchars($row['nazwa_spotu'] ?? '') ?></a></td>
                                    <td>
                                        <?php if (!empty($row['kampania_id'])): ?>
                                            <a href="kampania_podglad.php?id=<?= (int)$row['kampania_id'] ?>"><?= htmlspecialchars($row['klient_nazwa'] ?? '') ?></a>
                                        <?php else: ?>
                                            <?= htmlspecialchars($row['klient_nazwa'] ?? '—') ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= htmlspecialchars(formatOwnerLabel($row)) ?></td>
                                    <td><?= renderBadge('audio_status', (string)($row['production_status'] ? (string)$row['production_status'] : 'Brak audio')) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 mb-0">Kampanie do domknięcia</h2></div>
                <div class="table-responsive">
                    <table class="table table-zebra table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Kampania</th>
                                <th>Owner</th>
                                <th>Data końca</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($dashboard['tables']['close_campaigns'])): ?>
                                <tr><td colspan="3" class="text-muted">Brak kampanii do domknięcia</td></tr>
                            <?php else: foreach ($dashboard['tables']['close_campaigns'] as $row): ?>
                                <tr>
                                    <td><a href="kampania_podglad.php?id=<?= (int)$row['id'] ?>"><?= htmlspecialchars($row['klient_nazwa'] ?? '') ?></a></td>
                                    <td><?= htmlspecialchars(formatOwnerLabel($row)) ?></td>
                                    <td><?= htmlspecialchars((string)($row['data_koniec'] ?? '')) ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</main>

<?php require __DIR__ . '/includes/footer.php'; ?>
