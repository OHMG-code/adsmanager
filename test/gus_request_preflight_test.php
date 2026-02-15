<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_request_preflight.php';

$tests = 0;
$fail = 0;

function checkPreflight(string $label, bool $condition): void
{
    global $tests, $fail;
    $tests++;
    if ($condition) {
        return;
    }
    $fail++;
    echo "FAIL: {$label}\n";
}

$invalidNip = gusValidateRequestIdentifier('NIP', '123456789');
checkPreflight('NIP 9 cyfr -> invalid_request', $invalidNip['ok'] === false && $invalidNip['error_class'] === 'invalid_request');
checkPreflight('NIP 9 cyfr -> invalid nip length', ($invalidNip['error_message'] ?? '') === 'invalid nip length');
checkPreflight('NIP 9 cyfr -> bez SOAP', $invalidNip['should_call_soap'] === false);

$validNip = gusValidateRequestIdentifier('NIP', '1234567890');
checkPreflight('NIP 10 cyfr -> dopuszcza', $validNip['ok'] === true && $validNip['should_call_soap'] === true);

$normalizedNip = gusValidateRequestIdentifier('NIP', 'PL 521-079-32-16');
checkPreflight('normalizacja NIP usuwa PL i separatory', $normalizedNip['ok'] === true && $normalizedNip['request_value'] === '5210793216');

$fallbackToRegon = gusSelectCompanyRequestIdentifier([
    'nip' => '123456789',
    'regon' => '123-456-785',
    'krs' => '',
]);
checkPreflight('fallback do REGON gdy NIP niepoprawny', $fallbackToRegon['ok'] === true && $fallbackToRegon['request_type'] === 'REGON' && $fallbackToRegon['request_value'] === '123456785');

$placeholderOnly = gusSelectCompanyRequestIdentifier([
    'nip' => '0000000000',
    'regon' => '',
    'krs' => '',
]);
checkPreflight('placeholder 0000000000 -> invalid_request', $placeholderOnly['ok'] === false && $placeholderOnly['error_class'] === 'invalid_request');

if ($fail > 0) {
    echo "Tests:{$tests} Failures:{$fail}\n";
    exit(1);
}

echo "Tests:{$tests} Failures:{$fail}\n";
