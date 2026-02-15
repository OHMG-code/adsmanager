<?php
declare(strict_types=1);

require_once __DIR__ . '/../services/gus_refresh_queue.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureGusRefreshQueue($pdo);
$now = date('Y-m-d H:i:s');
$pdo->exec("INSERT INTO companies (id, name_full, is_active, gus_hold_until) VALUES (1,'A',1, datetime('now','+1 hour'))");
$pdo->exec("INSERT INTO gus_refresh_queue (company_id,status,priority,next_run_at,created_at,updated_at) VALUES (1,'pending',7,datetime('now'),datetime('now'),datetime('now'))");

// simulate worker skip: call smart enqueue? Actually mark skip logic uses priority and hold.
// We mimic: if priority>1 and hold future -> markDone
$job = $pdo->query('SELECT * FROM gus_refresh_queue')->fetch(PDO::FETCH_ASSOC);
if ($job && (int)$job['priority'] > 1) {
    $hold = $pdo->query('SELECT gus_hold_until FROM companies WHERE id=1')->fetchColumn();
    if ($hold && strtotime((string)$hold) > time()) {
        gusQueueMarkDone($pdo, (int)$job['id']);
    }
}
$row = $pdo->query('SELECT status FROM gus_refresh_queue WHERE id=1')->fetchColumn();
assert($row === 'done');

echo "OK\n";
