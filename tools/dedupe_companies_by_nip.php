<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../public/includes/db_schema.php';

const DEDUPE_LOG_PATH = __DIR__ . '/../storage/logs/dedupe_companies.log';

function normalizeNip(string $nip): ?string
{
    $digits = preg_replace('/\D+/', '', $nip) ?? '';
    return strlen($digits) === 10 ? $digits : null;
}

function countFilledFields(array $row): int
{
    $keys = [
        'name_full', 'name_short', 'status', 'is_active',
        'street', 'building_no', 'apartment_no', 'postal_code', 'city',
        'gmina', 'powiat', 'wojewodztwo', 'country', 'regon', 'krs',
        'gus_last_refresh_at', 'gus_last_status', 'gus_last_error_code', 'gus_last_sid',
        'last_gus_check_at', 'last_gus_error_at',
    ];
    $count = 0;
    foreach ($keys as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $val = $row[$key];
        if ($val === null) {
            continue;
        }
        if (is_string($val)) {
            if (trim($val) === '') {
                continue;
            }
        }
        $count++;
    }
    return $count;
}

function buildDedupePlan(array $rows): array
{
    $groups = [];
    foreach ($rows as $row) {
        if (!isset($row['nip'])) {
            continue;
        }
        $normalized = normalizeNip((string)$row['nip']);
        if ($normalized === null) {
            continue;
        }
        $id = (int)$row['id'];
        $row['__score'] = countFilledFields($row);
        $groups[$normalized]['nip'] = $normalized;
        $groups[$normalized]['rows'][] = $row;
    }

    $plan = [];
    foreach ($groups as $normalized => $data) {
        $rows = $data['rows'] ?? [];
        if (count($rows) <= 1) {
            continue;
        }
        usort($rows, static function (array $a, array $b): int {
            $scoreDiff = ($b['__score'] ?? 0) <=> ($a['__score'] ?? 0);
            if ($scoreDiff !== 0) {
                return $scoreDiff;
            }
            return ($a['id'] ?? 0) <=> ($b['id'] ?? 0);
        });
        $master = $rows[0];
        $duplicates = array_slice($rows, 1);
        $plan[] = [
            'nip' => $normalized,
            'master_id' => (int)$master['id'],
            'master_score' => $master['__score'] ?? 0,
            'duplicate_ids' => array_values(array_map(static fn($r) => (int)$r['id'], $duplicates)),
            'total' => count($rows),
        ];
    }
    return $plan;
}

function logLine(string $line): void
{
    $dir = dirname(DEDUPE_LOG_PATH);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $ts = date('c');
    @file_put_contents(DEDUPE_LOG_PATH, "[$ts] $line\n", FILE_APPEND);
}

function reassignCompanyIds(PDO $pdo, array $ids, int $masterId, string $table, string $column): void
{
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE {$table} SET {$column} = ? WHERE {$column} IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$masterId], $ids));
}

function markDuplicates(PDO $pdo, array $ids): void
{
    if (!$ids) {
        return;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "UPDATE companies SET is_active = 0, status = 'DUPLICATE', nip = NULL WHERE id IN ($placeholders)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($ids);
}

function updateMasterNip(PDO $pdo, int $masterId, string $normalizedNip): void
{
    $stmt = $pdo->prepare('UPDATE companies SET nip = :nip WHERE id = :id');
    $stmt->execute([':nip' => $normalizedNip, ':id' => $masterId]);
}

function logChanges(PDO $pdo, int $masterId, array $duplicateIds, string $nip, bool $logTable): void
{
    $fields = ['deduped_master', 'deduped_duplicates'];
    $diff = [
        'nip' => $nip,
        'master_id' => $masterId,
        'duplicate_ids' => $duplicateIds,
    ];
    if ($logTable && function_exists('ensureCompanyChangeLogTable')) {
        ensureCompanyChangeLogTable($pdo);
        $stmt = $pdo->prepare('INSERT INTO company_change_log (company_id, user_id, source, changed_fields, diff) VALUES (:company_id, :user_id, :source, :fields, :diff)');
        $stmt->execute([
            ':company_id' => $masterId,
            ':user_id' => null,
            ':source' => 'dedupe',
            ':fields' => json_encode($fields, JSON_UNESCAPED_UNICODE),
            ':diff' => json_encode($diff, JSON_UNESCAPED_UNICODE),
        ]);
    }
    logLine('deduped nip=' . $nip . ' master=' . $masterId . ' duplicates=' . implode(',', $duplicateIds));
}

function runDedupe(PDO $pdo, bool $dryRun): array
{
    ensureCompaniesTable($pdo);
    $stmt = $pdo->query('SELECT * FROM companies WHERE nip IS NOT NULL AND TRIM(nip) <> ""');
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    $plan = buildDedupePlan($rows);

    if ($dryRun) {
        return $plan;
    }

    $logTable = tableExists($pdo, 'company_change_log');

    foreach ($plan as $group) {
        $masterId = (int)$group['master_id'];
        $dupIds = $group['duplicate_ids'];
        if (!$dupIds) {
            continue;
        }
        $pdo->beginTransaction();
        try {
            reassignCompanyIds($pdo, $dupIds, $masterId, 'klienci', 'company_id');
            reassignCompanyIds($pdo, $dupIds, $masterId, 'gus_snapshots', 'company_id');
            markDuplicates($pdo, $dupIds);
            updateMasterNip($pdo, $masterId, $group['nip']);
            logChanges($pdo, $masterId, $dupIds, $group['nip'], $logTable);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            logLine('error nip=' . $group['nip'] . ' master=' . $masterId . ' msg=' . $e->getMessage());
        }
    }

    return $plan;
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $dryRun = false;
    foreach ($argv as $arg) {
        if ($arg === '--dry-run=1' || $arg === '--dry-run' || $arg === '-n') {
            $dryRun = true;
        }
    }
    $plan = runDedupe($pdo, $dryRun);
    $output = [
        'dry_run' => $dryRun,
        'groups' => $plan,
        'total_groups' => count($plan),
    ];
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
