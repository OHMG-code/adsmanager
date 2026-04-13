<?php
declare(strict_types=1);

$embeddedManagerDashboard = defined('EMBED_MANAGER_DASHBOARD') && EMBED_MANAGER_DASHBOARD === true;

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/lead_pipeline_helpers.php';
require_once __DIR__ . '/includes/emisje_helpers.php';
require_once __DIR__ . '/includes/transaction_metrics.php';
require_once __DIR__ . '/includes/ui_helpers.php';
require_once __DIR__ . '/includes/task_automation.php';
require_once __DIR__ . '/../config/config.php';

$pageTitle = 'Dashboard Managera';

if ($embeddedManagerDashboard) {
    $embeddedViewer = fetchCurrentUser($pdo) ?? [];
    $embeddedRole = normalizeRole($embeddedViewer);
    $isEmbeddedManagerView = in_array($embeddedRole, ['Manager', 'Administrator'], true) || isAdminOverride($embeddedViewer);
    if (!$isEmbeddedManagerView) {
        return;
    }
} else {
    requireRole(['Manager', 'Administrator']);
    $redirectTarget = 'dashboard.php';
    if (!empty($_SERVER['QUERY_STRING'])) {
        $redirectTarget .= '?' . $_SERVER['QUERY_STRING'];
    }
    header('Location: ' . $redirectTarget);
    exit;
}
$currentUser = fetchCurrentUser($pdo) ?? [];

try {
    ensureUserColumns($pdo);
    ensureLeadColumns($pdo);
    ensureLeadActivityTable($pdo);
    ensureSalesTargetsTable($pdo);
    ensureClientLeadColumns($pdo);
    ensureKampanieOwnershipColumns($pdo);
    ensureSpotColumns($pdo);
    ensureEmisjeSpotowTable($pdo);
    ensureSpotAudioFilesTable($pdo);
    ensureDocumentsTables($pdo);
    ensureTransactionsTable($pdo);
    ensureCrmTasksTable($pdo);
} catch (Throwable $e) {
    error_log('dashboard_manager: ensure schema failed: ' . $e->getMessage());
}

if (!function_exists('parseDateInput')) {
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
}

if (!function_exists('formatOwnerLabel')) {
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
}

if (!function_exists('statusSqlPlaceholders')) {
    function statusSqlPlaceholders(array $values, array &$params, string $prefix): string {
        $out = [];
        $idx = 0;
        foreach ($values as $value) {
            $key = ':' . $prefix . '_' . $idx++;
            $params[$key] = $value;
            $out[] = $key;
        }
        return $out ? implode(',', $out) : "''";
    }
}

if (!function_exists('normalizeCampaignStatusLabel')) {
    function normalizeCampaignStatusLabel(string $value): string {
        $normalized = normalizeCampaignStatus($value);
        if ($normalized === 'zamowiona') {
            return 'Zamówiona';
        }
        if ($normalized === 'zakonczona') {
            return 'Zakończona';
        }
        if ($normalized === 'propozycja') {
            return 'Propozycja';
        }
        return 'W realizacji';
    }
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

if (!isset($dashboard['transactions'])) {
    $dashboard['transactions'] = [
        'total_count' => 0,
        'total_netto' => 0.0,
        'by_owner' => [],
    ];
}
if (!isset($dashboard['sales_month'])) {
    $dashboard['sales_month'] = [
        'year' => (int)date('Y'),
        'month' => (int)date('n'),
        'date_from' => date('Y-m-01'),
        'date_to' => date('Y-m-t'),
        'plan_total' => 0.0,
        'netto_total' => 0.0,
        'delta' => 0.0,
        'percent' => 0.0,
        'transaction_count' => 0,
        'by_owner' => [],
    ];
}

if (!$cacheHit) {
    $dateFromSql = $dateFrom->format('Y-m-d');
    $dateToSql = $dateTo->format('Y-m-d');
    $dateFromDateTime = $dateFromSql . ' 00:00:00';
    $dateToDateTime = $dateToSql . ' 23:59:59';
    $today = (new DateTime('today'))->format('Y-m-d');

    if (!$embeddedManagerDashboard || !empty($currentUser['id'])) {
        try {
            $syncKey = 'task_alert_sync:manager:' . (int)($currentUser['id'] ?? 0);
            $lastSyncAt = isset($_SESSION[$syncKey]) ? (int)$_SESSION[$syncKey] : 0;
            if ((time() - $lastSyncAt) >= 300) {
                syncAlertTasks($pdo, [
                    'production_days' => 7,
                    'audio_days' => 5,
                    'actor_user_id' => !empty($currentUser['id']) ? (int)$currentUser['id'] : null,
                ]);
                $_SESSION[$syncKey] = time();
            }
        } catch (Throwable $e) {
            error_log('dashboard_manager: task alert sync failed: ' . $e->getMessage());
        }
    }

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
    $wonLeadStatuses = array_unique(array_merge(leadWonStatuses(), ['wygrana', 'media plan zaakceptowany', 'oferta zaakceptowana']));
    $lostLeadStatuses = array_unique(array_merge(leadLostStatuses(), ['przegrana']));
    $excludedFollowupStatuses = array_unique(array_merge(leadFollowupExcludedStatuses(), ['wygrana', 'przegrana', 'media plan zaakceptowany', 'oferta zaakceptowana']));

    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM leady WHERE created_at BETWEEN :from AND :to' . $leadOwnerClause);
        $stmt->execute(array_merge([':from' => $dateFromDateTime, ':to' => $dateToDateTime], $leadOwnerParams));
        $newLeads = (int)($stmt->fetchColumn() ?? 0);

        $statusSql = "LOWER(TRIM(COALESCE(status, ''))) IN ('oferta_wyslana','oferta wyslana')";
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
            $overdueParams = [];
            $overdueIn = statusSqlPlaceholders($excludedFollowupStatuses, $overdueParams, 'lead_excluded');
            $overdueSql .= " AND LOWER(TRIM(COALESCE(status, ''))) NOT IN ({$overdueIn})";
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM leady WHERE ' . $overdueSql . $leadOwnerClause);
            $params = array_merge([
                ':today' => $today,
                ':from' => $nextActionRangeFrom,
                ':to' => $nextActionRangeTo,
            ], $leadOwnerParams, $overdueParams);
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
                if (preg_match('/(→|->)\s*(Wygrana|Media plan zaakceptowany)/i', $desc)) {
                    $wonLeads++;
                }
                if (preg_match('/(→|->)\s*Przegrana/i', $desc)) {
                    $lostLeads++;
                }
            }
        }

        if (($wonLeads + $lostLeads) === 0) {
            $wonParams = [];
            $wonIn = statusSqlPlaceholders($wonLeadStatuses, $wonParams, 'lead_won');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leady WHERE created_at BETWEEN :from AND :to AND LOWER(TRIM(COALESCE(status, ''))) IN ({$wonIn})" . $leadOwnerClause);
            $stmt->execute(array_merge([':from' => $dateFromDateTime, ':to' => $dateToDateTime], $leadOwnerParams, $wonParams));
            $wonLeads = (int)($stmt->fetchColumn() ?? 0);

            $lostParams = [];
            $lostIn = statusSqlPlaceholders($lostLeadStatuses, $lostParams, 'lead_lost');
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM leady WHERE created_at BETWEEN :from AND :to AND LOWER(TRIM(COALESCE(status, ''))) IN ({$lostIn})" . $leadOwnerClause);
            $stmt->execute(array_merge([':from' => $dateFromDateTime, ':to' => $dateToDateTime], $leadOwnerParams, $lostParams));
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

    $transactionStats = fetchTransactionStatsByOwner(
        $pdo,
        $dateFromSql,
        $dateToSql,
        $selectedOwnerId > 0 ? $selectedOwnerId : null
    );
    $monthStartDate = (clone $dateFrom)->modify('first day of this month');
    $monthEndDate = (clone $monthStartDate)->modify('last day of this month');
    $monthFromSql = $monthStartDate->format('Y-m-d');
    $monthToSql = $monthEndDate->format('Y-m-d');
    $monthYear = (int)$monthStartDate->format('Y');
    $monthNumber = (int)$monthStartDate->format('n');

    $salesMonthTargetsByOwner = [];
    $salesMonthPlanTotal = 0.0;
    if (tableExists($pdo, 'cele_sprzedazowe')) {
        try {
            $stmt = $pdo->prepare('SELECT user_id, target_netto FROM cele_sprzedazowe WHERE year = :year AND month = :month');
            $stmt->execute([
                ':year' => $monthYear,
                ':month' => $monthNumber,
            ]);
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $ownerId = (int)($row['user_id'] ?? 0);
                if ($ownerId <= 0 || !isset($handlowcy[$ownerId])) {
                    continue;
                }
                $target = (float)($row['target_netto'] ?? 0);
                $salesMonthTargetsByOwner[$ownerId] = $target;
                $salesMonthPlanTotal += $target;
            }
        } catch (Throwable $e) {
            error_log('dashboard_manager: sales targets monthly failed: ' . $e->getMessage());
        }
    }

    $salesMonthStats = fetchTransactionStatsByOwner($pdo, $monthFromSql, $monthToSql, null);
    $salesMonthNettoTotal = 0.0;
    $salesMonthTransactionCount = 0;
    $salesMonthByOwner = [];
    foreach ($handlowcy as $ownerId => $ownerUser) {
        $ownerStats = $salesMonthStats['by_owner'][$ownerId] ?? [];
        $ownerNetto = (float)($ownerStats['netto'] ?? 0);
        $ownerCount = (int)($ownerStats['count'] ?? 0);
        $ownerPlan = (float)($salesMonthTargetsByOwner[$ownerId] ?? 0);
        $ownerDelta = $ownerNetto - $ownerPlan;
        $ownerPercent = $ownerPlan > 0 ? round(($ownerNetto / $ownerPlan) * 100, 1) : 0.0;

        $salesMonthNettoTotal += $ownerNetto;
        $salesMonthTransactionCount += $ownerCount;

        if ($ownerPlan <= 0 && $ownerNetto <= 0 && $ownerCount <= 0) {
            continue;
        }

        $salesMonthByOwner[] = [
            'owner_id' => (int)$ownerId,
            'owner_label' => formatOwnerLabel($ownerUser),
            'plan' => $ownerPlan,
            'netto' => $ownerNetto,
            'delta' => $ownerDelta,
            'percent' => $ownerPercent,
            'count' => $ownerCount,
        ];
    }
    usort($salesMonthByOwner, static function (array $a, array $b): int {
        return $b['netto'] <=> $a['netto'];
    });
    $salesMonthDelta = $salesMonthNettoTotal - $salesMonthPlanTotal;
    $salesMonthPercent = $salesMonthPlanTotal > 0 ? round(($salesMonthNettoTotal / $salesMonthPlanTotal) * 100, 1) : 0.0;

    $transactionsByOwner = [];
    foreach ($transactionStats['by_owner'] as $ownerId => $stats) {
        $ownerLabel = 'User #' . (int)$ownerId;
        if (isset($handlowcy[(int)$ownerId])) {
            $ownerLabel = formatOwnerLabel($handlowcy[(int)$ownerId]);
        }
        $transactionsByOwner[] = [
            'owner_id' => (int)$ownerId,
            'owner_label' => $ownerLabel,
            'count' => (int)($stats['count'] ?? 0),
            'netto' => (float)($stats['netto'] ?? 0),
        ];
    }

    $kampanieCounts = [
        'Zamówiona' => 0,
        'W realizacji' => 0,
        'Zakończona' => 0,
    ];
    $realizationKpi = [
        'missing_brief' => 0,
        'in_production' => 0,
        'audio_waiting' => 0,
        'ready_for_emission' => 0,
        'production_overdue' => 0,
        'audio_no_response' => 0,
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

        if (hasColumn($kampCols, 'realization_status')) {
            try {
                $realizationOwnerClause = '';
                $realizationOwnerParams = [];
                if ($selectedOwnerId > 0 && hasColumn($kampCols, 'owner_user_id')) {
                    $realizationOwnerClause = ' AND k.owner_user_id = :owner_id';
                    $realizationOwnerParams[':owner_id'] = $selectedOwnerId;
                }

                $stmtRealization = $pdo->prepare(
                    "SELECT
                        SUM(CASE WHEN k.realization_status = 'w_produkcji' THEN 1 ELSE 0 END) AS in_production,
                        SUM(CASE WHEN k.realization_status = 'wersje_wyslane' THEN 1 ELSE 0 END) AS audio_waiting,
                        SUM(CASE WHEN k.realization_status = 'do_emisji' THEN 1 ELSE 0 END) AS ready_for_emission
                     FROM kampanie k
                     WHERE 1=1 {$realizationOwnerClause}"
                );
                $stmtRealization->execute($realizationOwnerParams);
                $realizationRow = $stmtRealization->fetch(PDO::FETCH_ASSOC) ?: [];
                $realizationKpi['in_production'] = (int)($realizationRow['in_production'] ?? 0);
                $realizationKpi['audio_waiting'] = (int)($realizationRow['audio_waiting'] ?? 0);
                $realizationKpi['ready_for_emission'] = (int)($realizationRow['ready_for_emission'] ?? 0);
            } catch (Throwable $e) {
                error_log('dashboard_manager: realization KPI failed: ' . $e->getMessage());
            }

            if (tableExists($pdo, 'communication_events')) {
                try {
                    $prodDaysThreshold = 7;
                    $audioDaysThreshold = 5;
                    $ownerFilterSql = '';
                    $ownerFilterParams = [];
                    if ($selectedOwnerId > 0 && hasColumn($kampCols, 'owner_user_id')) {
                        $ownerFilterSql = ' AND k.owner_user_id = :owner_id';
                        $ownerFilterParams[':owner_id'] = $selectedOwnerId;
                    }

                    $sqlProdOverdue = "SELECT COUNT(*)
                        FROM kampanie k
                        LEFT JOIN (
                            SELECT campaign_id, MAX(created_at) AS event_at
                            FROM communication_events
                            WHERE event_type = 'production_started' AND status IN ('logged','sent')
                            GROUP BY campaign_id
                        ) ce ON ce.campaign_id = k.id
                        WHERE k.realization_status = 'w_produkcji'
                          AND TIMESTAMPDIFF(DAY, COALESCE(ce.event_at, k.created_at), NOW()) > :prod_days
                          {$ownerFilterSql}";
                    $stmtProdOverdue = $pdo->prepare($sqlProdOverdue);
                    $stmtProdOverdue->execute(array_merge([':prod_days' => $prodDaysThreshold], $ownerFilterParams));
                    $realizationKpi['production_overdue'] = (int)($stmtProdOverdue->fetchColumn() ?? 0);

                    $sqlAudioNoResponse = "SELECT COUNT(*)
                        FROM kampanie k
                        LEFT JOIN (
                            SELECT campaign_id, MAX(created_at) AS event_at
                            FROM communication_events
                            WHERE event_type = 'audio_dispatched' AND status IN ('logged','sent')
                            GROUP BY campaign_id
                        ) ce ON ce.campaign_id = k.id
                        WHERE k.realization_status = 'wersje_wyslane'
                          AND TIMESTAMPDIFF(DAY, COALESCE(ce.event_at, k.created_at), NOW()) > :audio_days
                          {$ownerFilterSql}";
                    $stmtAudioNoResponse = $pdo->prepare($sqlAudioNoResponse);
                    $stmtAudioNoResponse->execute(array_merge([':audio_days' => $audioDaysThreshold], $ownerFilterParams));
                    $realizationKpi['audio_no_response'] = (int)($stmtAudioNoResponse->fetchColumn() ?? 0);
                } catch (Throwable $e) {
                    error_log('dashboard_manager: realization alerts failed: ' . $e->getMessage());
                }
            }
        }

        if (tableExists($pdo, 'lead_briefs')) {
            try {
                $briefOwnerClause = '';
                $briefOwnerParams = [];
                if ($selectedOwnerId > 0 && hasColumn($kampCols, 'owner_user_id')) {
                    $briefOwnerClause = ' AND k.owner_user_id = :owner_id';
                    $briefOwnerParams[':owner_id'] = $selectedOwnerId;
                }
                $stmtBrief = $pdo->prepare(
                    "SELECT COUNT(*) AS missing_brief
                     FROM kampanie k
                     LEFT JOIN lead_briefs lb ON lb.campaign_id = k.id
                     WHERE COALESCE(k.realization_status, '') <> 'zakonczony'
                       AND (lb.id IS NULL OR COALESCE(lb.status, '') NOT IN ('submitted','approved_internal'))
                       {$briefOwnerClause}"
                );
                $stmtBrief->execute($briefOwnerParams);
                $realizationKpi['missing_brief'] = (int)($stmtBrief->fetchColumn() ?? 0);
            } catch (Throwable $e) {
                error_log('dashboard_manager: missing brief KPI failed: ' . $e->getMessage());
            }
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
            $acceptedValues = acceptedAudioStatusSqlValues();
            $acceptedParams = [];
            $acceptedIn = statusSqlPlaceholders($acceptedValues, $acceptedParams, 'audio_ready_accepted');
            $pendingValues = [
                'wyslana do klienta',
                'wyslana_do_klienta',
                'wyslano do klienta',
                'dispatched',
            ];
            $pendingParams = [];
            $pendingIn = statusSqlPlaceholders($pendingValues, $pendingParams, 'audio_ready_pending');
            $acceptedPendingParams = [];
            $acceptedPendingIn = statusSqlPlaceholders($acceptedValues, $acceptedPendingParams, 'audio_ready_pending_accepted');

            $sqlMissing = 'SELECT COUNT(DISTINCT s.id) FROM spoty s ';
            if (tableExists($pdo, 'kampanie')) {
                $sqlMissing .= 'LEFT JOIN kampanie k ON k.id = s.kampania_id ';
            }
            $sqlMissing .= 'WHERE ' . implode(' AND ', $spotWhere) . $spotOwnerClause;
            $sqlMissing .= " AND NOT EXISTS (
                SELECT 1
                FROM spot_audio_files saf
                WHERE saf.spot_id = s.id
                  AND saf.is_active = 1
                  AND LOWER(TRIM(COALESCE(saf.production_status, ''))) IN ($acceptedIn)
            )";
            $stmt = $pdo->prepare($sqlMissing);
            $stmt->execute(array_merge($spotParams, $acceptedParams));
            $audioMissing = (int)($stmt->fetchColumn() ?? 0);
            $stmt->closeCursor();

            $sqlPending = 'SELECT COUNT(DISTINCT s.id) FROM spoty s ';
            if (tableExists($pdo, 'kampanie')) {
                $sqlPending .= 'LEFT JOIN kampanie k ON k.id = s.kampania_id ';
            }
            $sqlPending .= 'WHERE ' . implode(' AND ', $spotWhere) . $spotOwnerClause;
            $sqlPending .= " AND EXISTS (
                SELECT 1
                FROM spot_audio_files saf
                WHERE saf.spot_id = s.id
                  AND saf.is_active = 1
                  AND LOWER(TRIM(COALESCE(saf.production_status, ''))) IN ($pendingIn)
            )
            AND NOT EXISTS (
                SELECT 1
                FROM spot_audio_files saf2
                WHERE saf2.spot_id = s.id
                  AND saf2.is_active = 1
                  AND LOWER(TRIM(COALESCE(saf2.production_status, ''))) IN ($acceptedPendingIn)
            )";
            $stmt = $pdo->prepare($sqlPending);
            $stmt->execute(array_merge($spotParams, $pendingParams, $acceptedPendingParams));
            $audioPending = (int)($stmt->fetchColumn() ?? 0);
            $stmt->closeCursor();
        } catch (Throwable $e) {
            error_log('dashboard_manager: audio readiness failed: ' . $e->getMessage());
        }
    }

    $documentsCount = 0;
    $taskKpi = [
        'open' => 0,
        'overdue' => 0,
        'done' => 0,
        'per_owner' => [],
    ];
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

    if (tableExists($pdo, 'crm_zadania')) {
        try {
            $taskOwnerJoin = '';
            $taskOwnerFilter = '';
            $taskParams = [];
            if ($selectedOwnerId > 0) {
                $taskOwnerFilter = ' AND t.owner_user_id = :owner_id';
                $taskParams[':owner_id'] = $selectedOwnerId;
            }

            $stmtTask = $pdo->prepare("SELECT
                    SUM(CASE WHEN t.status = 'OPEN' THEN 1 ELSE 0 END) AS open_total,
                    SUM(CASE WHEN t.status = 'OPEN' AND t.due_at < NOW() THEN 1 ELSE 0 END) AS overdue_total,
                    SUM(CASE WHEN t.status IN ('DONE','CANCELLED') THEN 1 ELSE 0 END) AS done_total
                FROM crm_zadania t
                WHERE 1=1 {$taskOwnerFilter}");
            $stmtTask->execute($taskParams);
            $taskRow = $stmtTask->fetch(PDO::FETCH_ASSOC) ?: [];
            $taskKpi['open'] = (int)($taskRow['open_total'] ?? 0);
            $taskKpi['overdue'] = (int)($taskRow['overdue_total'] ?? 0);
            $taskKpi['done'] = (int)($taskRow['done_total'] ?? 0);

            $stmtTaskOwners = $pdo->prepare("SELECT
                    t.owner_user_id,
                    SUM(CASE WHEN t.status = 'OPEN' THEN 1 ELSE 0 END) AS open_total,
                    SUM(CASE WHEN t.status = 'OPEN' AND t.due_at < NOW() THEN 1 ELSE 0 END) AS overdue_total,
                    SUM(CASE WHEN t.status IN ('DONE','CANCELLED') THEN 1 ELSE 0 END) AS done_total
                FROM crm_zadania t
                WHERE t.owner_user_id > 0 {$taskOwnerFilter}
                GROUP BY t.owner_user_id
                ORDER BY overdue_total DESC, open_total DESC");
            $stmtTaskOwners->execute($taskParams);
            while ($row = $stmtTaskOwners->fetch(PDO::FETCH_ASSOC)) {
                $ownerId = (int)($row['owner_user_id'] ?? 0);
                if ($ownerId <= 0) {
                    continue;
                }
                $ownerLabel = isset($handlowcy[$ownerId]) ? formatOwnerLabel($handlowcy[$ownerId]) : ('User #' . $ownerId);
                $taskKpi['per_owner'][] = [
                    'owner_id' => $ownerId,
                    'owner_label' => $ownerLabel,
                    'open' => (int)($row['open_total'] ?? 0),
                    'overdue' => (int)($row['overdue_total'] ?? 0),
                    'done' => (int)($row['done_total'] ?? 0),
                ];
            }
        } catch (Throwable $e) {
            error_log('dashboard_manager: task KPI failed: ' . $e->getMessage());
        }
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
            $tableExcludedParams = [];
            $tableExcludedIn = statusSqlPlaceholders($excludedFollowupStatuses, $tableExcludedParams, 'lead_overdue');
            $sql = "SELECT l.id, l.nazwa_firmy, l.owner_user_id, l.priority, l.status, l.{$nextActionColumn} AS next_action_at,
                        u.login, u.imie, u.nazwisko
                    FROM leady l
                    LEFT JOIN uzytkownicy u ON u.id = l.owner_user_id
                    WHERE l.{$nextActionColumn} IS NOT NULL
                      AND l.{$nextActionColumn} < :today
                      AND l.{$nextActionColumn} BETWEEN :from AND :to
                      AND LOWER(TRIM(COALESCE(l.status, ''))) NOT IN ({$tableExcludedIn})";
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
            $stmt->execute(array_merge($params, $tableExcludedParams));
            $overdueTable = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        error_log('dashboard_manager: overdue table failed: ' . $e->getMessage());
    }

    if (tableExists($pdo, 'spot_audio_files')) {
        try {
            $acceptedValues = acceptedAudioStatusSqlValues();
            $acceptedParams = [];
            $acceptedIn = statusSqlPlaceholders($acceptedValues, $acceptedParams, 'audio_risk_accepted');
            $sql = 'SELECT s.id, s.nazwa_spotu, s.kampania_id, k.klient_nazwa, k.owner_user_id,
                        u.login, u.imie, u.nazwisko,
                        saf.production_status
                    FROM spoty s
                    LEFT JOIN kampanie k ON k.id = s.kampania_id
                    LEFT JOIN uzytkownicy u ON u.id = k.owner_user_id
                    LEFT JOIN spot_audio_files saf ON saf.id = (
                        SELECT saf2.id
                        FROM spot_audio_files saf2
                        WHERE saf2.spot_id = s.id
                          AND saf2.is_active = 1
                        ORDER BY saf2.created_at DESC, saf2.id DESC
                        LIMIT 1
                    )
                    WHERE ' . implode(' AND ', $spotWhere) . $spotOwnerClause .
                    " AND (
                        saf.id IS NULL
                        OR LOWER(TRIM(COALESCE(saf.production_status, ''))) NOT IN ($acceptedIn)
                    )
                    ORDER BY s.data_start ASC
                    LIMIT 10";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_merge($spotParams, $acceptedParams));
            $audioRiskTable = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
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
            'realization' => $realizationKpi,
            'active_spots' => $activeSpots,
            'usage_bands' => $usageBands,
            'usage_estimate' => $usageEstimate,
            'audio_missing' => $audioMissing,
            'audio_pending' => $audioPending,
            'documents' => $documentsCount,
            'tasks' => $taskKpi,
        ],
        'transactions' => [
            'total_count' => (int)($transactionStats['totals']['count'] ?? 0),
            'total_netto' => (float)($transactionStats['totals']['netto'] ?? 0),
            'by_owner' => $transactionsByOwner,
        ],
        'sales_month' => [
            'year' => $monthYear,
            'month' => $monthNumber,
            'date_from' => $monthFromSql,
            'date_to' => $monthToSql,
            'plan_total' => $salesMonthPlanTotal,
            'netto_total' => $salesMonthNettoTotal,
            'delta' => $salesMonthDelta,
            'percent' => $salesMonthPercent,
            'transaction_count' => $salesMonthTransactionCount,
            'by_owner' => $salesMonthByOwner,
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

$pageStyles = ['dashboard'];
if (!$embeddedManagerDashboard) {
    require __DIR__ . '/includes/header.php';
}

$transactionChartLabels = [];
$transactionChartCounts = [];
$transactionChartValues = [];
foreach (($dashboard['transactions']['by_owner'] ?? []) as $row) {
    $transactionChartLabels[] = (string)($row['owner_label'] ?? '—');
    $transactionChartCounts[] = (int)($row['count'] ?? 0);
    $transactionChartValues[] = round((float)($row['netto'] ?? 0), 2);
}

$salesMonth = $dashboard['sales_month'] ?? [];
$salesMonthLabel = sprintf(
    '%02d/%04d',
    (int)($salesMonth['month'] ?? (int)date('n')),
    (int)($salesMonth['year'] ?? (int)date('Y'))
);
$salesMonthPlan = (float)($salesMonth['plan_total'] ?? 0);
$salesMonthNetto = (float)($salesMonth['netto_total'] ?? 0);
$salesMonthDelta = (float)($salesMonth['delta'] ?? 0);
$salesMonthPercent = (float)($salesMonth['percent'] ?? 0);
$salesMonthDeltaClass = $salesMonthDelta > 0 ? 'text-success' : ($salesMonthDelta < 0 ? 'text-danger' : 'text-muted');
$salesMonthDeltaPrefix = $salesMonthDelta > 0 ? '+' : '';
$selectedOwnerLabel = 'Wszyscy handlowcy';
if ($selectedOwnerId > 0 && isset($handlowcy[$selectedOwnerId])) {
    $selectedOwnerLabel = formatOwnerLabel($handlowcy[$selectedOwnerId]);
}
?>

<?php if ($embeddedManagerDashboard): ?>
<section class="page-shell page-shell--manager-dashboard dashboard-page mt-4" role="region" aria-labelledby="dashboard-manager-heading">
<?php else: ?>
<main class="page-shell page-shell--manager-dashboard dashboard-page" role="main" aria-labelledby="dashboard-manager-heading">
<?php endif; ?>
    <div class="app-header dashboard-page-header">
        <div>
            <h1 id="dashboard-manager-heading" class="app-title"><?= $embeddedManagerDashboard ? 'Panel managera' : 'Dashboard Managera' ?></h1>
            <div class="app-subtitle">KPI sprzedaży i realizacji dla zespołu, bez przebudowy logiki raportowania.</div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page"><?= $embeddedManagerDashboard ? 'Panel managera' : 'Dashboard Managera' ?></li>
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

    <?php if (!$embeddedManagerDashboard): ?>
        <section class="card mb-3" aria-label="Podsumowanie miesiąca">
            <div class="card-header">
                <h2 class="h6 mb-0">Podsumowanie miesiąca <?= htmlspecialchars($salesMonthLabel) ?> (wszyscy handlowcy)</h2>
            </div>
            <div class="card-body">
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div class="text-muted small">Plan netto</div>
                        <div class="kpi-value"><?= number_format($salesMonthPlan, 2, ',', ' ') ?> zł</div>
                        <div class="text-muted small">Suma celów sprzedażowych</div>
                    </div>
                    <div class="kpi-card">
                        <div class="text-muted small">Przychód netto</div>
                        <div class="kpi-value"><?= number_format($salesMonthNetto, 2, ',', ' ') ?> zł</div>
                        <div class="text-muted small">Transakcje: <?= (int)($salesMonth['transaction_count'] ?? 0) ?></div>
                    </div>
                    <div class="kpi-card">
                        <div class="text-muted small">Różnica (wykonanie - plan)</div>
                        <div class="kpi-value <?= $salesMonthDeltaClass ?>"><?= $salesMonthDeltaPrefix . number_format($salesMonthDelta, 2, ',', ' ') ?> zł</div>
                        <div class="text-muted small"><?= $salesMonthDelta < 0 ? 'Do planu brakuje' : 'Nadwyżka ponad plan' ?></div>
                    </div>
                    <div class="kpi-card">
                        <div class="text-muted small">Realizacja planu</div>
                        <div class="kpi-value"><?= number_format($salesMonthPercent, 1, ',', ' ') ?>%</div>
                        <div class="text-muted small">Miesiąc referencyjny: <?= htmlspecialchars($salesMonthLabel) ?></div>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <ul class="nav nav-tabs dashboard-tabs" id="managerDashboardTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-sales-tab" data-bs-toggle="tab" data-bs-target="#tab-sales" type="button" role="tab" aria-controls="tab-sales" aria-selected="true">Sprzedaż</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-ops-tab" data-bs-toggle="tab" data-bs-target="#tab-ops" type="button" role="tab" aria-controls="tab-ops" aria-selected="false">Operacje</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-risks-tab" data-bs-toggle="tab" data-bs-target="#tab-risks" type="button" role="tab" aria-controls="tab-risks" aria-selected="false">Ryzyka</button>
        </li>
    </ul>

    <div class="tab-content mb-4" id="managerDashboardTabsContent">
        <div class="tab-pane fade show active" id="tab-sales" role="tabpanel" aria-labelledby="tab-sales-tab">
            <section class="card mb-3">
                <div class="card-header"><h2 class="h6 mb-0">Transakcje handlowców (liczba i wartość)</h2></div>
                <div class="card-body">
                    <div class="text-muted small mb-3">
                        Zakres raportu: <?= htmlspecialchars($dateFrom->format('Y-m-d')) ?> - <?= htmlspecialchars($dateTo->format('Y-m-d')) ?>
                        • filtr: <?= htmlspecialchars($selectedOwnerLabel) ?>
                    </div>
                    <?php if (empty($dashboard['transactions']['by_owner'])): ?>
                        <div class="text-muted">Brak transakcji w wybranym zakresie.</div>
                    <?php else: ?>
                        <div class="dashboard-chart-frame dashboard-chart-frame--lg">
                            <canvas id="transactionsByOwnerChart" aria-label="Wykres transakcji handlowców"></canvas>
                        </div>
                        <div class="table-responsive mt-3">
                            <table class="table table-zebra table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Handlowiec</th>
                                        <th>Liczba transakcji</th>
                                        <th>Wartość netto</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($dashboard['transactions']['by_owner'] ?? []) as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)($row['owner_label'] ?? '—')) ?></td>
                                            <td><?= (int)($row['count'] ?? 0) ?></td>
                                            <td><?= number_format((float)($row['netto'] ?? 0), 2, ',', ' ') ?> zł</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card">
                <div class="card-header"><h2 class="h6 mb-0">Wykonanie planu miesiąca <?= htmlspecialchars($salesMonthLabel) ?> per handlowiec</h2></div>
                <div class="card-body">
                    <?php if (empty($salesMonth['by_owner'])): ?>
                        <div class="text-muted">Brak danych planu lub transakcji dla wybranego miesiąca.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-zebra table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Handlowiec</th>
                                        <th>Plan netto</th>
                                        <th>Przychód netto</th>
                                        <th>Różnica</th>
                                        <th>% realizacji</th>
                                        <th>Transakcje</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (($salesMonth['by_owner'] ?? []) as $row): ?>
                                        <?php
                                            $ownerDelta = (float)($row['delta'] ?? 0);
                                            $ownerDeltaClass = $ownerDelta > 0 ? 'text-success' : ($ownerDelta < 0 ? 'text-danger' : 'text-muted');
                                            $ownerDeltaPrefix = $ownerDelta > 0 ? '+' : '';
                                            $ownerPlan = (float)($row['plan'] ?? 0);
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)($row['owner_label'] ?? '—')) ?></td>
                                            <td><?= number_format($ownerPlan, 2, ',', ' ') ?> zł</td>
                                            <td><?= number_format((float)($row['netto'] ?? 0), 2, ',', ' ') ?> zł</td>
                                            <td class="<?= $ownerDeltaClass ?>"><?= $ownerDeltaPrefix . number_format($ownerDelta, 2, ',', ' ') ?> zł</td>
                                            <td><?= $ownerPlan > 0 ? number_format((float)($row['percent'] ?? 0), 1, ',', ' ') . '%' : '—' ?></td>
                                            <td><?= (int)($row['count'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>
        </div>

        <div class="tab-pane fade" id="tab-ops" role="tabpanel" aria-labelledby="tab-ops-tab">
            <section class="kpi-grid" aria-label="KPI operacyjne">
                <div class="kpi-card">
                    <div class="text-muted small">Nowe leady</div>
                    <div class="kpi-value"><?= (int)($dashboard['kpi']['new_leads'] ?? 0) ?></div>
                    <div class="text-muted small">Status: Oferta: <?= (int)($dashboard['kpi']['offer_leads'] ?? 0) ?></div>
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
                    <div class="text-muted small">Realizacja kampanii</div>
                    <div class="kpi-value"><?= (int)($dashboard['kpi']['realization']['in_production'] ?? 0) ?></div>
                    <div class="text-muted small">
                        Brak briefu: <?= (int)($dashboard['kpi']['realization']['missing_brief'] ?? 0) ?>
                        • Audio oczekuje: <?= (int)($dashboard['kpi']['realization']['audio_waiting'] ?? 0) ?>
                        • Do emisji: <?= (int)($dashboard['kpi']['realization']['ready_for_emission'] ?? 0) ?>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="text-muted small">Alerty operacyjne</div>
                    <div class="kpi-value"><?= (int)($dashboard['kpi']['realization']['production_overdue'] ?? 0) ?></div>
                    <div class="text-muted small">
                        Produkcja > 7 dni • Audio bez odpowiedzi > 5 dni:
                        <?= (int)($dashboard['kpi']['realization']['audio_no_response'] ?? 0) ?>
                    </div>
                </div>
                <div class="kpi-card">
                    <div class="text-muted small">Dokumenty</div>
                    <div class="kpi-value"><?= (int)($dashboard['kpi']['documents'] ?? 0) ?></div>
                    <div class="text-muted small">Umowy/aneksy w zakresie dat</div>
                </div>
                <div class="kpi-card">
                    <div class="text-muted small">Transakcje (zakres dat)</div>
                    <div class="kpi-value"><?= (int)($dashboard['transactions']['total_count'] ?? 0) ?></div>
                    <div class="text-muted small">Netto: <?= number_format((float)($dashboard['transactions']['total_netto'] ?? 0), 2, ',', ' ') ?> zł</div>
                </div>
                <div class="kpi-card">
                    <div class="text-muted small">Taski zespołu</div>
                    <div class="kpi-value"><?= (int)($dashboard['kpi']['tasks']['open'] ?? 0) ?></div>
                    <div class="text-muted small">
                        Overdue: <?= (int)($dashboard['kpi']['tasks']['overdue'] ?? 0) ?>
                        • Done: <?= (int)($dashboard['kpi']['tasks']['done'] ?? 0) ?>
                    </div>
                </div>
            </section>
        </div>

        <div class="tab-pane fade" id="tab-risks" role="tabpanel" aria-labelledby="tab-risks-tab">
            <section class="row g-3">
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
                                            <td><?= renderBadge('lead_status', leadStatusLabel((string)($row['status'] ?? ''))) ?></td>
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
                                            <?php
                                            $audioStatusRaw = (string)($row['production_status'] ?? '');
                                            $audioStatusLabel = $audioStatusRaw !== ''
                                                ? audioProductionStatusLabel($audioStatusRaw)
                                                : 'Brak audio';
                                            ?>
                                            <td><?= renderBadge('audio_status', $audioStatusLabel) ?></td>
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
                <div class="col-12">
                    <div class="card h-100">
                        <div class="card-header"><h2 class="h6 mb-0">Taski per owner</h2></div>
                        <div class="table-responsive">
                            <table class="table table-zebra table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Owner</th>
                                        <th>Open</th>
                                        <th>Overdue</th>
                                        <th>Done</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($dashboard['kpi']['tasks']['per_owner'])): ?>
                                        <tr><td colspan="4" class="text-muted">Brak danych tasków per owner</td></tr>
                                    <?php else: foreach ($dashboard['kpi']['tasks']['per_owner'] as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string)($row['owner_label'] ?? '—')) ?></td>
                                            <td><?= (int)($row['open'] ?? 0) ?></td>
                                            <td><?= (int)($row['overdue'] ?? 0) ?></td>
                                            <td><?= (int)($row['done'] ?? 0) ?></td>
                                        </tr>
                                    <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
<?php if ($embeddedManagerDashboard): ?>
</section>
<?php else: ?>
</main>
<?php endif; ?>

<?php if (!$embeddedManagerDashboard): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php endif; ?>
<script>
(function () {
    function initManagerChart() {
        const chartEl = document.getElementById('transactionsByOwnerChart');
        if (!chartEl || typeof Chart === 'undefined') {
            return;
        }

        const css = getComputedStyle(document.documentElement);
        const textColor = (css.getPropertyValue('--text') || '').trim() || '#0F172A';
        const mutedColor = (css.getPropertyValue('--muted') || '').trim() || '#64748B';
        const borderColor = (css.getPropertyValue('--border') || '').trim() || '#E2E8F0';
        const primaryRgb = ((css.getPropertyValue('--primary-rgb') || '').trim() || '79, 70, 229');
        const successRgb = ((css.getPropertyValue('--success-rgb') || '').trim() || '22, 163, 74');
        const chartPrimaryBg = 'rgba(' + primaryRgb + ', 0.75)';
        const chartPrimaryBorder = 'rgba(' + primaryRgb + ', 1)';
        const chartSuccessBg = 'rgba(' + successRgb + ', 0.7)';
        const chartSuccessBorder = 'rgba(' + successRgb + ', 1)';

        const labels = <?= json_encode($transactionChartLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const counts = <?= json_encode($transactionChartCounts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const values = <?= json_encode($transactionChartValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        if (!labels.length) {
            return;
        }

        new Chart(chartEl, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Liczba transakcji',
                        data: counts,
                        backgroundColor: chartPrimaryBg,
                        borderColor: chartPrimaryBorder,
                        borderWidth: 1,
                        yAxisID: 'y'
                    },
                    {
                        label: 'Wartość netto (zł)',
                        data: values,
                        backgroundColor: chartSuccessBg,
                        borderColor: chartSuccessBorder,
                        borderWidth: 1,
                        yAxisID: 'y1'
                    }
                ]
            },
            options: {
                maintainAspectRatio: false,
                responsive: true,
                plugins: {
                    legend: {
                        labels: {
                            color: textColor
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: { display: true, text: 'Liczba transakcji', color: mutedColor },
                        grid: { color: borderColor },
                        ticks: { color: textColor }
                    },
                    y1: {
                        beginAtZero: true,
                        position: 'right',
                        grid: { drawOnChartArea: false, color: borderColor },
                        title: { display: true, text: 'Wartość netto (zł)', color: mutedColor },
                        ticks: { color: textColor }
                    },
                    x: {
                        grid: { color: borderColor },
                        ticks: { color: textColor }
                    }
                }
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initManagerChart);
    } else {
        initManagerChart();
    }
})();
</script>

<?php if (!$embeddedManagerDashboard): ?>
<?php require __DIR__ . '/includes/footer.php'; ?>
<?php endif; ?>
