<?php
declare(strict_types=1);

define('GUS_LOOKUP_LIB', true);

require __DIR__ . '/../public/gus_lookup.php';

$failures = [];

$expectedAction = 'http://CIS/BIR/2014/07/IUslugaBIR/GetValue';
$expectedNamespace = 'http://CIS/BIR/2014/07';

$action = getSoapAction('GetValue');
if ($action !== $expectedAction) {
    $failures[] = 'GetValue action mismatch: ' . $action;
}

$namespace = getWsdlBodyNamespace('GetValue');
if ($namespace !== $expectedNamespace) {
    $failures[] = 'GetValue namespace mismatch: ' . $namespace;
}

$options = buildSoapClientOptions(SOAP_1_2, false);
$options['uri'] = 'http://CIS/BIR/PUBL/2014/07';
$client = new GusSoapClient(null, $options);
setClientHttpSid($client, 'TESTSID123');

$streamContext = $client->getStreamContext();
$header = '';
if ($streamContext) {
    $ctxOptions = stream_context_get_options($streamContext);
    $header = isset($ctxOptions['http']['header']) ? (string)$ctxOptions['http']['header'] : '';
}
if (stripos($header, 'sid: TESTSID123') === false) {
    $failures[] = 'HTTP sid header not set in stream context.';
}

if ($failures) {
    fwrite(STDERR, "GUS request test failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "GUS request test OK\n";
