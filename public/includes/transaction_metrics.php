<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/transactions.php';

function transactionResolveDateColumn(array $kampaniaColumns): ?array
{
    if (hasColumn($kampaniaColumns, 'data_start')) {
        return ['column' => 'data_start', 'with_time' => false];
    }
    if (hasColumn($kampaniaColumns, 'created_at')) {
        return ['column' => 'created_at', 'with_time' => true];
    }
    return null;
}

function transactionResolveValueExpr(array $kampaniaColumns, string $alias = 'k'): string
{
    $fallbackExpr = '0';
    if (hasColumn($kampaniaColumns, 'razem_netto')) {
        $fallbackExpr = "COALESCE({$alias}.razem_netto, 0)";
    } elseif (hasColumn($kampaniaColumns, 'netto_spoty') && hasColumn($kampaniaColumns, 'netto_dodatki')) {
        $fallbackExpr = "COALESCE({$alias}.netto_spoty, 0) + COALESCE({$alias}.netto_dodatki, 0)";
    } elseif (hasColumn($kampaniaColumns, 'netto_spoty')) {
        $fallbackExpr = "COALESCE({$alias}.netto_spoty, 0)";
    }

    if (hasColumn($kampaniaColumns, 'wartosc_netto')) {
        return "CASE WHEN COALESCE({$alias}.wartosc_netto, 0) > 0 THEN {$alias}.wartosc_netto ELSE {$fallbackExpr} END";
    }
    return $fallbackExpr;
}

function transactionResolveStatusCondition(array $kampaniaColumns, string $alias = 'k'): string
{
    if (hasColumn($kampaniaColumns, 'status')) {
        return "LOWER(REPLACE(TRIM(COALESCE({$alias}.status, '')), '_', ' ')) IN ('zamowiona','zamówiona','w realizacji','zakończona','zakonczona')";
    }
    if (hasColumn($kampaniaColumns, 'propozycja')) {
        return "COALESCE({$alias}.propozycja, 0) = 0";
    }
    return '1=1';
}

function transactionResolveAttribution(PDO $pdo, array $kampaniaColumns, string $alias = 'k'): array
{
    $joins = [];
    $ownerExprParts = [];

    if (hasColumn($kampaniaColumns, 'owner_user_id')) {
        $ownerExprParts[] = "NULLIF({$alias}.owner_user_id, 0)";
    }

    $hasLeadJoin = false;
    $leadColumns = [];
    if (tableExists($pdo, 'leady') && hasColumn($kampaniaColumns, 'source_lead_id')) {
        ensureLeadColumns($pdo);
        $leadColumns = getTableColumns($pdo, 'leady');
        $joins[] = "LEFT JOIN leady l ON l.id = {$alias}.source_lead_id";
        $hasLeadJoin = true;
        if (hasColumn($leadColumns, 'owner_user_id')) {
            $ownerExprParts[] = 'NULLIF(l.owner_user_id, 0)';
        }
        if (hasColumn($leadColumns, 'assigned_user_id')) {
            $ownerExprParts[] = 'NULLIF(l.assigned_user_id, 0)';
        }
    }

    if (tableExists($pdo, 'klienci')) {
        ensureClientLeadColumns($pdo);
        $clientColumns = getTableColumns($pdo, 'klienci');
        $clientJoin = '';
        if (hasColumn($kampaniaColumns, 'klient_id') && $hasLeadJoin && hasColumn($leadColumns, 'client_id')) {
            $clientJoin = "LEFT JOIN klienci c ON c.id = COALESCE(NULLIF({$alias}.klient_id, 0), NULLIF(l.client_id, 0))";
        } elseif (hasColumn($kampaniaColumns, 'klient_id')) {
            $clientJoin = "LEFT JOIN klienci c ON c.id = NULLIF({$alias}.klient_id, 0)";
        } elseif ($hasLeadJoin && hasColumn($leadColumns, 'client_id')) {
            $clientJoin = 'LEFT JOIN klienci c ON c.id = NULLIF(l.client_id, 0)';
        }

        if ($clientJoin !== '') {
            $joins[] = $clientJoin;
            if (hasColumn($clientColumns, 'owner_user_id')) {
                $ownerExprParts[] = 'NULLIF(c.owner_user_id, 0)';
            }
            if (hasColumn($clientColumns, 'assigned_user_id')) {
                $ownerExprParts[] = 'NULLIF(c.assigned_user_id, 0)';
            }
        }
    }

    $ownerExpr = $ownerExprParts ? 'COALESCE(' . implode(', ', $ownerExprParts) . ')' : 'NULL';

    return [
        'joins_sql' => $joins ? "\n" . implode("\n", $joins) : '',
        'owner_expr' => $ownerExpr,
    ];
}

function fetchTransactionStatsByOwner(PDO $pdo, string $dateFrom, string $dateTo, ?int $ownerUserId = null): array
{
    $result = [
        'totals' => ['count' => 0, 'netto' => 0.0],
        'by_owner' => [],
    ];

    $rangeFromDateTime = $dateFrom . ' 00:00:00';
    $rangeToDateTime = $dateTo . ' 23:59:59';
    $hasTransactionsTable = tableExists($pdo, 'transactions');

    if ($hasTransactionsTable) {
        ensureTransactionsTable($pdo);
        ensureKampanieOwnershipColumns($pdo);
        ensureLeadColumns($pdo);
        ensureClientLeadColumns($pdo);

        try {
            $sqlTx = "SELECT
                        COALESCE(NULLIF(t.owner_user_id, 0), NULLIF(k.owner_user_id, 0), NULLIF(l.owner_user_id, 0), NULLIF(l.assigned_user_id, 0), NULLIF(c.owner_user_id, 0), NULLIF(c.assigned_user_id, 0)) AS owner_id,
                        COUNT(*) AS transaction_count,
                        SUM(COALESCE(t.value_netto, 0)) AS transaction_netto
                    FROM transactions t
                    LEFT JOIN kampanie k ON k.id = t.campaign_id
                    LEFT JOIN leady l ON l.id = COALESCE(NULLIF(t.source_lead_id, 0), NULLIF(k.source_lead_id, 0))
                    LEFT JOIN klienci c ON c.id = COALESCE(NULLIF(t.client_id, 0), NULLIF(k.klient_id, 0), NULLIF(l.client_id, 0))
                    WHERE COALESCE(t.won_at, t.created_at) BETWEEN :from_dt AND :to_dt";
            $paramsTx = [
                ':from_dt' => $rangeFromDateTime,
                ':to_dt' => $rangeToDateTime,
            ];
            if ($ownerUserId !== null && $ownerUserId > 0) {
                $sqlTx .= ' AND COALESCE(NULLIF(t.owner_user_id, 0), NULLIF(k.owner_user_id, 0), NULLIF(l.owner_user_id, 0), NULLIF(l.assigned_user_id, 0), NULLIF(c.owner_user_id, 0), NULLIF(c.assigned_user_id, 0)) = :owner_user_id';
                $paramsTx[':owner_user_id'] = $ownerUserId;
            }
            $sqlTx .= ' GROUP BY owner_id ORDER BY transaction_netto DESC, transaction_count DESC';

            $stmtTx = $pdo->prepare($sqlTx);
            $stmtTx->execute($paramsTx);
            while ($row = $stmtTx->fetch(PDO::FETCH_ASSOC)) {
                $ownerId = (int)($row['owner_id'] ?? 0);
                if ($ownerId <= 0) {
                    continue;
                }
                $count = (int)($row['transaction_count'] ?? 0);
                $netto = (float)($row['transaction_netto'] ?? 0);
                if (!isset($result['by_owner'][$ownerId])) {
                    $result['by_owner'][$ownerId] = ['count' => 0, 'netto' => 0.0];
                }
                $result['by_owner'][$ownerId]['count'] += $count;
                $result['by_owner'][$ownerId]['netto'] += $netto;
                $result['totals']['count'] += $count;
                $result['totals']['netto'] += $netto;
            }
        } catch (Throwable $e) {
            error_log('transaction_metrics: transaction source failed: ' . $e->getMessage());
        }
    }

    if (!tableExists($pdo, 'kampanie')) {
        return $result;
    }

    ensureKampanieOwnershipColumns($pdo);
    ensureKampanieSalesValueColumn($pdo);
    $kampaniaColumns = getTableColumns($pdo, 'kampanie');
    if (!$kampaniaColumns) {
        return $result;
    }

    $dateConfig = transactionResolveDateColumn($kampaniaColumns);
    if ($dateConfig === null) {
        return $result;
    }

    $rangeFrom = $dateFrom;
    $rangeTo = $dateTo;
    if (!empty($dateConfig['with_time'])) {
        $rangeFrom .= ' 00:00:00';
        $rangeTo .= ' 23:59:59';
    }

    $valueExpr = transactionResolveValueExpr($kampaniaColumns, 'k');
    $statusCondition = transactionResolveStatusCondition($kampaniaColumns, 'k');
    $attribution = transactionResolveAttribution($pdo, $kampaniaColumns, 'k');
    $ownerExpr = $attribution['owner_expr'];
    if ($ownerExpr === 'NULL') {
        return $result;
    }

    $where = [
        "k.{$dateConfig['column']} BETWEEN :date_from AND :date_to",
        $statusCondition,
        $ownerExpr . ' IS NOT NULL',
    ];
    $params = [
        ':date_from' => $rangeFrom,
        ':date_to' => $rangeTo,
    ];

    if ($hasTransactionsTable) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM transactions t2 WHERE t2.campaign_id = k.id)';
    }

    if ($ownerUserId !== null && $ownerUserId > 0) {
        $where[] = $ownerExpr . ' = :owner_user_id';
        $params[':owner_user_id'] = $ownerUserId;
    }

    $sql = "SELECT {$ownerExpr} AS owner_id,
                   COUNT(*) AS transaction_count,
                   SUM({$valueExpr}) AS transaction_netto
            FROM kampanie k" . $attribution['joins_sql'] . '
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY owner_id
            ORDER BY transaction_netto DESC, transaction_count DESC';

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $ownerId = (int)($row['owner_id'] ?? 0);
            if ($ownerId <= 0) {
                continue;
            }
            $count = (int)($row['transaction_count'] ?? 0);
            $netto = (float)($row['transaction_netto'] ?? 0);
            if (!isset($result['by_owner'][$ownerId])) {
                $result['by_owner'][$ownerId] = ['count' => 0, 'netto' => 0.0];
            }
            $result['by_owner'][$ownerId]['count'] += $count;
            $result['by_owner'][$ownerId]['netto'] += $netto;
            $result['totals']['count'] += $count;
            $result['totals']['netto'] += $netto;
        }
    } catch (Throwable $e) {
        error_log('transaction_metrics: campaign fallback failed: ' . $e->getMessage());
    }

    return $result;
}
