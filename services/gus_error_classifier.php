<?php
declare(strict_types=1);

function gusClassifyError(array $ctx): array
{
    $toLower = static function (?string $value): string {
        $value = (string)$value;
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    };

    $http = (int)($ctx['http_code'] ?? 0);
    $faultcode = $toLower((string)($ctx['faultcode'] ?? ''));
    $faultstring = $toLower((string)($ctx['faultstring'] ?? ''));
    $exMsg = $toLower((string)($ctx['exception_message'] ?? ''));
    $errCode = $toLower((string)($ctx['gus_error_code'] ?? ''));
    $errMsg = $toLower((string)($ctx['gus_error_message'] ?? ($ctx['error'] ?? '')));

    $notFoundCodes = ['not_found', 'entity_not_found', 'brak_danych'];
    $invalidCodes = [
        'invalid_request',
        'invalid_identifier',
        'invalid_nip',
        'invalid_regon',
        'invalid_krs',
        'invalid_nip_length',
        'invalid_regon_length',
        'invalid_krs_length',
        'validation',
        'missing_identifier',
        'invalid_request_type',
    ];

    if (in_array($errCode, $notFoundCodes, true)) {
        return ['error_class' => 'not_found', 'user_message_short' => 'Brak danych w GUS', 'retryable' => false, 'backoff_seconds' => 0];
    }
    if (in_array($errCode, $invalidCodes, true)) {
        return ['error_class' => 'invalid_request', 'user_message_short' => 'Nieprawidłowe dane wejściowe', 'retryable' => false, 'backoff_seconds' => 0];
    }

    $isTimeout = str_contains($exMsg, 'timeout') || str_contains($errMsg, 'timeout') || str_contains($faultstring, 'timeout');
    $isRate = $http === 429 || str_contains($errMsg, 'rate limit') || str_contains($errMsg, 'too many') || str_contains($errMsg, 'limit');
    $isAuth = str_contains($errMsg, 'login') || str_contains($errMsg, 'sid') || str_contains($errMsg, 'auth') || str_contains($errMsg, 'unauthorized');
    $isInvalid = str_contains($errMsg, 'nieprawidłowy') || str_contains($errMsg, 'invalid') || str_contains($errMsg, 'validation');
    $isNotFound = str_contains($errMsg, 'brak danych')
        || str_contains($errMsg, 'nie znaleziono')
        || str_contains($errMsg, 'nie istnieje')
        || str_contains($errMsg, 'not found')
        || str_contains($faultstring, 'nie znaleziono')
        || str_contains($faultstring, 'not found');
    $isServer = in_array($http, [500,502,503,504], true);

    if ($isRate) {
        return ['error_class' => 'rate_limit', 'user_message_short' => 'Limit GUS', 'retryable' => true, 'backoff_seconds' => 120];
    }
    if ($isTimeout || $isServer) {
        return ['error_class' => 'transient', 'user_message_short' => 'Błąd sieci/timeout', 'retryable' => true, 'backoff_seconds' => 300];
    }
    if ($isAuth) {
        return ['error_class' => 'auth', 'user_message_short' => 'Błąd autoryzacji GUS', 'retryable' => true, 'backoff_seconds' => 900];
    }
    if ($isInvalid) {
        return ['error_class' => 'invalid_request', 'user_message_short' => 'Nieprawidłowe dane wejściowe', 'retryable' => false, 'backoff_seconds' => 0];
    }
    if ($isNotFound) {
        return ['error_class' => 'not_found', 'user_message_short' => 'Brak danych w GUS', 'retryable' => false, 'backoff_seconds' => 0];
    }

    return ['error_class' => 'unknown', 'user_message_short' => 'Nieznany błąd GUS', 'retryable' => true, 'backoff_seconds' => 600];
}
