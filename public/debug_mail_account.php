<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

requireRole(['Administrator']);

$sessionLogin = (string)($_SESSION['login'] ?? $_SESSION['user_login'] ?? '');
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$sessionRole = (string)($_SESSION['rola'] ?? $_SESSION['user_role'] ?? '');

$requestedUid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
$uid = $requestedUid > 0 ? $requestedUid : $sessionUserId;

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fetchDbInfo(PDO $pdo): array {
    $info = [
        'db' => '',
        'host' => '',
        'db_user' => '',
        'port' => '',
        'error' => '',
    ];
    try {
        $stmt = $pdo->query('SELECT DATABASE() AS db, @@hostname AS host, USER() AS db_user, @@port AS port');
        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $info['db'] = (string)($row['db'] ?? '');
        $info['host'] = (string)($row['host'] ?? '');
        $info['db_user'] = (string)($row['db_user'] ?? '');
        $info['port'] = (string)($row['port'] ?? '');
    } catch (Throwable $e) {
        $info['error'] = $e->getMessage();
    }
    return $info;
}

$dbInfo = fetchDbInfo($pdo);

$mailCount = null;
$mailCountError = '';
try {
    $stmt = $pdo->query('SELECT COUNT(*) FROM mail_accounts');
    $mailCount = $stmt ? (int)$stmt->fetchColumn() : null;
} catch (Throwable $e) {
    $mailCountError = $e->getMessage();
}

$mailRows = [];
$mailRowsError = '';
try {
    $stmt = $pdo->prepare(
        'SELECT id, user_id, email_address, is_active, created_at FROM mail_accounts WHERE user_id = :uid'
    );
    $stmt->execute([':uid' => $uid]);
    $mailRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $mailRowsError = $e->getMessage();
}

$userRow = null;
$userRowError = '';
try {
    $stmt = $pdo->prepare('SELECT id, login, email FROM uzytkownicy WHERE id = :uid');
    $stmt->execute([':uid' => $uid]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
    $userRowError = $e->getMessage();
}

$hasAccount = count($mailRows) > 0;
$hasActive = false;
foreach ($mailRows as $row) {
    if (!empty($row['is_active'])) {
        $hasActive = true;
        break;
    }
}
$accountStatus = 'NIE';
if ($hasAccount) {
    $accountStatus = $hasActive ? 'TAK (aktywny)' : 'TAK (nieaktywny)';
}

?><!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <title>Debug mail account</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; color: #1b1b1b; }
    table { border-collapse: collapse; width: 100%; margin: 10px 0 20px; }
    th, td { border: 1px solid #ccc; padding: 8px 10px; text-align: left; vertical-align: top; }
    th { background: #f3f3f3; }
    .section-title { margin-top: 24px; font-size: 18px; }
    .note { color: #555; font-size: 13px; }
  </style>
</head>
<body>
  <h1>Debug mail account</h1>
  <p class="note">Parametr: <code>?uid=123</code> pozwala sprawdzic dowolnego usera (tylko admin).</p>

  <div class="section-title">SESSION</div>
  <table>
    <tr><th>login</th><td><?= h($sessionLogin !== '' ? $sessionLogin : '-') ?></td></tr>
    <tr><th>user_id</th><td><?= (int)$sessionUserId ?></td></tr>
    <tr><th>rola</th><td><?= h($sessionRole !== '' ? $sessionRole : '-') ?></td></tr>
  </table>

  <div class="section-title">DB</div>
  <table>
    <tr><th>db</th><td><?= h($dbInfo['db'] !== '' ? $dbInfo['db'] : '-') ?></td></tr>
    <tr><th>host</th><td><?= h($dbInfo['host'] !== '' ? $dbInfo['host'] : '-') ?></td></tr>
    <tr><th>db_user</th><td><?= h($dbInfo['db_user'] !== '' ? $dbInfo['db_user'] : '-') ?></td></tr>
    <tr><th>port</th><td><?= h($dbInfo['port'] !== '' ? $dbInfo['port'] : '-') ?></td></tr>
    <tr><th>error</th><td><?= h($dbInfo['error'] !== '' ? $dbInfo['error'] : '-') ?></td></tr>
  </table>

  <div class="section-title">Test zapytania</div>
  <table>
    <tr>
      <th>SELECT COUNT(*) FROM mail_accounts</th>
      <td>
        <?= $mailCountError !== '' ? h('ERROR: ' . $mailCountError) : (is_int($mailCount) ? (string)$mailCount : '-') ?>
      </td>
    </tr>
    <tr>
      <th>uid=<?= (int)$uid ?> => rekord mail_accounts</th>
      <td><?= h($accountStatus) ?></td>
    </tr>
  </table>

  <div class="section-title">mail_accounts WHERE user_id = :uid</div>
  <table>
    <thead>
      <tr>
        <th>id</th>
        <th>user_id</th>
        <th>email_address</th>
        <th>is_active</th>
        <th>created_at</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($mailRowsError !== ''): ?>
        <tr><td colspan="5"><?= h('ERROR: ' . $mailRowsError) ?></td></tr>
      <?php elseif (!$mailRows): ?>
        <tr><td colspan="5">Brak rekordow.</td></tr>
      <?php else: ?>
        <?php foreach ($mailRows as $row): ?>
          <tr>
            <td><?= (int)($row['id'] ?? 0) ?></td>
            <td><?= (int)($row['user_id'] ?? 0) ?></td>
            <td><?= h((string)($row['email_address'] ?? '')) ?></td>
            <td><?= (int)($row['is_active'] ?? 0) ?></td>
            <td><?= h((string)($row['created_at'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="section-title">uzytkownicy WHERE id = :uid</div>
  <table>
    <thead>
      <tr>
        <th>id</th>
        <th>login</th>
        <th>email</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($userRowError !== ''): ?>
        <tr><td colspan="3"><?= h('ERROR: ' . $userRowError) ?></td></tr>
      <?php elseif (!$userRow): ?>
        <tr><td colspan="3">Brak rekordu.</td></tr>
      <?php else: ?>
        <tr>
          <td><?= (int)($userRow['id'] ?? 0) ?></td>
          <td><?= h((string)($userRow['login'] ?? '')) ?></td>
          <td><?= h((string)($userRow['email'] ?? '')) ?></td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>
</body>
</html>
