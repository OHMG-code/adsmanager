<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_error_classifier.php';

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected".var_export($e,true)." got".var_export($a,true)."\n";}}

$c = gusClassifyError(['http_code'=>500,'gus_error_message'=>'server error']);
assertSameVal('transient',$c['error_class'],'500 transient');

$c = gusClassifyError(['http_code'=>429,'gus_error_message'=>'rate limit']);
assertSameVal('rate_limit',$c['error_class'],'429 rate');

$c = gusClassifyError(['gus_error_message'=>'Nieprawidłowy NIP']);
assertSameVal('invalid_request',$c['error_class'],'invalid nip');

$c = gusClassifyError(['gus_error_message'=>'brak danych']);
assertSameVal('not_found',$c['error_class'],'not found');
assertSameVal(false,$c['retryable'],'not found retryable false');

$c = gusClassifyError(['gus_error_message'=>'Nie znaleziono podmiotu w GUS']);
assertSameVal('not_found',$c['error_class'],'soap not found phrase');
assertSameVal(false,$c['retryable'],'soap not found retryable false');

$c = gusClassifyError(['gus_error_message'=>'login failed']);
assertSameVal('auth',$c['error_class'],'auth');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
