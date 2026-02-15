<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/gus_validation.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
}

// Normalization
check('normalizeNip strips non-digits', normalizeNip(' 521-079-3216 ') === '5210793216');
check('normalizeRegon strips non-digits', normalizeRegon(' 123-456-785 ') === '123456785');
check('normalizeKrs strips non-digits', normalizeKrs(' 0000-123-456 ') === '0000123456');

// NIP validation
check('valid NIP passes checksum', isValidNip('5210793216') === true);
check('invalid NIP fails checksum', isValidNip('5210793215') === false);
check('invalid NIP length', isValidNip('123') === false);

// REGON validation
check('valid REGON 9 passes checksum', isValidRegon('123456785') === true);
check('invalid REGON 9 fails checksum', isValidRegon('123456784') === false);
check('valid REGON 14 passes checksum', isValidRegon('12345678500002') === true);
check('invalid REGON 14 fails checksum', isValidRegon('12345678500001') === false);

// KRS validation
check('valid KRS length', isValidKrs('0000123456') === true);
check('invalid KRS length', isValidKrs('123456789') === false);

if ($failed === 0) {
    echo "OK ({$passed} tests)\n";
    exit(0);
}

echo "FAILED: {$failed} of " . ($passed + $failed) . " tests\n";
exit(1);
