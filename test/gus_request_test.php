<?php
declare(strict_types=1);

define('GUS_LOOKUP_LIB', true);

require __DIR__ . '/../public/gus_lookup.php';

if (!function_exists('getSoapAction') || !function_exists('buildSoapClientOptions')) {
    fwrite(STDERR, "GUS request test preflight failed:\nMissing expected helper functions from gus_lookup.php\n");
    exit(1);
}

$failures = [];

$expectedAction = 'http://CIS/BIR/2014/07/IUslugaBIR/GetValue';
$action = getSoapAction('GetValue');
if ($action !== $expectedAction) {
    $failures[] = 'GetValue action mismatch: ' . $action;
}

if (strpos($action, 'http://CIS/BIR/2014/07/') !== 0) {
    $failures[] = 'GetValue action has unexpected namespace prefix: ' . $action;
}

$options = buildSoapClientOptions(SOAP_1_2, false, ['sid: TESTSID123']);
$streamContext = $options['stream_context'] ?? null;
$header = '';
if (is_resource($streamContext)) {
    $ctxOptions = stream_context_get_options($streamContext);
    $header = isset($ctxOptions['http']['header']) ? (string)$ctxOptions['http']['header'] : '';
}
if (stripos($header, 'sid: TESTSID123') === false) {
    $failures[] = 'HTTP sid header not set in stream context options.';
}

if ($failures) {
    fwrite(STDERR, "GUS request test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "GUS request test OK\n";
