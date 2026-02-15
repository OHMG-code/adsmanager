<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/legacy_write_guard.php';

$tests = 0; $failures = 0;
function assertSameVal($exp,$act,$label){global $tests,$failures;$tests++;if($exp!==$act){$failures++;echo"FAIL: $label\nExpected:".var_export($exp,true)."\nActual:".var_export($act,true)."\n";}}

$post = [
    'nip' => '1234567890',
    'nazwa_firmy' => 'ACME',
    'adres' => 'Ulica 1',
    'notatka' => 'keep',
];
$found = assertNoFirmFieldWrites($post);
assertSameVal(['nip','nazwa_firmy','adres'], $found, 'guard detects firm fields');

// simulate removal for CRM insert
$crmPost = $post;
foreach ($found as $key) { unset($crmPost[$key]); }
assertSameVal(['notatka'=>'keep'], $crmPost, 'legacy fields removed from CRM payload');

if($failures>0){echo"Tests: $tests, Failures: $failures\n";exit(1);}echo"Tests: $tests, Failures: $failures\n";
