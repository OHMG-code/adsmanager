<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../includes/db_schema.php';
require_once __DIR__ . '/../includes/gus_validation.php';

function backfillLog(string $message): void
{
    $logDir = __DIR__ . '/../../storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/gus_backfill.log';
    $line = sprintf("[%s] %s\n", date('c'), $message);
    @file_put_contents($logFile, $line, FILE_APPEND);
}

ensureGusSnapshotsTable($pdo);
ensureCompaniesTable($pdo);

$limit = 500;
$totalUpdated = 0;
$totalSkipped = 0;
$totalChecked = 0;

while (true) {
    $stmt = $pdo->prepare('SELECT id, request_value FROM gus_snapshots WHERE company_id IS NULL AND request_value IS NOT NULL ORDER BY id ASC LIMIT :limit');
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        break;
    }

    foreach ($rows as $row) {
        $totalChecked++;
        $snapshotId = (int)$row['id'];
        $value = trim((string)$row['request_value']);
        if ($value === '') {
            $totalSkipped++;
            continue;
        }

        $companyId = null;
        $stmtCompany = $pdo->prepare('SELECT id FROM companies WHERE nip = :val LIMIT 2');
        $stmtCompany->execute([':val' => $value]);
        $matches = $stmtCompany->fetchAll(PDO::FETCH_COLUMN);
        if (count($matches) === 1) {
            $companyId = (int)$matches[0];
        }

        if ($companyId === null) {
            $stmtCompany = $pdo->prepare('SELECT id FROM companies WHERE regon = :val LIMIT 2');
            $stmtCompany->execute([':val' => $value]);
            $matches = $stmtCompany->fetchAll(PDO::FETCH_COLUMN);
            if (count($matches) === 1) {
                $companyId = (int)$matches[0];
            }
        }

        if ($companyId === null) {
            $stmtCompany = $pdo->prepare('SELECT id FROM companies WHERE krs = :val LIMIT 2');
            $stmtCompany->execute([':val' => $value]);
            $matches = $stmtCompany->fetchAll(PDO::FETCH_COLUMN);
            if (count($matches) === 1) {
                $companyId = (int)$matches[0];
            }
        }

        if ($companyId === null) {
            $totalSkipped++;
            continue;
        }

        $update = $pdo->prepare('UPDATE gus_snapshots SET company_id = :company_id WHERE id = :id');
        $update->execute([':company_id' => $companyId, ':id' => $snapshotId]);
        $totalUpdated++;
    }
}

// Backfill gus_last_* fields
$rows = $pdo->query('SELECT id, last_gus_check_at, last_gus_error_at, gus_last_status, gus_last_refresh_at FROM companies')->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($rows as $row) {
    $id = (int)$row['id'];
    $lastCheck = $row['last_gus_check_at'] ?? null;
    $lastError = $row['last_gus_error_at'] ?? null;
    $gusLastRefresh = $row['gus_last_refresh_at'] ?? null;
    $status = $row['gus_last_status'] ?? null;

    $newStatus = $status;
    if ($lastError) {
        $newStatus = 'ERROR';
    } elseif ($lastCheck) {
        $newStatus = 'OK';
    }

    $newRefresh = $gusLastRefresh;
    if ($newRefresh === null && $lastCheck) {
        $newRefresh = $lastCheck;
    }

    $stmt = $pdo->prepare('UPDATE companies SET gus_last_status = :status, gus_last_refresh_at = :refresh WHERE id = :id');
    $stmt->execute([
        ':status' => $newStatus,
        ':refresh' => $newRefresh,
        ':id' => $id,
    ]);
}

backfillLog("Backfill done. checked={$totalChecked}, updated={$totalUpdated}, skipped={$totalSkipped}");
echo "Backfill done. checked={$totalChecked}, updated={$totalUpdated}, skipped={$totalSkipped}\n";
