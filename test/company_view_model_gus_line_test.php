<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/includes/company_view_model.php';

$tests=0;$fail=0;
function assertContainsStr($needle,$hay,$label){global $tests,$fail;$tests++;if(strpos($hay,$needle)===false){$fail++;echo"FAIL: $label expected to contain $needle got $hay\n";}}

// pending
$vm = buildCompanyViewModel([], [], ['queue_state'=>'pending','queue_next_run_at'=>'2026-01-27 12:30:00']);
assertContainsStr('w kolejce', $vm['gus_display_line'], 'pending line');

// running
$vm = buildCompanyViewModel([], [], ['queue_state'=>'running']);
assertContainsStr('w toku', $vm['gus_display_line'], 'running line');

// failed
$vm = buildCompanyViewModel([], [], ['queue_state'=>'failed','queue_last_error_code'=>'E123','queue_last_error_message'=>'timeout','queue_next_run_at'=>'2026-01-27 12:45:00']);
assertContainsStr('błąd', $vm['gus_display_line'], 'failed line');

// none + ok
$vm = buildCompanyViewModel([], ['gus_last_status'=>'OK','gus_last_refresh_at'=>'2026-01-27 10:12:00'], []);
assertContainsStr('OK', $vm['gus_display_line'], 'ok line');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
