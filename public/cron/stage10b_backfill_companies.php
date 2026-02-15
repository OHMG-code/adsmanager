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
    $logFile = $logDir . '/stage10b_backfill.log';
    $line = sprintf("[%s] %s\n", date('c'), $message);
    @file_put_contents($logFile, $line, FILE_APPEND);
}

function toNull(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function detectDuplicates(PDO $pdo, string $table, string $idCol, string $nipCol, int $batchSize): array
{
    $duplicates = [];
    $counts = [];
    $firstIds = [];
    $lastId = 0;

    while (true) {
        $stmt = $pdo->prepare("SELECT {$idCol} AS id, {$nipCol} AS nip FROM {$table} WHERE {$idCol} > :lastId ORDER BY {$idCol} ASC LIMIT :limit");
        $stmt->bindValue(':lastId', $lastId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (!$rows) {
            break;
        }

        foreach ($rows as $row) {
            $lastId = (int)$row['id'];
            $nip = normalizeNip((string)($row['nip'] ?? ''));
            if ($nip === '') {
                continue;
            }
            if (!isset($counts[$nip])) {
                $counts[$nip] = 1;
                $firstIds[$nip] = [(int)$row['id']];
                continue;
            }
            $counts[$nip]++;
            if ($counts[$nip] === 2) {
                $duplicates[$nip] = [$firstIds[$nip][0], (int)$row['id']];
            } else {
                $duplicates[$nip][] = (int)$row['id'];
            }
        }
    }

    return $duplicates;
}

$batchSize = 500;
foreach ($argv as $arg) {
    if (strpos($arg, '--batch=') === 0) {
        $batchSize = (int)substr($arg, 8);
    }
}
if ($batchSize <= 0) {
    $batchSize = 500;
}

ensureCompaniesTable($pdo);
ensureGusSnapshotsTable($pdo);
ensureClientLeadColumns($pdo);

backfillLog('Stage10B backfill started');

$totalClients = (int)$pdo->query('SELECT COUNT(*) FROM klienci')->fetchColumn();
$validNipCount = 0;
$invalidNipCount = 0;
$assignedCompanyCount = 0;
$newCompanies = 0;
$matchedByRegon = 0;
$ambiguousRegon = 0;
$unmatchedNoId = 0;

backfillLog('Detecting duplicates in klienci and companies by NIP');
$dupKlienci = detectDuplicates($pdo, 'klienci', 'id', 'nip', $batchSize);
$dupCompanies = detectDuplicates($pdo, 'companies', 'id', 'nip', $batchSize);

$companyByNip = [];
$companyByRegon = [];

$lastId = 0;
while (true) {
    $stmt = $pdo->prepare('SELECT * FROM klienci WHERE id > :lastId ORDER BY id ASC LIMIT :limit');
    $stmt->bindValue(':lastId', $lastId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        break;
    }

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $lastId = (int)$row['id'];
            $existingCompanyId = isset($row['company_id']) ? (int)$row['company_id'] : 0;

            $nipNorm = normalizeNip((string)($row['nip'] ?? ''));
            $regonNorm = normalizeRegon((string)($row['regon'] ?? ''));
            $hasValidNip = ($nipNorm !== '' && strlen($nipNorm) === 10);
            if ($nipNorm !== '' && strlen($nipNorm) !== 10) {
                $invalidNipCount++;
            }
            if ($hasValidNip) {
                $validNipCount++;
            }

            if ($existingCompanyId > 0) {
                $assignedCompanyCount++;
                continue;
            }

            $companyId = 0;
            if ($hasValidNip) {
                if (array_key_exists($nipNorm, $companyByNip)) {
                    $companyId = (int)$companyByNip[$nipNorm];
                } else {
                    $stmtCompany = $pdo->prepare('SELECT id FROM companies WHERE nip = :nip LIMIT 2');
                    $stmtCompany->execute([':nip' => $nipNorm]);
                    $matches = $stmtCompany->fetchAll(PDO::FETCH_COLUMN);
                    if (count($matches) === 1) {
                        $companyId = (int)$matches[0];
                        $companyByNip[$nipNorm] = $companyId;
                    } else {
                        $companyByNip[$nipNorm] = 0;
                    }
                }
            }

            if ($companyId === 0 && $hasValidNip) {
                $nameFull = toNull($row['nazwa_firmy'] ?? null);
                $street = toNull($row['ulica'] ?? null);
                if ($street === null) {
                    $street = toNull($row['adres'] ?? null);
                }
                $buildingNo = toNull($row['nr_nieruchomosci'] ?? null);
                $apartmentNo = toNull($row['nr_lokalu'] ?? null);
                $postalCode = toNull($row['kod_pocztowy'] ?? null);
                $city = toNull($row['miejscowosc'] ?? null);
                $woj = toNull($row['wojewodztwo'] ?? null);
                $powiat = toNull($row['powiat'] ?? null);
                $gmina = toNull($row['gmina'] ?? null);
                $country = toNull($row['kraj'] ?? null);

                $regonValue = null;
                if ($regonNorm !== '' && (strlen($regonNorm) === 9 || strlen($regonNorm) === 14)) {
                    $regonValue = $regonNorm;
                }

                $stmtInsert = $pdo->prepare('INSERT INTO companies
                    (nip, regon, name_full, name_short, status, is_active, street, building_no, apartment_no, postal_code, city, wojewodztwo, powiat, gmina, country)
                    VALUES
                    (:nip, :regon, :name_full, NULL, NULL, 1, :street, :building_no, :apartment_no, :postal_code, :city, :wojewodztwo, :powiat, :gmina, :country)');
                $stmtInsert->execute([
                    ':nip' => $nipNorm,
                    ':regon' => $regonValue,
                    ':name_full' => $nameFull,
                    ':street' => $street,
                    ':building_no' => $buildingNo,
                    ':apartment_no' => $apartmentNo,
                    ':postal_code' => $postalCode,
                    ':city' => $city,
                    ':wojewodztwo' => $woj,
                    ':powiat' => $powiat,
                    ':gmina' => $gmina,
                    ':country' => $country,
                ]);
                $companyId = (int)$pdo->lastInsertId();
                $companyByNip[$nipNorm] = $companyId;
                if ($regonValue) {
                    $companyByRegon[$regonValue] = $companyId;
                }
                $newCompanies++;
            }

            if ($companyId === 0 && !$hasValidNip && $regonNorm !== '' && (strlen($regonNorm) === 9 || strlen($regonNorm) === 14)) {
                if (array_key_exists($regonNorm, $companyByRegon)) {
                    $companyId = (int)$companyByRegon[$regonNorm];
                } else {
                    $stmtCompany = $pdo->prepare('SELECT id FROM companies WHERE regon = :regon LIMIT 2');
                    $stmtCompany->execute([':regon' => $regonNorm]);
                    $matches = $stmtCompany->fetchAll(PDO::FETCH_COLUMN);
                    if (count($matches) === 1) {
                        $companyId = (int)$matches[0];
                        $companyByRegon[$regonNorm] = $companyId;
                        $matchedByRegon++;
                    } elseif (count($matches) > 1) {
                        $ambiguousRegon++;
                        $companyByRegon[$regonNorm] = 0;
                    }
                }
            }

            if ($companyId > 0) {
                $stmtUpdate = $pdo->prepare('UPDATE klienci SET company_id = :company_id WHERE id = :id');
                $stmtUpdate->execute([':company_id' => $companyId, ':id' => $lastId]);
                $assignedCompanyCount++;
            } else {
                $unmatchedNoId++;
            }
        }

        $pdo->commit();
        backfillLog("Batch klienci processed up to id={$lastId}");
    } catch (Throwable $e) {
        $pdo->rollBack();
        backfillLog('Batch failed: ' . $e->getMessage());
        throw $e;
    }
}

// Backfill gus_snapshots.company_id by request_value
$snapMatched = 0;
$snapSkipped = 0;
$snapChecked = 0;
$lastSnapshotId = 0;
while (true) {
    $stmt = $pdo->prepare('SELECT id, request_type, request_value FROM gus_snapshots WHERE company_id IS NULL AND request_value IS NOT NULL AND id > :lastId ORDER BY id ASC LIMIT :limit');
    $stmt->bindValue(':lastId', $lastSnapshotId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        break;
    }

    $pdo->beginTransaction();
    try {
        foreach ($rows as $row) {
            $snapChecked++;
            $lastSnapshotId = (int)$row['id'];
            $type = strtolower(trim((string)($row['request_type'] ?? '')));
            $value = trim((string)($row['request_value'] ?? ''));
            if ($value === '') {
                $snapSkipped++;
                continue;
            }

            $companyId = 0;
            if ($type === 'nip') {
                $value = normalizeNip($value);
                if ($value !== '') {
                    $stmtCompany = $pdo->prepare('SELECT id FROM companies WHERE nip = :val LIMIT 2');
                    $stmtCompany->execute([':val' => $value]);
                    $matches = $stmtCompany->fetchAll(PDO::FETCH_COLUMN);
                    if (count($matches) === 1) {
                        $companyId = (int)$matches[0];
                    }
                }
            } elseif ($type === 'regon') {
                $value = normalizeRegon($value);
                if ($value !== '') {
                    $stmtCompany = $pdo->prepare('SELECT id FROM companies WHERE regon = :val LIMIT 2');
                    $stmtCompany->execute([':val' => $value]);
                    $matches = $stmtCompany->fetchAll(PDO::FETCH_COLUMN);
                    if (count($matches) === 1) {
                        $companyId = (int)$matches[0];
                    }
                }
            } elseif ($type === 'krs') {
                $value = normalizeKrs($value);
                if ($value !== '') {
                    $stmtCompany = $pdo->prepare('SELECT id FROM companies WHERE krs = :val LIMIT 2');
                    $stmtCompany->execute([':val' => $value]);
                    $matches = $stmtCompany->fetchAll(PDO::FETCH_COLUMN);
                    if (count($matches) === 1) {
                        $companyId = (int)$matches[0];
                    }
                }
            } else {
                $snapSkipped++;
                continue;
            }

            if ($companyId > 0) {
                $update = $pdo->prepare('UPDATE gus_snapshots SET company_id = :company_id WHERE id = :id');
                $update->execute([':company_id' => $companyId, ':id' => $lastSnapshotId]);
                $snapMatched++;
            } else {
                $snapSkipped++;
            }
        }

        $pdo->commit();
        backfillLog("Batch gus_snapshots processed up to id={$lastSnapshotId}");
    } catch (Throwable $e) {
        $pdo->rollBack();
        backfillLog('Snapshot batch failed: ' . $e->getMessage());
        throw $e;
    }
}

// Backfill companies.gus_last_* based on snapshots
$companiesWithRefresh = 0;
$lastCompanyId = 0;
while (true) {
    $stmt = $pdo->prepare('SELECT id FROM companies WHERE id > :lastId ORDER BY id ASC LIMIT :limit');
    $stmt->bindValue(':lastId', $lastCompanyId, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
    $stmt->execute();
    $companyRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$companyRows) {
        break;
    }

    $ids = array_map(static function (array $row): int {
        return (int)$row['id'];
    }, $companyRows);
    $lastCompanyId = (int)end($ids);

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $snapStmt = $pdo->prepare("SELECT company_id, created_at, ok, error_code, error_message
        FROM gus_snapshots
        WHERE company_id IN ($placeholders)
        ORDER BY company_id ASC, created_at DESC");
    $snapStmt->execute($ids);
    $snapshots = $snapStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $lastAny = [];
    $lastOk = [];
    $lastError = [];
    foreach ($snapshots as $snap) {
        $cid = (int)$snap['company_id'];
        if (!isset($lastAny[$cid])) {
            $lastAny[$cid] = $snap;
        }
        if (!empty($snap['ok']) && !isset($lastOk[$cid])) {
            $lastOk[$cid] = $snap;
        }
        if (empty($snap['ok']) && !isset($lastError[$cid])) {
            $lastError[$cid] = $snap;
        }
    }

    $pdo->beginTransaction();
    try {
        $updateStmt = $pdo->prepare('UPDATE companies
            SET gus_last_refresh_at = :refresh_at,
                gus_last_status = :status,
                gus_last_error_code = :err_code,
                gus_last_error_message = :err_msg
            WHERE id = :id');

        foreach ($ids as $cid) {
            $okSnap = $lastOk[$cid] ?? null;
            $anySnap = $lastAny[$cid] ?? null;
            $errSnap = $lastError[$cid] ?? null;

            $refreshAt = $okSnap['created_at'] ?? null;
            $status = null;
            if ($refreshAt) {
                $status = 'OK';
            } elseif ($anySnap) {
                $status = 'ERROR';
            }

            $errCode = null;
            $errMsg = null;
            if (!$refreshAt && $errSnap) {
                $errCode = $errSnap['error_code'] ?? null;
                $errMsg = $errSnap['error_message'] ?? null;
            }

            $updateStmt->execute([
                ':refresh_at' => $refreshAt,
                ':status' => $status,
                ':err_code' => $errCode,
                ':err_msg' => $errMsg,
                ':id' => $cid,
            ]);

            if ($refreshAt) {
                $companiesWithRefresh++;
            }
        }

        $pdo->commit();
        backfillLog("Batch companies metadata updated up to id={$lastCompanyId}");
    } catch (Throwable $e) {
        $pdo->rollBack();
        backfillLog('Companies metadata batch failed: ' . $e->getMessage());
        throw $e;
    }
}

$summary = [
    'klienci_total' => $totalClients,
    'klienci_valid_nip' => $validNipCount,
    'klienci_invalid_nip_len' => $invalidNipCount,
    'klienci_company_id_assigned' => $assignedCompanyCount,
    'companies_created' => $newCompanies,
    'regon_matched' => $matchedByRegon,
    'regon_ambiguous' => $ambiguousRegon,
    'unmatched_clients' => $unmatchedNoId,
    'dup_nip_klienci' => count($dupKlienci),
    'dup_nip_companies' => count($dupCompanies),
    'snapshots_checked' => $snapChecked,
    'snapshots_matched' => $snapMatched,
    'companies_with_gus_last_refresh_at' => $companiesWithRefresh,
];

backfillLog('Summary: ' . json_encode($summary, JSON_UNESCAPED_UNICODE));
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

if ($dupKlienci) {
    backfillLog('Duplicate NIP in klienci: ' . json_encode($dupKlienci, JSON_UNESCAPED_UNICODE));
}
if ($dupCompanies) {
    backfillLog('Duplicate NIP in companies: ' . json_encode($dupCompanies, JSON_UNESCAPED_UNICODE));
}

backfillLog('Manual actions: resolve duplicate NIP in klienci/companies, review unmatched clients and ambiguous REGON matches, then consider adding UNIQUE index on companies.nip.');
backfillLog('Stage10B backfill finished');
