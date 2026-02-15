<?php
declare(strict_types=1);

function companyValue(?string $companyValue, ?string $legacyValue, string $flagKey, array &$flags): ?string
{
    $companyValue = trim((string)$companyValue);
    if ($companyValue !== '') {
        $flags[$flagKey] = 'companies';
        return $companyValue;
    }

    $legacyValue = trim((string)$legacyValue);
    if ($legacyValue !== '') {
        $flags[$flagKey] = 'legacy';
        return $legacyValue;
    }

    $flags[$flagKey] = 'none';
    return null;
}

function buildStreetLine(?string $street, ?string $buildingNo, ?string $apartmentNo): ?string
{
    $street = trim((string)$street);
    $buildingNo = trim((string)$buildingNo);
    $apartmentNo = trim((string)$apartmentNo);

    if ($street === '' && $buildingNo === '' && $apartmentNo === '') {
        return null;
    }

    $line = $street;
    if ($buildingNo !== '') {
        $line = trim($line . ' ' . $buildingNo);
    }
    if ($apartmentNo !== '') {
        $line .= '/' . $apartmentNo;
    }
    return trim($line);
}

function buildCompanyViewModel(array $client, ?array $company = null, array $gusStatus = []): array
{
    $company = $company ?? [];
    $flags = [];

    $nameFull = trim((string)($company['name_full'] ?? ''));
    $nameShort = trim((string)($company['name_short'] ?? ''));
    $companyName = null;
    if ($nameFull !== '') {
        $companyName = $nameFull;
        $flags['name'] = 'companies';
    } elseif ($nameShort !== '') {
        $companyName = $nameShort;
        $flags['name'] = 'companies';
    } else {
        $companyName = trim((string)($client['nazwa_firmy'] ?? ''));
        $flags['name'] = $companyName !== '' ? 'legacy' : 'none';
    }

    $nip = companyValue($company['nip'] ?? null, $client['nip'] ?? null, 'nip', $flags);
    $regon = companyValue($company['regon'] ?? null, $client['regon'] ?? null, 'regon', $flags);

    $street = companyValue($company['street'] ?? null, $client['ulica'] ?? null, 'address_street', $flags);
    if ($street === null) {
        $street = companyValue(null, $client['adres'] ?? null, 'address_street_fallback', $flags);
        if ($street !== null) {
            $flags['address_street'] = $flags['address_street_fallback'];
        }
    }
    $buildingNo = companyValue($company['building_no'] ?? null, $client['nr_nieruchomosci'] ?? null, 'address_building', $flags);
    $apartmentNo = companyValue($company['apartment_no'] ?? null, $client['nr_lokalu'] ?? null, 'address_apartment', $flags);
    $postalCode = companyValue($company['postal_code'] ?? null, $client['kod_pocztowy'] ?? null, 'address_postal', $flags);
    $city = companyValue($company['city'] ?? null, $client['miejscowosc'] ?? ($client['miasto'] ?? null), 'address_city', $flags);
    $woj = companyValue($company['wojewodztwo'] ?? null, $client['wojewodztwo'] ?? null, 'address_woj', $flags);
    $powiat = companyValue($company['powiat'] ?? null, $client['powiat'] ?? null, 'address_powiat', $flags);
    $gmina = companyValue($company['gmina'] ?? null, $client['gmina'] ?? null, 'address_gmina', $flags);
    $country = companyValue($company['country'] ?? null, $client['kraj'] ?? null, 'address_country', $flags);

    $addressParts = [];
    $streetLine = buildStreetLine($street, $buildingNo, $apartmentNo);
    if ($streetLine) {
        $addressParts[] = $streetLine;
    }
    if ($city) {
        $addressParts[] = $city;
    }
    if ($woj) {
        $addressParts[] = $woj;
    }
    if (!$addressParts && $street) {
        $addressParts[] = $street;
    }

    $addressDisplay = $addressParts ? implode(', ', $addressParts) : null;

    $statusSubject = null;
    $statusRaw = trim((string)($company['status'] ?? ''));
    if ($statusRaw !== '') {
        $statusSubject = $statusRaw;
        $flags['status_subject'] = 'companies';
    } elseif (array_key_exists('is_active', $company)) {
        $statusSubject = !empty($company['is_active']) ? 'Aktywny' : 'Nieaktywny';
        $flags['status_subject'] = 'companies';
    } else {
        $flags['status_subject'] = 'none';
    }

    $crmStatus = $client['status'] ?? null;

    $gusDisplay = buildGusDisplayLine($gusStatus ?: [], $company);

    return [
        'company_id' => $company['id'] ?? null,
        'company_name_display' => $companyName !== '' ? $companyName : null,
        'nip_display' => $nip,
        'regon_display' => $regon,
        'address_display' => $addressDisplay ?? ($client['adres'] ?? null),
        'status_subject_display' => $statusSubject,
        'crm_status_display' => $crmStatus,
        'source_flags' => array_merge($flags, [
            'name' => $company['name_source'] ?? 'legacy',
            'address' => $company['address_source'] ?? 'legacy',
            'identifiers' => $company['identifiers_source'] ?? 'legacy',
            'last_gus_update_at' => $company['last_gus_update_at'] ?? null,
            'last_manual_update_at' => $company['last_manual_update_at'] ?? null,
        ]),
        'address_city_display' => $city,
        'address_wojewodztwo_display' => $woj,
        'gus_queue_state' => $gusStatus['queue_state'] ?? 'none',
        'gus_queue_next_run_at' => $gusStatus['queue_next_run_at'] ?? null,
        'gus_queue_eta_minutes' => $gusStatus['queue_eta_minutes'] ?? null,
        'gus_queue_error_class' => $gusStatus['queue_error_class'] ?? null,
        'gus_queue_error_message' => $gusStatus['queue_error_message'] ?? null,
        'gus_display_line' => $gusDisplay,
    ];
}

function extractPrefixedRow(array $row, string $prefix): array
{
    $result = [];
    $prefixLen = strlen($prefix);
    foreach ($row as $key => $value) {
        if (strncmp($key, $prefix, $prefixLen) === 0) {
            $result[substr($key, $prefixLen)] = $value;
        }
    }
    return $result;
}

function buildCompanyViewModelFromJoined(array $row, string $clientPrefix = 'k_', string $companyPrefix = 'c_', array $gusStatus = []): array
{
    $client = extractPrefixedRow($row, $clientPrefix);
    $company = extractPrefixedRow($row, $companyPrefix);
    return buildCompanyViewModel($client, $company ?: null, $gusStatus);
}

function buildGusDisplayLine(array $status, array $company): string
{
    $state = $status['queue_state'] ?? 'none';
    $next = $status['queue_next_run_at'] ?? null;
    $eta = $status['queue_eta_minutes'] ?? null;
    $lastRefresh = $status['last_refresh_at'] ?? ($company['gus_last_refresh_at'] ?? null);
    $lastStatus = $status['last_status'] ?? ($company['gus_last_status'] ?? null);
    $lastErrCode = $status['last_error_code'] ?? ($company['gus_last_error_code'] ?? null);
    $lastErrMsg = $status['last_error_message'] ?? ($company['gus_last_error_message'] ?? null);
    $errCodeQueue = $status['queue_last_error_code'] ?? null;
    $errMsgQueue = $status['queue_last_error_message'] ?? null;
    $errClass = $status['queue_error_class'] ?? null;
    $holdUntil = $status['gus_hold_until'] ?? null;
    $holdReason = $status['gus_hold_reason'] ?? null;
    $notFound = !empty($status['gus_not_found']);
    $notFoundAt = $status['gus_not_found_at'] ?? null;

    $shortErr = function (?string $msg): string {
        $msg = trim((string)$msg);
        if ($msg === '') {
            return '';
        }
        return mb_strimwidth($msg, 0, 80, '...');
    };

    if (in_array($state, ['running', 'pending'], true)) {
        return $state === 'running'
            ? 'GUS: odświeżanie w toku'
            : 'GUS: w kolejce' . ($eta ? ' (za ~' . $eta . ' min)' : ($next ? ' (start: ' . $next . ')' : ''));
    }
    if ($holdUntil && strtotime($holdUntil) > time()) {
        return 'GUS: wstrzymane do ' . $holdUntil . ' (powód: ' . ($holdReason ?: 'hold') . ')';
    }
    if ($notFound) {
        return 'GUS: brak danych (ostatnio: ' . ($notFoundAt ?: '-') . ')';
    }
    if ($state === 'failed') {
        return 'GUS: błąd' . ($errClass ? ' (' . $errClass . ')' : '') . ($errCodeQueue ? ' (' . $errCodeQueue . ')' : '') . ($eta ? ' – ponowi za ~' . $eta . ' min' : '') . ($errMsgQueue ? ' ' . $shortErr($errMsgQueue) : '');
    }
    return ($lastStatus === 'OK' && $lastRefresh)
        ? 'GUS: OK (ostatnio: ' . $lastRefresh . ')'
        : (($lastErrCode || $lastErrMsg)
            ? 'GUS: błąd (ostatnio: ' . ($lastErrCode ?: 'ERR') . ') ' . $shortErr($lastErrMsg)
            : 'GUS: brak danych');
}
