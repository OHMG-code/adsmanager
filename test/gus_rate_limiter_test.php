<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_rate_limiter.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureGusRateLimit($pdo);

$tests=0;$fail=0;
function assertTrue($c,$l){global $tests,$fail;$tests++;if(!$c){$fail++;echo"FAIL: $l\n";}}
function assertFalse($c,$l){assertTrue(!$c,$l);} 

// global limit 2 per window
assertTrue(gusRateAllow($pdo,'global',2,60),'first allowed');
assertTrue(gusRateAllow($pdo,'global',2,60),'second allowed');
assertFalse(gusRateAllow($pdo,'global',2,60),'third blocked');

// company limit
assertTrue(gusRateAllow($pdo,'company:1',1,900),'company first');
assertFalse(gusRateAllow($pdo,'company:1',1,900),'company second blocked');

if($fail){echo"Tests:$tests Failures:$fail\n";exit(1);}echo"Tests:$tests Failures:$fail\n";
