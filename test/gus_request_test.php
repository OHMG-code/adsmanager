<?php
declare(strict_types=1);

// Preflight: test must run gus_lookup.php in library mode, not as HTTP endpoint.
$gusLookupPath = __DIR__ . '/../public/gus_lookup.php';
$libProbeCode = sprintf(
    'define("GUS_LOOKUP_LIB", true); require %s; echo "LIB_MODE_OK\n";',
    var_export($gusLookupPath, true)
);
$cmd = 'php -r ' . escapeshellarg($libProbeCode) . ' 2>&1';
$probeOutput = [];
$probeExit = 0;
exec($cmd, $probeOutput, $probeExit);
$probeText = trim(implode("\n", $probeOutput));
if ($probeExit !== 0 || strpos($probeText, 'LIB_MODE_OK') === false) {
    fwrite(STDERR, "GUS request test preflight failed:\n" . $probeText . "\n");
    exit(1);
}

define('GUS_LOOKUP_LIB', true);

require __DIR__ . '/../public/gus_lookup.php';

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
