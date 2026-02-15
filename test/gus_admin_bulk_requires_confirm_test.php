<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_refresh_queue.php';

$tests=0;$fail=0;
function assertSameVal($e,$a,$l){global $tests,$fail;$tests++;if($e!==$a){$fail++;echo"FAIL: $l expected ".var_export($e,true)." got ".var_export($a,true)."\n";}}

assertSameVal(false, gusBulkConfirmOk('filter', ''), 'filter without confirm blocked');
assertSameVal(true, gusBulkConfirmOk('filter', 'CONFIRM'), 'filter with confirm ok');
assertSameVal(true, gusBulkConfirmOk('selected', ''), 'selected no confirm ok');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
