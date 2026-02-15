<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/gus_config.php';
require_once __DIR__ . '/gus_snapshots.php';
require_once __DIR__ . '/gus_validation.php';

function gusCircuitStatePath(): string
{
    $storageDir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0775, true);
    }
    return $storageDir . '/gus_circuit.json';
}

function gusCircuitLogPath(): string
{
    $storageDir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0775, true);
    }
    return $storageDir . '/gus_circuit.log';
}

function gusCircuitLogOpen(int $errorCount, int $openUntil): void
{
    $payload = [
        'ts' => date('c'),
        'event' => 'circuit_open',
        'error_count' => $errorCount,
        'open_until' => date('c', $openUntil),
    ];
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return;
    }
    @file_put_contents(gusCircuitLogPath(), $line . PHP_EOL, FILE_APPEND);
}

function gusCircuitLoad(): array
{
    $path = gusCircuitStatePath();
    if (!is_file($path)) {
        return ['open_until' => 0, 'errors' => []];
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return ['open_until' => 0, 'errors' => []];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return ['open_until' => 0, 'errors' => []];
    }
    $data['open_until'] = (int)($data['open_until'] ?? 0);
    $data['errors'] = array_filter((array)($data['errors'] ?? []), 'is_numeric');
    return $data;
}

function gusCircuitSave(array $state): void
{
    $path = gusCircuitStatePath();
    $state['open_until'] = (int)($state['open_until'] ?? 0);
    $state['errors'] = array_values(array_filter((array)($state['errors'] ?? []), 'is_numeric'));
    $payload = json_encode($state, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        return;
    }
    @file_put_contents($path, $payload);
}

function gusCircuitIsOpen(int $threshold, int $windowSec, int $cooldownSec): bool
{
    $state = gusCircuitLoad();
    $now = time();
    if (!empty($state['open_until']) && $state['open_until'] > $now) {
        return true;
    }

    // prune old errors
    $minTs = $now - $windowSec;
    $state['errors'] = array_values(array_filter($state['errors'], static function ($ts) use ($minTs) {
        return (int)$ts >= $minTs;
    }));
    $state['open_until'] = 0;
    gusCircuitSave($state);
    return false;
}

function gusCircuitRecordFailure(int $threshold, int $windowSec, int $cooldownSec): void
{
    $state = gusCircuitLoad();
    $now = time();
    $state['errors'][] = $now;
    $minTs = $now - $windowSec;
    $state['errors'] = array_values(array_filter($state['errors'], static function ($ts) use ($minTs) {
        return (int)$ts >= $minTs;
    }));
    if (count($state['errors']) >= $threshold) {
        $wasOpen = !empty($state['open_until']) && $state['open_until'] > $now;
        $state['open_until'] = $now + $cooldownSec;
        if (!$wasOpen) {
            gusCircuitLogOpen(count($state['errors']), $state['open_until']);
        }
    }
    gusCircuitSave($state);
}

function gusHasSoapFaultResponse(?string $xml): bool
{
    if ($xml === null || $xml === '') {
        return false;
    }
    return stripos($xml, ':Fault') !== false || stripos($xml, '<Fault') !== false;
}

function gusNormalizeIdentifier(?string $value): string
{
    $value = preg_replace('/\D+/', '', (string)$value);
    return $value ?? '';
}

function gusLogError(PDO $pdo, ?int $userId, string $requestId, string $message): void
{
    try {
        ensureIntegrationsLogsTable($pdo);
        $stmt = $pdo->prepare("INSERT INTO integrations_logs (user_id, type, request_id, message) VALUES (?, 'gus', ?, ?)");
        $stmt->execute([$userId, $requestId, $message]);
    } catch (Throwable $e) {
        error_log('gus_service: log failed: ' . $e->getMessage());
    }
}

function gusNormalizeErrorClass(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }

    $lower = strtolower($value);
    switch ($lower) {
        case 'transport':
        case 'circuit_breaker':
            return 'TRANSPORT';
        case 'soap_fault':
            return 'SOAP_FAULT';
        case 'http_4xx':
            return 'HTTP_4XX';
        case 'http_5xx':
            return 'HTTP_5XX';
        case 'validation':
            return 'VALIDATION';
    }

    return strtoupper($value);
}

function gusMapErrorClass(array $response, string $default): string
{
    $normalized = gusNormalizeErrorClass($response['error_class'] ?? '') ?? gusNormalizeErrorClass($default);
    return $normalized ?? 'TRANSPORT';
}

function gusIsCircuitOpenMessage(?string $message): bool
{
    $message = trim((string)$message);
    return $message !== '' && stripos($message, 'Usługa GUS chwilowo niedostępna') !== false;
}

function gusExtractSoapFault(?string $xml): array
{
    $result = ['faultcode' => null, 'faultstring' => null];
    if ($xml === null || $xml === '') {
        return $result;
    }

    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    if (!$doc) {
        return $result;
    }

    $nodes = $doc->xpath('//*[local-name()="Fault"]');
    if (!$nodes || !isset($nodes[0])) {
        return $result;
    }

    $fault = $nodes[0];
    $faultCode = '';
    $faultString = '';

    if (isset($fault->faultcode)) {
        $faultCode = (string)$fault->faultcode;
    }
    if (isset($fault->faultstring)) {
        $faultString = (string)$fault->faultstring;
    }
    if ($faultCode === '' && isset($fault->Code) && isset($fault->Code->Value)) {
        $faultCode = (string)$fault->Code->Value;
    }
    if ($faultString === '' && isset($fault->Reason) && isset($fault->Reason->Text)) {
        $faultString = (string)$fault->Reason->Text;
    }

    if ($faultCode === '') {
        $codeNodes = $fault->xpath('.//*[local-name()="faultcode" or local-name()="Value"]');
        if ($codeNodes && isset($codeNodes[0])) {
            $faultCode = (string)$codeNodes[0];
        }
    }
    if ($faultString === '') {
        $textNodes = $fault->xpath('.//*[local-name()="faultstring" or local-name()="Text"]');
        if ($textNodes && isset($textNodes[0])) {
            $faultString = (string)$textNodes[0];
        }
    }

    $faultCode = trim($faultCode);
    $faultString = trim($faultString);
    $result['faultcode'] = $faultCode !== '' ? $faultCode : null;
    $result['faultstring'] = $faultString !== '' ? $faultString : null;
    return $result;
}

function gusCacheGet(PDO $pdo, ?string $nip, ?string $regon, int $ttlDays): ?array
{
    if ($ttlDays <= 0) {
        return null;
    }
    ensureGusCacheTable($pdo);
    $nip = $nip ? gusNormalizeIdentifier($nip) : null;
    $regon = $regon ? gusNormalizeIdentifier($regon) : null;
    if (!$nip && !$regon) {
        return null;
    }

    $sql = "SELECT data_json, fetched_at FROM gus_cache WHERE ";
    $params = [];
    $parts = [];
    if ($nip) {
        $parts[] = 'nip = ?';
        $params[] = $nip;
    }
    if ($regon) {
        $parts[] = 'regon = ?';
        $params[] = $regon;
    }
    $sql .= implode(' OR ', $parts) . " ORDER BY fetched_at DESC LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    $fetchedAt = new DateTime($row['fetched_at']);
    $ttl = max(1, $ttlDays);
    $expiry = (clone $fetchedAt)->modify('+' . $ttl . ' days');
    if ($expiry < new DateTime()) {
        return null;
    }
    $data = json_decode($row['data_json'], true);
    return is_array($data) ? $data : null;
}

function gusCacheSave(PDO $pdo, ?string $nip, ?string $regon, array $data, string $source = 'gus'): void
{
    ensureGusCacheTable($pdo);
    $nip = $nip ? gusNormalizeIdentifier($nip) : null;
    $regon = $regon ? gusNormalizeIdentifier($regon) : null;
    $stmt = $pdo->prepare("INSERT INTO gus_cache (nip, regon, data_json, source) VALUES (?, ?, ?, ?)");
    $stmt->execute([$nip, $regon, json_encode($data, JSON_UNESCAPED_UNICODE), $source]);
}

function gusSoapRequest(string $url, string $action, string $xml, ?string $sid = null): array
{
    $connectTimeout = gusEnvInt('GUS_CONNECT_TIMEOUT', 5);
    $readTimeout = gusEnvInt('GUS_READ_TIMEOUT', 10);
    $retryMax = max(0, min(2, gusEnvInt('GUS_RETRY_MAX', 2)));
    $retryBackoffMs = max(0, gusEnvInt('GUS_RETRY_BACKOFF_MS', 500));
    $circuitThreshold = max(1, gusEnvInt('GUS_CIRCUIT_ERROR_THRESHOLD', 5));
    $circuitWindow = max(60, gusEnvInt('GUS_CIRCUIT_WINDOW_SEC', 300));
    $circuitCooldown = max(30, gusEnvInt('GUS_CIRCUIT_COOLDOWN_SEC', 120));

    if (gusCircuitIsOpen($circuitThreshold, $circuitWindow, $circuitCooldown)) {
        return [
            'ok' => false,
            'error' => 'Usługa GUS chwilowo niedostępna — spróbuj za chwilę.',
            'http_code' => 0,
            'attempt_no' => 0,
            'latency_ms' => 0,
            'error_class' => 'TRANSPORT',
            'faultcode' => null,
            'faultstring' => null,
        ];
    }

    if (!function_exists('curl_init')) {
        return [
            'ok' => false,
            'error' => 'Brak obsługi cURL na serwerze.',
            'http_code' => 0,
            'attempt_no' => 0,
            'latency_ms' => 0,
            'error_class' => 'TRANSPORT',
            'faultcode' => null,
            'faultstring' => null,
        ];
    }

    $maxAttempts = $retryMax + 1;
    $lastError = '';
    $lastHttpCode = 0;
    $lastResponse = null;
    $lastErrorClass = null;
    $lastFaultCode = null;
    $lastFaultString = null;
    $latencyMs = 0;
    $lastAttemptNo = 0;

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $lastAttemptNo = $attempt;
        $ch = curl_init($url);
        if (!$ch) {
            $lastError = 'Nie udało się zainicjować połączenia z GUS.';
            $lastErrorClass = 'TRANSPORT';
            $lastHttpCode = 0;
            break;
        }

        $headers = [
            'Content-Type: application/soap+xml; charset=utf-8',
            'User-Agent: AddsManager/1.0',
            'SOAPAction: "' . $action . '"',
        ];
        if ($sid) {
            $headers[] = 'sid: ' . $sid;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => $readTimeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
        ]);

        $start = microtime(true);
        $response = curl_exec($ch);
        $latencyMs = (int)round((microtime(true) - $start) * 1000);
        if ($response === false) {
            $lastError = 'Błąd połączenia z GUS: ' . curl_error($ch);
            $lastHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $lastErrorClass = 'TRANSPORT';
            curl_close($ch);
            if ($attempt < $maxAttempts) {
                $backoffMs = (int)round($retryBackoffMs * (2 ** ($attempt - 1)));
                usleep($backoffMs * 1000);
                continue;
            }
            break;
        }
        if ($response === false) {
            // No need to parse SOAP when no response
        }
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $lastResponse = $response;
        $lastHttpCode = $httpCode;
        $faultInfo = gusExtractSoapFault($response);
        $hasFault = !empty($faultInfo['faultcode']) || !empty($faultInfo['faultstring']) || gusHasSoapFaultResponse($response);
        $lastFaultCode = $faultInfo['faultcode'] ?? null;
        $lastFaultString = $faultInfo['faultstring'] ?? null;

        if ($httpCode >= 500) {
            $lastError = 'Serwer GUS zwrócił błąd HTTP ' . $httpCode . '.';
            $lastErrorClass = 'HTTP_5XX';
            if ($attempt < $maxAttempts) {
                $backoffMs = (int)round($retryBackoffMs * (2 ** ($attempt - 1)));
                usleep($backoffMs * 1000);
                continue;
            }
            break;
        }

        if ($httpCode >= 400) {
            $lastError = 'Serwer GUS zwrócił błąd HTTP ' . $httpCode . '.';
            $lastErrorClass = 'HTTP_4XX';
            break;
        }

        if ($hasFault) {
            $lastError = 'Błąd SOAP w odpowiedzi GUS.';
            $lastErrorClass = 'SOAP_FAULT';
            break;
        }

        return [
            'ok' => true,
            'response' => $response,
            'http_code' => $httpCode,
            'attempt_no' => $attempt,
            'latency_ms' => $latencyMs,
            'error_class' => null,
            'faultcode' => null,
            'faultstring' => null,
        ];
    }

    if ($lastErrorClass === 'TRANSPORT' || $lastErrorClass === 'HTTP_5XX') {
        gusCircuitRecordFailure($circuitThreshold, $circuitWindow, $circuitCooldown);
    }

    return [
        'ok' => false,
        'error' => $lastError !== '' ? $lastError : 'Błąd połączenia z GUS.',
        'http_code' => $lastHttpCode,
        'response' => $lastResponse,
        'attempt_no' => $lastAttemptNo,
        'latency_ms' => $latencyMs,
        'error_class' => $lastErrorClass ?? 'TRANSPORT',
        'faultcode' => $lastFaultCode,
        'faultstring' => $lastFaultString,
    ];
}

function gusExtractResultValue(string $xml, string $resultTag): ?string
{
    libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    if (!$doc) {
        return null;
    }
    $doc->registerXPathNamespace('soap', 'http://www.w3.org/2003/05/soap-envelope');
    $nodes = $doc->xpath('//soap:Body//*[local-name()="' . $resultTag . '"]');
    if (!$nodes || !isset($nodes[0])) {
        return null;
    }
    return (string)$nodes[0];
}

function gusBuildLoginEnvelope(string $apiKey): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="http://CIS/BIR/PUBL/2014/07">'
        . '<soap:Header/>'
        . '<soap:Body>'
        . '<ns:Zaloguj><ns:pKluczUzytkownika>' . htmlspecialchars($apiKey, ENT_XML1) . '</ns:pKluczUzytkownika></ns:Zaloguj>'
        . '</soap:Body>'
        . '</soap:Envelope>';
}

function gusBuildSearchEnvelope(string $field, string $value): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="http://CIS/BIR/PUBL/2014/07">'
        . '<soap:Header/>'
        . '<soap:Body>'
        . '<ns:DaneSzukajPodmioty>'
        . '<ns:pParametryWyszukiwania>'
        . '<ns:' . $field . '>' . htmlspecialchars($value, ENT_XML1) . '</ns:' . $field . '>'
        . '</ns:pParametryWyszukiwania>'
        . '</ns:DaneSzukajPodmioty>'
        . '</soap:Body>'
        . '</soap:Envelope>';
}

function gusParseSearchResult(?string $resultXml): ?array
{
    if (!$resultXml) {
        return null;
    }
    $decoded = html_entity_decode($resultXml, ENT_QUOTES | ENT_XML1, 'UTF-8');
    libxml_use_internal_errors(true);
    $dataXml = simplexml_load_string($decoded);
    if (!$dataXml) {
        return null;
    }
    $items = $dataXml->xpath('//dane');
    if (!$items || !isset($items[0])) {
        return null;
    }
    $item = $items[0];
    $data = [];
    foreach ($item->children() as $child) {
        $data[$child->getName()] = (string)$child;
    }
    return $data;
}

function gusStandardizeCompany(array $raw): array
{
    $streetParts = [];
    if (!empty($raw['Ulica'])) {
        $streetParts[] = $raw['Ulica'];
    }
    if (!empty($raw['NrNieruchomosci'])) {
        $streetParts[] = $raw['NrNieruchomosci'];
    }
    if (!empty($raw['NrLokalu'])) {
        $streetParts[] = 'lok. ' . $raw['NrLokalu'];
    }
    $street = trim(implode(' ', $streetParts));

    return [
        'name' => $raw['Nazwa'] ?? '',
        'nip' => $raw['Nip'] ?? '',
        'regon' => $raw['Regon'] ?? '',
        'krs' => $raw['Krs'] ?? '',
        'address_street' => $street,
        'address_postal' => $raw['KodPocztowy'] ?? '',
        'address_city' => $raw['Miejscowosc'] ?? '',
        'legal_form' => $raw['FormaPrawna_Nazwa'] ?? ($raw['FormaPrawna'] ?? ''),
    ];
}

function gusResolveFallbackIdentifiers(PDO $pdo, string $nip, ?int $companyId = null): array
{
    $nip = normalizeNip($nip);
    if ($nip === '' && (!$companyId || $companyId <= 0)) {
        return ['regon' => '', 'krs' => ''];
    }
    $regon = '';
    $krs = '';

    if (tableExists($pdo, 'companies')) {
        try {
            if ($companyId && $companyId > 0) {
                $stmt = $pdo->prepare('SELECT regon, krs FROM companies WHERE id = :id');
                $stmt->execute([':id' => $companyId]);
            } else {
                $stmt = $pdo->prepare('SELECT regon, krs FROM companies WHERE nip = :nip LIMIT 1');
                $stmt->execute([':nip' => $nip]);
            }
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $regon = isset($row['regon']) ? normalizeRegon((string)$row['regon']) : '';
            $krs = isset($row['krs']) ? normalizeKrs((string)$row['krs']) : '';
        } catch (Throwable $e) {
            $regon = '';
            $krs = '';
        }
    }

    if ($regon !== '' || $krs !== '') {
        return ['regon' => $regon, 'krs' => $krs];
    }

    if (!tableExists($pdo, 'klienci')) {
        return ['regon' => '', 'krs' => ''];
    }

    try {
        $stmt = $pdo->prepare('SELECT regon, krs FROM klienci WHERE nip = :nip LIMIT 1');
        $stmt->execute([':nip' => $nip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return ['regon' => '', 'krs' => ''];
    }

    $regon = isset($row['regon']) ? normalizeRegon((string)$row['regon']) : '';
    $krs = isset($row['krs']) ? normalizeKrs((string)$row['krs']) : '';
    return ['regon' => $regon, 'krs' => $krs];
}

function gusFetchCompanyByNip(PDO $pdo, string $nip, array $config, ?int $userId = null, ?int $companyId = null): array
{
    $requestId = uniqid('gus_', true);
    $runtime = gusResolveRuntimeConfig();
    $repo = new GusSnapshotRepository($pdo);
    $nip = normalizeNip($nip);
    $snapshotBase = [
        'company_id' => $companyId,
        'env' => (string)($runtime['environment'] ?? ''),
        'request_type' => 'nip',
        'request_value' => $nip,
        'report_type' => 'DaneSzukajPodmioty',
        'correlation_id' => $requestId,
    ];
    if (!isValidNip($nip)) {
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'validation',
            'error_message' => 'validation_failed',
            'error_class' => 'VALIDATION',
            'http_code' => null,
            'attempt_no' => 0,
            'latency_ms' => 0,
            'fault_code' => null,
            'fault_string' => null,
        ]);
        return ['success' => false, 'error' => 'Nieprawidłowy numer NIP.'];
    }

    $cacheTtl = (int)($config['cache_ttl_days'] ?? 30);
    $cache = gusCacheGet($pdo, $nip, null, $cacheTtl);
    if ($cache) {
        return ['success' => true, 'data' => $cache, 'cached' => true];
    }

    $apiKey = (string)($runtime['api_key'] ?? '');
    $url = (string)($runtime['endpoint'] ?? '');
    if (!$runtime['ok'] || $apiKey === '' || $url === '') {
        gusLogError($pdo, $userId, $requestId, 'Brak konfiguracji GUS w .env.');
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'config',
            'error_message' => 'Brak konfiguracji GUS (sprawdz .env).',
            'error_class' => 'VALIDATION',
            'http_code' => null,
            'attempt_no' => 0,
            'latency_ms' => 0,
            'fault_code' => null,
            'fault_string' => null,
        ]);
        return ['success' => false, 'error' => 'Brak konfiguracji GUS (sprawdz .env).'];
    }

    $login = gusSoapRequest($url, 'Zaloguj', gusBuildLoginEnvelope($apiKey));
    if (!$login['ok']) {
        gusLogError($pdo, $userId, $requestId, $login['error']);
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'login',
            'error_message' => $login['error'] ?? 'Błąd logowania do GUS.',
            'http_code' => $login['http_code'] ?? null,
            'attempt_no' => $login['attempt_no'] ?? null,
            'latency_ms' => $login['latency_ms'] ?? null,
            'error_class' => gusMapErrorClass($login, 'TRANSPORT'),
            'fault_code' => $login['faultcode'] ?? null,
            'fault_string' => $login['faultstring'] ?? null,
        ]);
        $msg = gusIsCircuitOpenMessage($login['error'] ?? '') ? (string)$login['error'] : 'Błąd logowania do GUS.';
        return ['success' => false, 'error' => $msg];
    }
    $sid = gusExtractResultValue($login['response'], 'ZalogujResult');
    if (!$sid) {
        gusLogError($pdo, $userId, $requestId, 'Brak SID w odpowiedzi GUS.');
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'login_sid',
            'error_message' => 'Brak SID w odpowiedzi GUS.',
            'attempt_no' => $login['attempt_no'] ?? null,
            'latency_ms' => $login['latency_ms'] ?? null,
            'error_class' => 'SOAP_FAULT',
            'fault_code' => $login['faultcode'] ?? null,
            'fault_string' => $login['faultstring'] ?? null,
        ]);
        return ['success' => false, 'error' => 'Błąd logowania do GUS.'];
    }

    $searchXml = gusBuildSearchEnvelope('Nip', $nip);
    $search = gusSoapRequest($url, 'DaneSzukajPodmioty', $searchXml, $sid);
    if (!$search['ok']) {
        gusLogError($pdo, $userId, $requestId, $search['error']);
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'search',
            'error_message' => $search['error'] ?? 'Błąd wyszukiwania w GUS.',
            'http_code' => $search['http_code'] ?? null,
            'raw_request' => $searchXml,
            'raw_response' => $search['response'] ?? null,
            'attempt_no' => $search['attempt_no'] ?? null,
            'latency_ms' => $search['latency_ms'] ?? null,
            'error_class' => gusMapErrorClass($search, 'TRANSPORT'),
            'fault_code' => $search['faultcode'] ?? null,
            'fault_string' => $search['faultstring'] ?? null,
        ]);
        $msg = gusIsCircuitOpenMessage($search['error'] ?? '') ? (string)$search['error'] : 'Błąd wyszukiwania w GUS.';
        return ['success' => false, 'error' => $msg];
    }
    $resultXml = gusExtractResultValue($search['response'], 'DaneSzukajPodmiotyResult');
    $raw = gusParseSearchResult($resultXml);
    if (!$raw) {
        $fallback = gusResolveFallbackIdentifiers($pdo, $nip, $companyId);
        $fallbackAttempts = [];
        if (!empty($fallback['regon']) && isValidRegon($fallback['regon'])) {
            $fallbackAttempts[] = ['type' => 'regon', 'value' => $fallback['regon']];
        }
        if (!empty($fallback['krs']) && isValidKrs($fallback['krs'])) {
            $fallbackAttempts[] = ['type' => 'krs', 'value' => $fallback['krs']];
        }

        foreach ($fallbackAttempts as $attempt) {
            if ($attempt['type'] === 'regon') {
                $alt = gusFetchCompanyByRegon($pdo, $attempt['value'], $config, $userId, $companyId);
            } else {
                $alt = gusFetchCompanyByKrs($pdo, $attempt['value'], $config, $userId, $companyId);
            }
            if (!empty($alt['success'])) {
                return $alt;
            }
        }

        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'not_found',
            'error_message' => 'Nie znaleziono podmiotu w GUS.',
            'http_code' => $search['http_code'] ?? null,
            'raw_request' => $searchXml,
            'raw_response' => $search['response'] ?? null,
            'attempt_no' => $search['attempt_no'] ?? null,
            'latency_ms' => $search['latency_ms'] ?? null,
            'error_class' => gusMapErrorClass($search, 'SOAP_FAULT'),
            'fault_code' => $search['faultcode'] ?? null,
            'fault_string' => $search['faultstring'] ?? null,
        ]);
        return ['success' => false, 'error' => 'Nie znaleziono podmiotu w GUS.'];
    }
    $data = gusStandardizeCompany($raw);
    gusCacheSave($pdo, $data['nip'] ?: $nip, $data['regon'] ?? null, $data);
    $repo->save($snapshotBase + [
        'ok' => true,
        'http_code' => $search['http_code'] ?? null,
        'raw_request' => $searchXml,
        'raw_response' => $search['response'] ?? null,
        'raw_parsed' => $data,
        'attempt_no' => $search['attempt_no'] ?? null,
        'latency_ms' => $search['latency_ms'] ?? null,
        'error_class' => null,
        'fault_code' => null,
        'fault_string' => null,
    ]);
    return ['success' => true, 'data' => $data, 'cached' => false];
}

function gusFetchCompanyByKrs(PDO $pdo, string $krs, array $config, ?int $userId = null, ?int $companyId = null): array
{
    $requestId = uniqid('gus_', true);
    $runtime = gusResolveRuntimeConfig();
    $repo = new GusSnapshotRepository($pdo);
    $krs = normalizeKrs($krs);
    $snapshotBase = [
        'company_id' => $companyId,
        'env' => (string)($runtime['environment'] ?? ''),
        'request_type' => 'krs',
        'request_value' => $krs,
        'report_type' => 'DaneSzukajPodmioty',
        'correlation_id' => $requestId,
    ];
    if (!isValidKrs($krs)) {
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'validation',
            'error_message' => 'validation_failed',
            'error_class' => 'VALIDATION',
            'http_code' => null,
            'attempt_no' => 0,
            'latency_ms' => 0,
            'fault_code' => null,
            'fault_string' => null,
        ]);
        return ['success' => false, 'error' => 'Nieprawidłowy numer KRS.'];
    }

    $apiKey = (string)($runtime['api_key'] ?? '');
    $url = (string)($runtime['endpoint'] ?? '');
    if (!$runtime['ok'] || $apiKey === '' || $url === '') {
        gusLogError($pdo, $userId, $requestId, 'Brak konfiguracji GUS w .env.');
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'config',
            'error_message' => 'Brak konfiguracji GUS (sprawdz .env).',
            'error_class' => 'VALIDATION',
            'http_code' => null,
            'attempt_no' => 0,
            'latency_ms' => 0,
            'fault_code' => null,
            'fault_string' => null,
        ]);
        return ['success' => false, 'error' => 'Brak konfiguracji GUS (sprawdz .env).'];
    }

    $login = gusSoapRequest($url, 'Zaloguj', gusBuildLoginEnvelope($apiKey));
    if (!$login['ok']) {
        gusLogError($pdo, $userId, $requestId, $login['error']);
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'login',
            'error_message' => $login['error'] ?? 'Błąd logowania do GUS.',
            'http_code' => $login['http_code'] ?? null,
            'attempt_no' => $login['attempt_no'] ?? null,
            'latency_ms' => $login['latency_ms'] ?? null,
            'error_class' => gusMapErrorClass($login, 'TRANSPORT'),
            'fault_code' => $login['faultcode'] ?? null,
            'fault_string' => $login['faultstring'] ?? null,
        ]);
        $msg = gusIsCircuitOpenMessage($login['error'] ?? '') ? (string)$login['error'] : 'Błąd logowania do GUS.';
        return ['success' => false, 'error' => $msg];
    }
    $sid = gusExtractResultValue($login['response'], 'ZalogujResult');
    if (!$sid) {
        gusLogError($pdo, $userId, $requestId, 'Brak SID w odpowiedzi GUS.');
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'login_sid',
            'error_message' => 'Brak SID w odpowiedzi GUS.',
            'attempt_no' => $login['attempt_no'] ?? null,
            'latency_ms' => $login['latency_ms'] ?? null,
            'error_class' => 'SOAP_FAULT',
            'fault_code' => $login['faultcode'] ?? null,
            'fault_string' => $login['faultstring'] ?? null,
        ]);
        return ['success' => false, 'error' => 'Błąd logowania do GUS.'];
    }

    $searchXml = gusBuildSearchEnvelope('Krs', $krs);
    $search = gusSoapRequest($url, 'DaneSzukajPodmioty', $searchXml, $sid);
    if (!$search['ok']) {
        gusLogError($pdo, $userId, $requestId, $search['error']);
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'search',
            'error_message' => $search['error'] ?? 'Błąd wyszukiwania w GUS.',
            'http_code' => $search['http_code'] ?? null,
            'raw_request' => $searchXml,
            'raw_response' => $search['response'] ?? null,
            'attempt_no' => $search['attempt_no'] ?? null,
            'latency_ms' => $search['latency_ms'] ?? null,
            'error_class' => gusMapErrorClass($search, 'TRANSPORT'),
            'fault_code' => $search['faultcode'] ?? null,
            'fault_string' => $search['faultstring'] ?? null,
        ]);
        $msg = gusIsCircuitOpenMessage($search['error'] ?? '') ? (string)$search['error'] : 'Błąd wyszukiwania w GUS.';
        return ['success' => false, 'error' => $msg];
    }
    $resultXml = gusExtractResultValue($search['response'], 'DaneSzukajPodmiotyResult');
    $raw = gusParseSearchResult($resultXml);
    if (!$raw) {
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'not_found',
            'error_message' => 'Nie znaleziono podmiotu w GUS.',
            'http_code' => $search['http_code'] ?? null,
            'raw_request' => $searchXml,
            'raw_response' => $search['response'] ?? null,
            'attempt_no' => $search['attempt_no'] ?? null,
            'latency_ms' => $search['latency_ms'] ?? null,
            'error_class' => gusMapErrorClass($search, 'SOAP_FAULT'),
            'fault_code' => $search['faultcode'] ?? null,
            'fault_string' => $search['faultstring'] ?? null,
        ]);
        return ['success' => false, 'error' => 'Nie znaleziono podmiotu w GUS.'];
    }
    $data = gusStandardizeCompany($raw);
    gusCacheSave($pdo, $data['nip'] ?? null, $data['regon'] ?? null, $data);
    $repo->save($snapshotBase + [
        'ok' => true,
        'http_code' => $search['http_code'] ?? null,
        'raw_request' => $searchXml,
        'raw_response' => $search['response'] ?? null,
        'raw_parsed' => $data,
        'attempt_no' => $search['attempt_no'] ?? null,
        'latency_ms' => $search['latency_ms'] ?? null,
        'error_class' => null,
        'fault_code' => null,
        'fault_string' => null,
    ]);
    return ['success' => true, 'data' => $data, 'cached' => false];
}

function gusFetchCompanyByRegon(PDO $pdo, string $regon, array $config, ?int $userId = null, ?int $companyId = null): array
{
    $requestId = uniqid('gus_', true);
    $runtime = gusResolveRuntimeConfig();
    $repo = new GusSnapshotRepository($pdo);
    $regon = normalizeRegon($regon);
    $snapshotBase = [
        'company_id' => $companyId,
        'env' => (string)($runtime['environment'] ?? ''),
        'request_type' => 'regon',
        'request_value' => $regon,
        'report_type' => 'DaneSzukajPodmioty',
        'correlation_id' => $requestId,
    ];
    if (!isValidRegon($regon)) {
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'validation',
            'error_message' => 'validation_failed',
            'error_class' => 'VALIDATION',
            'http_code' => null,
            'attempt_no' => 0,
            'latency_ms' => 0,
            'fault_code' => null,
            'fault_string' => null,
        ]);
        return ['success' => false, 'error' => 'Nieprawidłowy numer REGON.'];
    }

    $cacheTtl = (int)($config['cache_ttl_days'] ?? 30);
    $cache = gusCacheGet($pdo, null, $regon, $cacheTtl);
    if ($cache) {
        return ['success' => true, 'data' => $cache, 'cached' => true];
    }

    $apiKey = (string)($runtime['api_key'] ?? '');
    $url = (string)($runtime['endpoint'] ?? '');
    if (!$runtime['ok'] || $apiKey === '' || $url === '') {
        gusLogError($pdo, $userId, $requestId, 'Brak konfiguracji GUS w .env.');
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'config',
            'error_message' => 'Brak konfiguracji GUS (sprawdz .env).',
            'error_class' => 'VALIDATION',
            'http_code' => null,
            'attempt_no' => 0,
            'latency_ms' => 0,
            'fault_code' => null,
            'fault_string' => null,
        ]);
        return ['success' => false, 'error' => 'Brak konfiguracji GUS (sprawdz .env).'];
    }

    $login = gusSoapRequest($url, 'Zaloguj', gusBuildLoginEnvelope($apiKey));
    if (!$login['ok']) {
        gusLogError($pdo, $userId, $requestId, $login['error']);
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'login',
            'error_message' => $login['error'] ?? 'Błąd logowania do GUS.',
            'http_code' => $login['http_code'] ?? null,
            'attempt_no' => $login['attempt_no'] ?? null,
            'latency_ms' => $login['latency_ms'] ?? null,
            'error_class' => gusMapErrorClass($login, 'TRANSPORT'),
            'fault_code' => $login['faultcode'] ?? null,
            'fault_string' => $login['faultstring'] ?? null,
        ]);
        $msg = gusIsCircuitOpenMessage($login['error'] ?? '') ? (string)$login['error'] : 'Błąd logowania do GUS.';
        return ['success' => false, 'error' => $msg];
    }
    $sid = gusExtractResultValue($login['response'], 'ZalogujResult');
    if (!$sid) {
        gusLogError($pdo, $userId, $requestId, 'Brak SID w odpowiedzi GUS.');
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'login_sid',
            'error_message' => 'Brak SID w odpowiedzi GUS.',
            'attempt_no' => $login['attempt_no'] ?? null,
            'latency_ms' => $login['latency_ms'] ?? null,
            'error_class' => 'SOAP_FAULT',
            'fault_code' => $login['faultcode'] ?? null,
            'fault_string' => $login['faultstring'] ?? null,
        ]);
        return ['success' => false, 'error' => 'Błąd logowania do GUS.'];
    }

    $searchXml = gusBuildSearchEnvelope('Regon', $regon);
    $search = gusSoapRequest($url, 'DaneSzukajPodmioty', $searchXml, $sid);
    if (!$search['ok']) {
        gusLogError($pdo, $userId, $requestId, $search['error']);
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'search',
            'error_message' => $search['error'] ?? 'Błąd wyszukiwania w GUS.',
            'http_code' => $search['http_code'] ?? null,
            'raw_request' => $searchXml,
            'raw_response' => $search['response'] ?? null,
            'attempt_no' => $search['attempt_no'] ?? null,
            'latency_ms' => $search['latency_ms'] ?? null,
            'error_class' => gusMapErrorClass($search, 'TRANSPORT'),
            'fault_code' => $search['faultcode'] ?? null,
            'fault_string' => $search['faultstring'] ?? null,
        ]);
        $msg = gusIsCircuitOpenMessage($search['error'] ?? '') ? (string)$search['error'] : 'Błąd wyszukiwania w GUS.';
        return ['success' => false, 'error' => $msg];
    }
    $resultXml = gusExtractResultValue($search['response'], 'DaneSzukajPodmiotyResult');
    $raw = gusParseSearchResult($resultXml);
    if (!$raw) {
        $repo->save($snapshotBase + [
            'ok' => false,
            'error_code' => 'not_found',
            'error_message' => 'Nie znaleziono podmiotu w GUS.',
            'http_code' => $search['http_code'] ?? null,
            'raw_request' => $searchXml,
            'raw_response' => $search['response'] ?? null,
            'attempt_no' => $search['attempt_no'] ?? null,
            'latency_ms' => $search['latency_ms'] ?? null,
            'error_class' => gusMapErrorClass($search, 'SOAP_FAULT'),
            'fault_code' => $search['faultcode'] ?? null,
            'fault_string' => $search['faultstring'] ?? null,
        ]);
        return ['success' => false, 'error' => 'Nie znaleziono podmiotu w GUS.'];
    }
    $data = gusStandardizeCompany($raw);
    gusCacheSave($pdo, $data['nip'] ?? null, $data['regon'] ?: $regon, $data);
    $repo->save($snapshotBase + [
        'ok' => true,
        'http_code' => $search['http_code'] ?? null,
        'raw_request' => $searchXml,
        'raw_response' => $search['response'] ?? null,
        'raw_parsed' => $data,
        'attempt_no' => $search['attempt_no'] ?? null,
        'latency_ms' => $search['latency_ms'] ?? null,
        'error_class' => null,
        'fault_code' => null,
        'fault_string' => null,
    ]);
    return ['success' => true, 'data' => $data, 'cached' => false];
}

function gusSelfTest(array $config = []): array
{
    $runtime = gusResolveRuntimeConfig();
    $apiKey = (string)($runtime['api_key'] ?? '');
    $url = (string)($runtime['endpoint'] ?? '');
    if (!$runtime['ok'] || $apiKey === '' || $url === '') {
        return ['ok' => false, 'error' => 'Brak konfiguracji GUS (sprawdz .env).'];
    }

    $login = gusSoapRequest($url, 'Zaloguj', gusBuildLoginEnvelope($apiKey));
    if (!$login['ok']) {
        return ['ok' => false, 'error' => $login['error'] ?? 'Błąd logowania do GUS.'];
    }
    $sid = gusExtractResultValue($login['response'], 'ZalogujResult');
    if (!$sid) {
        return ['ok' => false, 'error' => 'Brak SID w odpowiedzi GUS.'];
    }

    return ['ok' => true];
}
