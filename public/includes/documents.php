<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/auth.php';

const DEFAULT_DOC_PREFIX = 'AM/';

function loadTcpdfLibrary(): void {
    if (class_exists('TCPDF')) {
        return;
    }
    $publicDir = dirname(__DIR__);
    $autoloadCandidates = [
        $publicDir . '/../vendor/autoload.php',
        $publicDir . '/vendor/autoload.php',
    ];
    foreach ($autoloadCandidates as $candidate) {
        if (file_exists($candidate)) {
            require_once $candidate;
            if (class_exists('TCPDF')) {
                return;
            }
        }
    }
    $tcpdfCandidates = [
        $publicDir . '/../vendor/tecnickcom/tcpdf/src/tcpdf.php',
        $publicDir . '/../vendor/tecnickcom/tcpdf/tcpdf.php',
        $publicDir . '/../vendor/tecnickcom/tcpdf/src/TCPDF.php',
        $publicDir . '/../vendor/tecnickcom/tcpdf/TCPDF.php',
        $publicDir . '/vendor/tecnickcom/tcpdf/src/tcpdf.php',
        $publicDir . '/vendor/tecnickcom/tcpdf/tcpdf.php',
        $publicDir . '/vendor/tecnickcom/tcpdf/src/TCPDF.php',
        $publicDir . '/vendor/tecnickcom/tcpdf/TCPDF.php',
        $publicDir . '/../tcpdf/tcpdf.php',
        $publicDir . '/tcpdf/tcpdf.php',
    ];
    foreach ($tcpdfCandidates as $path) {
        if (file_exists($path)) {
            require_once $path;
            if (class_exists('TCPDF')) {
                return;
            }
        }
    }
}

function loadDompdfLibrary(): void {
    if (class_exists('\\Dompdf\\Dompdf')) {
        return;
    }
    $publicDir = dirname(__DIR__);
    $candidates = [
        $publicDir . '/../vendor/autoload.php',
        $publicDir . '/vendor/autoload.php',
        $publicDir . '/../vendor/dompdf/dompdf/src/Dompdf.php',
        $publicDir . '/vendor/dompdf/dompdf/src/Dompdf.php',
    ];
    foreach ($candidates as $candidate) {
        if (file_exists($candidate)) {
            require_once $candidate;
            if (class_exists('\\Dompdf\\Dompdf')) {
                return;
            }
        }
    }
}

function fetchSystemConfigSafe(PDO $pdo): array {
    try {
        $stmt = $pdo->query("SELECT * FROM konfiguracja_systemu WHERE id = 1 LIMIT 1");
        return $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        error_log('documents: konfiguracja_systemu fetch failed: ' . $e->getMessage());
        return [];
    }
}

function resolveDocumentsStoragePath(array $config): string {
    $path = trim((string)($config['documents_storage_path'] ?? ''));
    if ($path === '') {
        $path = dirname(__DIR__, 2) . '/storage/docs';
    } elseif ($path[0] !== '/' && !preg_match('/^[A-Za-z]:\\\\/', $path)) {
        $path = dirname(__DIR__, 2) . '/' . ltrim($path, '/');
    }
    return rtrim($path, "/\\") . DIRECTORY_SEPARATOR;
}

function ensureDocumentsStorageDir(string $dir): bool {
    if (is_dir($dir)) {
        return true;
    }
    return @mkdir($dir, 0775, true) || is_dir($dir);
}

function renderDocTemplate(string $templatePath, array $data): string {
    if (!file_exists($templatePath)) {
        throw new RuntimeException('Brak szablonu dokumentu: ' . $templatePath);
    }
    extract($data, EXTR_SKIP);
    ob_start();
    require $templatePath;
    return (string)ob_get_clean();
}

function formatDateForDoc(?string $date, string $fallback = ''): string {
    if (!$date) {
        return $fallback;
    }
    $dt = DateTime::createFromFormat('Y-m-d', $date) ?: DateTime::createFromFormat('Y-m-d H:i:s', $date);
    if (!$dt) {
        return $date;
    }
    return $dt->format('d.m.Y');
}

function buildDocumentNumber(PDO $pdo, string $prefix): string {
    $prefix = trim($prefix);
    if ($prefix === '') {
        $prefix = DEFAULT_DOC_PREFIX;
    }
    $year = (int)date('Y');

    try {
        $pdo->beginTransaction();
        $ensure = $pdo->prepare('INSERT INTO numeracja_dokumentow (year, last_number) VALUES (:year, 0)
            ON DUPLICATE KEY UPDATE last_number = last_number');
        $ensure->execute([':year' => $year]);

        $stmt = $pdo->prepare('SELECT last_number FROM numeracja_dokumentow WHERE year = :year FOR UPDATE');
        $stmt->execute([':year' => $year]);
        $last = (int)($stmt->fetchColumn() ?? 0);
        $stmt->closeCursor();
        $next = $last + 1;
        $update = $pdo->prepare('UPDATE numeracja_dokumentow SET last_number = :num WHERE year = :year');
        $update->execute([':num' => $next, ':year' => $year]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw new RuntimeException('Nie udało się wygenerować numeru dokumentu.');
    }

    return sprintf('%s%d/%04d', $prefix, $year, $next);
}

function getUserLabel(array $user): string {
    $parts = [];
    if (!empty($user['imie'])) {
        $parts[] = trim((string)$user['imie']);
    }
    if (!empty($user['nazwisko'])) {
        $parts[] = trim((string)$user['nazwisko']);
    }
    $label = trim(implode(' ', $parts));
    if ($label === '') {
        $label = trim((string)($user['login'] ?? ''));
    }
    return $label !== '' ? $label : 'Opiekun';
}

function canUserAccessClient(PDO $pdo, array $user, int $clientId): bool {
    if (normalizeRole($user) !== 'Handlowiec') {
        return true;
    }
    if ($clientId <= 0) {
        return false;
    }
    ensureClientLeadColumns($pdo);
    $cols = getTableColumns($pdo, 'klienci');
    if (!hasColumn($cols, 'owner_user_id')) {
        return false;
    }
    $select = ['owner_user_id'];
    if (hasColumn($cols, 'assigned_user_id')) {
        $select[] = 'assigned_user_id';
    }
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM klienci WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $clientId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return false;
    }
    $userId = (int)$user['id'];
    $ownerId = (int)($row['owner_user_id'] ?? 0);
    $assignedId = hasColumn($cols, 'assigned_user_id') ? (int)($row['assigned_user_id'] ?? 0) : 0;
    return ($ownerId > 0 && $ownerId === $userId) || ($assignedId > 0 && $assignedId === $userId);
}

function canUserAccessLead(PDO $pdo, array $user, int $leadId): bool {
    if (normalizeRole($user) !== 'Handlowiec') {
        return true;
    }
    if ($leadId <= 0) {
        return false;
    }
    ensureLeadColumns($pdo);
    $cols = getTableColumns($pdo, 'leady');
    if (!hasColumn($cols, 'owner_user_id')) {
        return false;
    }
    $select = ['owner_user_id'];
    if (hasColumn($cols, 'assigned_user_id')) {
        $select[] = 'assigned_user_id';
    }
    if (hasColumn($cols, 'client_id')) {
        $select[] = 'client_id';
    }
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM leady WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $leadId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return false;
    }
    $userId = (int)$user['id'];
    $ownerId = (int)($row['owner_user_id'] ?? 0);
    $assignedId = hasColumn($cols, 'assigned_user_id') ? (int)($row['assigned_user_id'] ?? 0) : 0;
    if (($ownerId > 0 && $ownerId === $userId) || ($assignedId > 0 && $assignedId === $userId)) {
        return true;
    }
    if ($ownerId <= 0 && $assignedId <= 0) {
        return true;
    }
    $leadClientId = hasColumn($cols, 'client_id') ? (int)($row['client_id'] ?? 0) : 0;
    if ($leadClientId > 0 && canUserAccessClient($pdo, $user, $leadClientId)) {
        return true;
    }
    return false;
}

function resolveClientIdForCampaign(PDO $pdo, int $kampaniaId): int {
    if ($kampaniaId <= 0 || !tableExists($pdo, 'kampanie')) {
        return 0;
    }
    $cols = getTableColumns($pdo, 'kampanie');
    if (!$cols) {
        return 0;
    }
    $select = [];
    if (hasColumn($cols, 'klient_id')) {
        $select[] = 'klient_id';
    }
    if (hasColumn($cols, 'source_lead_id')) {
        $select[] = 'source_lead_id';
    }
    if (hasColumn($cols, 'klient_nazwa')) {
        $select[] = 'klient_nazwa';
    }
    if (!$select) {
        return 0;
    }
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM kampanie WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $kampaniaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return 0;
    }
    $clientId = hasColumn($cols, 'klient_id') ? (int)($row['klient_id'] ?? 0) : 0;
    if ($clientId > 0) {
        return $clientId;
    }
    $leadId = hasColumn($cols, 'source_lead_id') ? (int)($row['source_lead_id'] ?? 0) : 0;
    if ($leadId > 0 && tableExists($pdo, 'leady')) {
        ensureLeadColumns($pdo);
        $leadCols = getTableColumns($pdo, 'leady');
        if (hasColumn($leadCols, 'client_id')) {
            $stmtLead = $pdo->prepare('SELECT client_id FROM leady WHERE id = :id LIMIT 1');
            $stmtLead->execute([':id' => $leadId]);
            $leadClientId = (int)($stmtLead->fetchColumn() ?? 0);
            if ($leadClientId > 0) {
                return $leadClientId;
            }
        }
    }

    if (tableExists($pdo, 'klienci') && hasColumn($cols, 'klient_nazwa')) {
        $clientName = trim((string)($row['klient_nazwa'] ?? ''));
        if ($clientName !== '') {
            $stmtByName = $pdo->prepare('SELECT id FROM klienci WHERE TRIM(COALESCE(nazwa_firmy, \'\')) = :name LIMIT 2');
            $stmtByName->execute([':name' => $clientName]);
            $matches = $stmtByName->fetchAll(PDO::FETCH_ASSOC) ?: [];
            if (count($matches) === 1) {
                return (int)($matches[0]['id'] ?? 0);
            }
        }
    }

    return 0;
}

function canUserAccessKampania(PDO $pdo, array $user, int $kampaniaId): bool {
    if (normalizeRole($user) !== 'Handlowiec') {
        return true;
    }
    if ($kampaniaId <= 0) {
        return false;
    }
    ensureKampanieOwnershipColumns($pdo);
    $cols = getTableColumns($pdo, 'kampanie');
    if (!hasColumn($cols, 'owner_user_id')) {
        return false;
    }
    $select = ['owner_user_id'];
    if (hasColumn($cols, 'klient_id')) {
        $select[] = 'klient_id';
    }
    if (hasColumn($cols, 'source_lead_id')) {
        $select[] = 'source_lead_id';
    }
    $stmt = $pdo->prepare('SELECT ' . implode(', ', $select) . ' FROM kampanie WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $kampaniaId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) {
        return false;
    }
    $userId = (int)$user['id'];
    $ownerId = (int)($row['owner_user_id'] ?? 0);
    if ($ownerId > 0 && $ownerId === $userId) {
        return true;
    }

    $clientId = hasColumn($cols, 'klient_id') ? (int)($row['klient_id'] ?? 0) : 0;
    if ($clientId > 0 && canUserAccessClient($pdo, $user, $clientId)) {
        return true;
    }

    $leadId = hasColumn($cols, 'source_lead_id') ? (int)($row['source_lead_id'] ?? 0) : 0;
    if ($leadId > 0 && canUserAccessLead($pdo, $user, $leadId)) {
        return true;
    }

    if ($clientId <= 0) {
        $derivedClientId = resolveClientIdForCampaign($pdo, $kampaniaId);
        if ($derivedClientId > 0 && canUserAccessClient($pdo, $user, $derivedClientId)) {
            return true;
        }
    }

    return false;
}

function generateDocumentPdfBinary(string $templatePath, array $data, string $title): string {
    loadTcpdfLibrary();
    if (class_exists('TCPDF')) {
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Adds Manager');
        $pdf->SetAuthor($data['company']['name'] ?? 'Adds Manager');
        $pdf->SetTitle($title);
        $pdf->SetMargins(15, 15, 15);
        $pdf->SetAutoPageBreak(true, 15);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 10);

        $html = renderDocTemplate($templatePath, $data);
        $pdf->writeHTML($html, true, false, true, false, '');
        return (string)$pdf->Output('', 'S');
    }

    $html = renderDocTemplate($templatePath, $data);
    loadDompdfLibrary();
    if (!class_exists('\\Dompdf\\Dompdf')) {
        throw new RuntimeException('Brak biblioteki TCPDF/Dompdf.');
    }

    $dompdf = new \Dompdf\Dompdf();
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->render();
    return $dompdf->output();
}

function sanitizeFilename(string $value): string {
    $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value);
    $value = trim($value, '-');
    return $value !== '' ? $value : 'document';
}

function saveDocumentPdf(array $config, string $docType, string $docNumber, string $pdfBinary): array {
    $storageDir = resolveDocumentsStoragePath($config);
    if (!ensureDocumentsStorageDir($storageDir)) {
        throw new RuntimeException('Nie udało się utworzyć katalogu dokumentów.');
    }

    $safeNumber = sanitizeFilename($docNumber);
    $timestamp = date('Ymd_His');
    $storedFilename = strtolower($docType) . '_' . $safeNumber . '_' . $timestamp . '.pdf';
    $fullPath = $storageDir . $storedFilename;
    if (file_put_contents($fullPath, $pdfBinary) === false) {
        throw new RuntimeException('Nie udało się zapisać pliku PDF.');
    }
    return [$storedFilename, $fullPath];
}

function generateDocument(PDO $pdo, array $user, string $docType, int $clientId, ?int $kampaniaId, array $options = []): array {
    ensureSystemConfigColumns($pdo);
    ensureDocumentsTables($pdo);

    if ($clientId <= 0 && $kampaniaId && $kampaniaId > 0) {
        $resolvedClientId = resolveClientIdForCampaign($pdo, (int)$kampaniaId);
        if ($resolvedClientId > 0) {
            $clientId = $resolvedClientId;
        }
    }

    if ($docType === 'umowa' && (!$kampaniaId || $kampaniaId <= 0)) {
        return ['success' => false, 'error' => 'Wybierz kampanię dla umowy reklamowej.'];
    }
    if ($docType === 'aneks' && (!$kampaniaId || $kampaniaId <= 0)) {
        return ['success' => false, 'error' => 'Brak kampanii do aneksu.'];
    }

    if (!canUserAccessClient($pdo, $user, $clientId)) {
        return ['success' => false, 'error' => 'Brak dostępu do klienta.'];
    }
    if ($kampaniaId && !canUserAccessKampania($pdo, $user, (int)$kampaniaId)) {
        return ['success' => false, 'error' => 'Brak dostępu do kampanii.'];
    }

    $clientStmt = $pdo->prepare('SELECT * FROM klienci WHERE id = :id');
    $clientStmt->execute([':id' => $clientId]);
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) {
        return ['success' => false, 'error' => 'Nie znaleziono klienta.'];
    }

    $kampania = null;
    if ($kampaniaId) {
        $stmt = $pdo->prepare('SELECT * FROM kampanie WHERE id = :id');
        $stmt->execute([':id' => $kampaniaId]);
        $kampania = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$kampania) {
            return ['success' => false, 'error' => 'Nie znaleziono kampanii.'];
        }
        if (!empty($kampania['klient_id']) && (int)$kampania['klient_id'] !== $clientId) {
            return ['success' => false, 'error' => 'Kampania nie jest powiązana z klientem.'];
        }
        $kampania['data_start_fmt'] = formatDateForDoc($kampania['data_start'] ?? '');
        $kampania['data_koniec_fmt'] = formatDateForDoc($kampania['data_koniec'] ?? '');
    }

    $config = fetchSystemConfigSafe($pdo);
    $prefix = trim((string)($config['documents_number_prefix'] ?? DEFAULT_DOC_PREFIX));
    if ($prefix === '') {
        $prefix = DEFAULT_DOC_PREFIX;
    }
    $docNumber = buildDocumentNumber($pdo, $prefix);
    $docDate = date('d.m.Y');

    $company = [
        'name'    => trim((string)($config['company_name'] ?? '')),
        'address' => trim((string)($config['company_address'] ?? '')),
        'nip'     => trim((string)($config['company_nip'] ?? '')),
        'email'   => trim((string)($config['company_email'] ?? '')),
        'phone'   => trim((string)($config['company_phone'] ?? '')),
    ];

    $data = [
        'company' => $company,
        'client' => $client,
        'campaign' => $kampania,
        'docNumber' => $docNumber,
        'docDate' => $docDate,
        'user' => $user,
        'userLabel' => getUserLabel($user),
    ];

    $baseTemplateDir = dirname(__DIR__, 2) . '/templates/docs';
    if ($docType === 'umowa') {
        $templatePath = $baseTemplateDir . '/umowa_reklamowa.php';
        $title = 'Umowa reklamowa';
    } else {
        $templatePath = $baseTemplateDir . '/aneks_terminowy.php';
        $title = 'Aneks terminowy';
        $startDate = (string)($options['start_date'] ?? '');
        $startDate = preg_replace('/[^0-9\-]/', '', $startDate);
        if ($startDate === '' || !DateTime::createFromFormat('Y-m-d', $startDate)) {
            return ['success' => false, 'error' => 'Podaj poprawną datę startu aneksu (YYYY-MM-DD).'];
        }
        $startDt = new DateTime($startDate);
        $endDt = (clone $startDt)->modify('+12 months')->modify('-1 day');
        $data['aneks_start_date'] = $startDt->format('d.m.Y');
        $data['aneks_end_date'] = $endDt->format('d.m.Y');
    }

    try {
        $pdfBinary = generateDocumentPdfBinary($templatePath, $data, $title);
        [$storedFilename] = saveDocumentPdf($config, $docType, $docNumber, $pdfBinary);
    } catch (Throwable $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }

    $originalFilename = $title . '_' . $docNumber . '.pdf';
    $sha = hash('sha256', $pdfBinary);

    try {
        $stmt = $pdo->prepare("INSERT INTO dokumenty
            (doc_type, doc_number, client_id, kampania_id, created_by_user_id, stored_filename, original_filename, sha256)
            VALUES (:type, :num, :client_id, :kampania_id, :user_id, :stored, :original, :sha)");
        $stmt->execute([
            ':type' => $docType,
            ':num' => $docNumber,
            ':client_id' => $clientId,
            ':kampania_id' => $kampaniaId,
            ':user_id' => (int)$user['id'],
            ':stored' => $storedFilename,
            ':original' => $originalFilename,
            ':sha' => $sha,
        ]);
        $docId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Nie udało się zapisać rekordu dokumentu.'];
    }

    return [
        'success' => true,
        'doc_id' => $docId,
        'doc_number' => $docNumber,
        'original_filename' => $originalFilename,
    ];
}
