<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../public/includes/db_schema.php';
require_once __DIR__ . '/../services/company_writer.php';
require_once __DIR__ . '/../services/company_input_mapper.php';

const SCAN_LOG = __DIR__ . '/../storage/logs/scan_clients_without_company.log';

function logScan(string $line): void
{
    $dir = dirname(SCAN_LOG);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    @file_put_contents(SCAN_LOG, '[' . date('c') . '] ' . $line . "\n", FILE_APPEND);
}

function isValidNipStr(?string $nip): bool
{
    if ($nip === null) {
        return false;
    }
    $digits = preg_replace('/\D+/', '', (string)$nip) ?? '';
    return strlen($digits) === 10;
}

function fetchCandidates(PDO $pdo): array
{
    ensureClientLeadColumns($pdo);
    $sql = "SELECT id, company_id, nip, nazwa_firmy, data_dodania FROM klienci WHERE company_id IS NULL";
    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    return array_values(array_filter($rows, static function (array $row): bool {
        $nipOk = isValidNipStr($row['nip'] ?? null);
        $name = trim((string)($row['nazwa_firmy'] ?? ''));
        return $nipOk || $name !== '';
    }));
}

function runScan(PDO $pdo, bool $fix): array
{
    $candidates = fetchCandidates($pdo);
    $writer = new CompanyWriter($pdo);
    $fixed = 0;
    $unresolved = [];

    if ($fix) {
        foreach ($candidates as $row) {
            $clientId = (int)$row['id'];
            $nipDigits = preg_replace('/\D+/', '', (string)($row['nip'] ?? '')) ?? '';
            if (strlen($nipDigits) !== 10) {
                $unresolved[] = $row;
                continue;
            }
            $payload = [
                'nip' => $nipDigits,
                'name_full' => $row['nazwa_firmy'] ?? null,
            ];
            $res = $writer->upsertForClient($clientId, $payload, 0, 'manual');
            if ($res['ok'] && !empty($res['company_id'])) {
                $fixed++;
                logScan('fixed client_id=' . $clientId . ' nip=' . $nipDigits . ' company_id=' . $res['company_id']);
            } else {
                $unresolved[] = $row;
                logScan('unresolved client_id=' . $clientId . ' nip=' . $nipDigits);
            }
        }
    }

    return [
        'count' => count($candidates),
        'fixed' => $fixed,
        'unresolved' => $unresolved,
        'sample' => array_slice($candidates, 0, 10),
    ];
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $fix = in_array('--fix=1', $argv, true) || in_array('--fix', $argv, true);
    $result = runScan($pdo, $fix);
    echo json_encode(['fix' => $fix] + $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}
