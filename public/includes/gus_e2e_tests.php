<?php
declare(strict_types=1);

require_once __DIR__ . '/gus_config.php';
require_once __DIR__ . '/gus_service.php';
require_once __DIR__ . '/gus_snapshots.php';
require_once __DIR__ . '/gus_company_mapper.php';
require_once __DIR__ . '/gus_validation.php';

function gusResolveRuntimeConfigForEnv(string $env): array
{
    $env = strtolower(trim($env));
    if (!in_array($env, ['test', 'prod'], true)) {
        $env = 'prod';
    }

    $keyName = $env === 'test' ? 'GUS_KEY_TEST' : 'GUS_KEY_PROD';
    $wsdlName = $env === 'test' ? 'GUS_WSDL_TEST' : 'GUS_WSDL_PROD';
    $endpointName = $env === 'test' ? 'GUS_ENDPOINT_TEST' : 'GUS_ENDPOINT_PROD';

    $apiKey = gusEnvValue($keyName);
    $wsdl = gusEnvValue($wsdlName);
    $endpoint = gusEnvValue($endpointName);

    $missing = [];
    if ($apiKey === '') {
        $missing[] = $keyName;
    }
    if ($wsdl === '') {
        $missing[] = $wsdlName;
    }
    if ($endpoint === '') {
        $missing[] = $endpointName;
    }

    return [
        'environment' => $env,
        'api_key' => $apiKey,
        'wsdl' => $wsdl,
        'endpoint' => $endpoint,
        'missing' => $missing,
        'ok' => $missing === [],
    ];
}

function gusE2eLog(string $message, array $context = []): void
{
    $logDir = dirname(__DIR__, 2) . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $logFile = $logDir . '/gus_e2e.log';
    $payload = [
        'ts' => date('c'),
        'message' => $message,
        'context' => $context,
    ];
    $line = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return;
    }
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
}

function gusMaskValue(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    $len = strlen($digits);
    if ($len <= 4) {
        return $digits !== '' ? str_repeat('*', $len) : '';
    }
    return str_repeat('*', $len - 4) . substr($digits, -4);
}

function gusMaskApiKey(string $xml, string $apiKey): string
{
    if ($apiKey === '') {
        return $xml;
    }
    return str_replace($apiKey, '****', $xml);
}

function gusHasSoapFault(string $xml): bool
{
    return stripos($xml, ':Fault') !== false || stripos($xml, '<Fault') !== false;
}

function gusBuildGetValueEnvelope(string $paramName): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="http://CIS/BIR/2014/07">'
        . '<soap:Header/>'
        . '<soap:Body>'
        . '<ns:GetValue><ns:pNazwaParametru>' . htmlspecialchars($paramName, ENT_XML1) . '</ns:pNazwaParametru></ns:GetValue>'
        . '</soap:Body>'
        . '</soap:Envelope>';
}

function gusBuildLogoutEnvelope(string $sid): string
{
    return '<?xml version="1.0" encoding="utf-8"?>'
        . '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" xmlns:ns="http://CIS/BIR/PUBL/2014/07">'
        . '<soap:Header/>'
        . '<soap:Body>'
        . '<ns:Wyloguj><ns:pIdentyfikatorSesji>' . htmlspecialchars($sid, ENT_XML1) . '</ns:pIdentyfikatorSesji></ns:Wyloguj>'
        . '</soap:Body>'
        . '</soap:Envelope>';
}

function gusE2eSnapshot(PDO $pdo, array $data): void
{
    try {
        $repo = new GusSnapshotRepository($pdo);
        $repo->save($data);
    } catch (Throwable $e) {
        gusE2eLog('snapshot_save_failed', ['error' => $e->getMessage()]);
    }
}

function gusE2eRunSelfTest(PDO $pdo, string $env, bool $withSearch = false, ?string $testNip = null): array
{
    $traceId = uniqid('e2e_', true);
    $config = gusResolveRuntimeConfigForEnv($env);
    if (empty($config['ok'])) {
        $missing = implode(', ', (array)($config['missing'] ?? []));
        $msg = 'Brak konfiguracji GUS: ' . $missing;
        gusE2eLog('e2e_config_missing', ['env' => $env, 'missing' => $missing]);
        gusE2eSnapshot($pdo, [
            'env' => $env,
            'request_type' => 'e2e_config',
            'request_value' => 'selftest',
            'report_type' => null,
            'http_code' => null,
            'ok' => false,
            'error_code' => 'config_missing',
            'error_message' => $msg,
            'raw_request' => null,
            'raw_response' => null,
            'raw_parsed' => null,
            'correlation_id' => $traceId,
            'error_class' => 'VALIDATION',
            'attempt_no' => 0,
            'latency_ms' => 0,
            'fault_code' => null,
            'fault_string' => null,
        ]);
        return ['ok' => false, 'error' => $msg, 'trace_id' => $traceId];
    }

    $apiKey = (string)$config['api_key'];
    $endpoint = (string)$config['endpoint'];

    $results = [
        'ok' => true,
        'trace_id' => $traceId,
        'steps' => [],
    ];

    // Login
    $loginXml = gusBuildLoginEnvelope($apiKey);
    $login = gusSoapRequest($endpoint, 'Zaloguj', $loginXml);
    $loginFault = gusExtractSoapFault($login['response'] ?? '');
    $sid = '';
    $loginOk = false;
    $loginError = '';
    if (!empty($login['ok'])) {
        $sid = gusExtractResultValue($login['response'], 'ZalogujResult') ?: '';
        $loginOk = $sid !== '' && !gusHasSoapFault((string)$login['response']);
        if (!$loginOk && gusHasSoapFault((string)$login['response'])) {
            $loginError = 'soap_fault';
        }
    } else {
        $loginError = $login['error'] ?? 'login_failed';
    }

    gusE2eSnapshot($pdo, [
        'env' => $env,
        'request_type' => 'e2e_login',
        'request_value' => 'selftest',
        'report_type' => 'Zaloguj',
        'http_code' => $login['http_code'] ?? null,
        'ok' => $loginOk,
        'error_code' => $loginOk ? null : 'login_failed',
        'error_message' => $loginOk ? null : $loginError,
        'raw_request' => gusMaskApiKey($loginXml, $apiKey),
        'raw_response' => $login['response'] ?? null,
        'raw_parsed' => null,
        'correlation_id' => $traceId,
        'attempt_no' => $login['attempt_no'] ?? null,
        'latency_ms' => $login['latency_ms'] ?? null,
        'error_class' => $login['error_class'] ?? null,
        'fault_code' => $loginFault['faultcode'] ?? null,
        'fault_string' => $loginFault['faultstring'] ?? null,
    ]);

    $results['steps'][] = ['name' => 'login', 'ok' => $loginOk];
    if (!$loginOk) {
        $results['ok'] = false;
        $results['error'] = 'Login failed';
        gusE2eLog('e2e_login_failed', ['env' => $env, 'http_code' => $login['http_code'] ?? null]);
        return $results;
    }

    // GetValue(StatusSesji)
    $getValueXml = gusBuildGetValueEnvelope('StatusSesji');
    $getValue = gusSoapRequest($endpoint, 'GetValue', $getValueXml, $sid);
    $getValueFault = gusExtractSoapFault($getValue['response'] ?? '');
    $statusValue = '';
    $getValueOk = false;
    $getValueError = '';
    if (!empty($getValue['ok'])) {
        $statusValue = gusExtractResultValue($getValue['response'], 'GetValueResult') ?: '';
        $getValueOk = trim($statusValue) === '1' && !gusHasSoapFault((string)$getValue['response']);
        if (!$getValueOk && gusHasSoapFault((string)$getValue['response'])) {
            $getValueError = 'soap_fault';
        }
    } else {
        $getValueError = $getValue['error'] ?? 'getvalue_failed';
    }

    gusE2eSnapshot($pdo, [
        'env' => $env,
        'request_type' => 'e2e_getvalue',
        'request_value' => 'StatusSesji',
        'report_type' => 'GetValue',
        'http_code' => $getValue['http_code'] ?? null,
        'ok' => $getValueOk,
        'error_code' => $getValueOk ? null : 'getvalue_failed',
        'error_message' => $getValueOk ? null : $getValueError,
        'raw_request' => $getValueXml,
        'raw_response' => $getValue['response'] ?? null,
        'raw_parsed' => null,
        'correlation_id' => $traceId,
        'attempt_no' => $getValue['attempt_no'] ?? null,
        'latency_ms' => $getValue['latency_ms'] ?? null,
        'error_class' => $getValue['error_class'] ?? null,
        'fault_code' => $getValueFault['faultcode'] ?? null,
        'fault_string' => $getValueFault['faultstring'] ?? null,
    ]);

    $results['steps'][] = ['name' => 'getvalue', 'ok' => $getValueOk, 'value' => $statusValue];
    if (!$getValueOk) {
        $results['ok'] = false;
        $results['error'] = 'GetValue failed';
    }

    // Optional search test
    if ($withSearch) {
        $nip = $testNip ? normalizeNip($testNip) : '';
        $searchOk = false;
        $searchError = '';
        if ($nip === '' || !isValidNip($nip)) {
            $searchError = 'invalid_test_nip';
        } else {
            $searchXml = gusBuildSearchEnvelope('Nip', $nip);
            $search = gusSoapRequest($endpoint, 'DaneSzukajPodmioty', $searchXml, $sid);
            $searchFault = gusExtractSoapFault($search['response'] ?? '');
            if (!empty($search['ok'])) {
                $resultXml = gusExtractResultValue($search['response'], 'DaneSzukajPodmiotyResult');
                $raw = gusParseSearchResult($resultXml);
                $mapper = new GusCompanyMapper();
                $mapped = $mapper->map($raw ?? []);
                $searchOk = !empty($mapped['name_full']) && !empty($mapped['nip']) && !empty($mapped['regon'])
                    && !empty($mapped['address']['street']) && !empty($mapped['address']['city']);
                if (!$searchOk) {
                    $searchError = 'mapping_missing_fields';
                }
                if (!$searchOk && gusHasSoapFault((string)$search['response'])) {
                    $searchError = 'soap_fault';
                }
            } else {
                $searchError = $search['error'] ?? 'search_failed';
            }

            gusE2eSnapshot($pdo, [
                'env' => $env,
                'request_type' => 'e2e_search',
                'request_value' => $nip,
                'report_type' => 'DaneSzukajPodmioty',
                'http_code' => $search['http_code'] ?? null,
                'ok' => $searchOk,
                'error_code' => $searchOk ? null : $searchError,
                'error_message' => $searchOk ? null : $searchError,
                'raw_request' => $searchXml,
                'raw_response' => $search['response'] ?? null,
                'raw_parsed' => null,
                'correlation_id' => $traceId,
                'attempt_no' => $search['attempt_no'] ?? null,
                'latency_ms' => $search['latency_ms'] ?? null,
                'error_class' => $search['error_class'] ?? null,
                'fault_code' => $searchFault['faultcode'] ?? null,
                'fault_string' => $searchFault['faultstring'] ?? null,
            ]);
        }

        $results['steps'][] = ['name' => 'search', 'ok' => $searchOk, 'error' => $searchError];
        if (!$searchOk) {
            $results['ok'] = false;
            if ($results['error'] === null || $results['error'] === '') {
                $results['error'] = 'Search test failed';
            }
        }
    }

    // Logout
    $logoutXml = gusBuildLogoutEnvelope($sid);
    $logout = gusSoapRequest($endpoint, 'Wyloguj', $logoutXml, $sid);
    $logoutFault = gusExtractSoapFault($logout['response'] ?? '');
    $logoutOk = !empty($logout['ok']) && !gusHasSoapFault((string)$logout['response']);
    $logoutError = '';
    if (!$logoutOk) {
        $logoutError = gusHasSoapFault((string)$logout['response']) ? 'soap_fault' : ($logout['error'] ?? 'logout_failed');
    }

    gusE2eSnapshot($pdo, [
        'env' => $env,
        'request_type' => 'e2e_logout',
        'request_value' => 'selftest',
        'report_type' => 'Wyloguj',
        'http_code' => $logout['http_code'] ?? null,
        'ok' => $logoutOk,
        'error_code' => $logoutOk ? null : 'logout_failed',
        'error_message' => $logoutOk ? null : $logoutError,
        'raw_request' => $logoutXml,
        'raw_response' => $logout['response'] ?? null,
        'raw_parsed' => null,
        'correlation_id' => $traceId,
        'attempt_no' => $logout['attempt_no'] ?? null,
        'latency_ms' => $logout['latency_ms'] ?? null,
        'error_class' => $logout['error_class'] ?? null,
        'fault_code' => $logoutFault['faultcode'] ?? null,
        'fault_string' => $logoutFault['faultstring'] ?? null,
    ]);

    $results['steps'][] = ['name' => 'logout', 'ok' => $logoutOk];
    if (!$logoutOk) {
        $results['ok'] = false;
        if ($results['error'] === null || $results['error'] === '') {
            $results['error'] = 'Logout failed';
        }
    }

    gusE2eLog('e2e_run', [
        'env' => $env,
        'trace_id' => $traceId,
        'ok' => $results['ok'],
        'steps' => array_map(static function ($step) {
            return $step['name'] . ':' . (!empty($step['ok']) ? 'ok' : 'fail');
        }, $results['steps']),
    ]);

    return $results;
}
