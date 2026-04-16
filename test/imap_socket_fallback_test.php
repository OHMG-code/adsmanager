<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/imap_socket_fallback.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
}

$headers = imapFallbackParseHeaders("Subject: Test\r\n\tMessage\r\nFrom: Jan <jan@example.com>\r\nDate: Wed, 16 Apr 2026 21:00:00 +0200\r\n");
check('parse headers with folded lines', ($headers['subject'] ?? '') === 'Test Message');
check('parse headers keeps from', ($headers['from'] ?? '') === 'Jan <jan@example.com>');

$addr = imapFallbackParseAddressHeader('Jan Kowalski <jan@example.com>, ala@example.com');
check('address parser extracts first email', ($addr['emails'][0] ?? '') === 'jan@example.com');
check('address parser extracts second email', ($addr['emails'][1] ?? '') === 'ala@example.com');
check('address parser extracts display name', ($addr['names'][0] ?? '') === 'Jan Kowalski');

$base64 = imapFallbackDecodeTransferEncoding(base64_encode('Zazolc gesla jazn'), 'base64');
check('decode base64 body', $base64 === 'Zazolc gesla jazn');

$qp = imapFallbackDecodeTransferEncoding('Linia=0A2', 'quoted-printable');
check('decode quoted-printable body', $qp === "Linia\n2");

[$textBody, $htmlBody] = imapFallbackExtractBodies(
    ['content-type' => 'text/plain; charset=UTF-8', 'content-transfer-encoding' => 'base64'],
    base64_encode('To jest plain')
);
check('extract plain text body', $textBody === 'To jest plain');
check('extract plain gives empty html', $htmlBody === '');

$multipart = "--abc\r\n"
    . "Content-Type: text/plain; charset=UTF-8\r\n\r\n"
    . "Plain body\r\n"
    . "--abc\r\n"
    . "Content-Type: text/html; charset=UTF-8\r\n\r\n"
    . "<p>Html body</p>\r\n"
    . "--abc--\r\n";
[$multiText, $multiHtml] = imapFallbackExtractBodies(
    ['content-type' => 'multipart/alternative; boundary="abc"'],
    $multipart
);
check('extract multipart text body', $multiText === 'Plain body');
check('extract multipart html body', $multiHtml === '<p>Html body</p>');

if ($failed === 0) {
    echo "OK ({$passed} tests)\n";
    exit(0);
}

echo "FAILED: {$failed} of " . ($passed + $failed) . " tests\n";
exit(1);
