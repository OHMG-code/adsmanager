<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/gus_validation.php';

function gusNormalizeRequestType(string $requestType): string
{
    $type = strtoupper(trim($requestType));
    return match ($type) {
        'NIP', 'REGON', 'KRS' => $type,
        default => $type,
    };
}

function gusNormalizeRequestValue(string $requestType, ?string $value): string
{
    $type = gusNormalizeRequestType($requestType);
    return match ($type) {
        'NIP' => normalizeNip((string)$value),
        'REGON' => normalizeRegon((string)$value),
        'KRS' => normalizeKrs((string)$value),
        default => preg_replace('/\D+/', '', (string)$value) ?? '',
    };
}

function gusMaskRequestValue(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    $len = strlen($digits);
    if ($len === 0) {
        return '';
    }
    if ($len <= 4) {
        return str_repeat('*', $len);
    }
    return str_repeat('*', $len - 4) . substr($digits, -4);
}

function gusValidateRequestIdentifier(string $requestType, ?string $rawValue): array
{
    $type = gusNormalizeRequestType($requestType);
    $value = gusNormalizeRequestValue($type, $rawValue);

    $fail = static function (string $code, string $message) use ($type, $value): array {
        return [
            'ok' => false,
            'should_call_soap' => false,
            'request_type' => $type,
            'request_value' => $value,
            'error_class' => 'invalid_request',
            'error_code' => $code,
            'error_message' => $message,
        ];
    };

    if ($type !== 'NIP' && $type !== 'REGON' && $type !== 'KRS') {
        return $fail('invalid_request_type', 'invalid request type');
    }

    if ($value === '') {
        return $fail('missing_identifier', 'missing identifier value');
    }

    if (preg_match('/^0+$/', $value) === 1) {
        return $fail('invalid_identifier', 'invalid identifier placeholder');
    }

    if ($type === 'NIP' && strlen($value) !== 10) {
        return $fail('invalid_nip_length', 'invalid nip length');
    }
    if ($type === 'REGON' && !in_array(strlen($value), [9, 14], true)) {
        return $fail('invalid_regon_length', 'invalid regon length');
    }
    if ($type === 'KRS' && strlen($value) !== 10) {
        return $fail('invalid_krs_length', 'invalid krs length');
    }

    return [
        'ok' => true,
        'should_call_soap' => true,
        'request_type' => $type,
        'request_value' => $value,
        'error_class' => null,
        'error_code' => null,
        'error_message' => null,
    ];
}

function gusSelectCompanyRequestIdentifier(array $company): array
{
    $candidates = [
        ['request_type' => 'NIP', 'request_value' => gusNormalizeRequestValue('NIP', (string)($company['nip'] ?? ''))],
        ['request_type' => 'REGON', 'request_value' => gusNormalizeRequestValue('REGON', (string)($company['regon'] ?? ''))],
        ['request_type' => 'KRS', 'request_value' => gusNormalizeRequestValue('KRS', (string)($company['krs'] ?? ''))],
    ];

    $firstInvalid = null;
    foreach ($candidates as $candidate) {
        if ($candidate['request_value'] === '') {
            continue;
        }
        $validation = gusValidateRequestIdentifier($candidate['request_type'], $candidate['request_value']);
        if ($validation['ok']) {
            return $validation;
        }
        if ($firstInvalid === null) {
            $firstInvalid = $validation;
        }
    }

    if ($firstInvalid !== null) {
        return $firstInvalid;
    }

    return [
        'ok' => false,
        'should_call_soap' => false,
        'request_type' => 'NONE',
        'request_value' => '',
        'error_class' => 'invalid_request',
        'error_code' => 'missing_identifier',
        'error_message' => 'missing valid identifier',
    ];
}
