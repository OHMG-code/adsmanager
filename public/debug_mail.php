<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_utils.php';

requireLogin();
$currentUser = currentUser();
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

$isAdmin = isAdminOverride($currentUser) || normalizeRole($currentUser) === 'Administrator';
if (!$isAdmin) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$uid = isset($_GET['uid']) ? (int)$_GET['uid'] : 0;
if ($uid <= 0) {
    $uid = (int)($_SESSION['user_id'] ?? $currentUser['id'] ?? 0);
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fetchDbInfo(PDO $pdo): array {
    $info = [
        'db' => '',
        'db_user' => '',
        'db_host' => '',
        'db_port' => '',
        'error' => '',
    ];
    try {
        $stmt = $pdo->query('SELECT DATABASE() AS db, USER() AS db_user, @@hostname AS db_host, @@port AS db_port');
        $row = $stmt ? ($stmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $info['db'] = (string)($row['db'] ?? '');
        $info['db_user'] = (string)($row['db_user'] ?? '');
        $info['db_host'] = (string)($row['db_host'] ?? '');
        $info['db_port'] = (string)($row['db_port'] ?? '');
    } catch (Throwable $e) {
        $info['error'] = $e->getMessage();
    }
    return $info;
}

function fetchColumns(PDO $pdo, string $table): array {
    $rows = [];
    try {
        $stmt = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
        $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return [];
    }
    return $rows;
}

function pickUserTable(PDO $pdo): string {
    foreach (['uzytkownicy', 'users', 'user'] as $candidate) {
        if (tableExists($pdo, $candidate)) {
            return $candidate;
        }
    }
    return '';
}

$sessionLogin = (string)($_SESSION['login'] ?? $_SESSION['user_login'] ?? '');
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
$sessionRole = (string)($_SESSION['rola'] ?? $_SESSION['user_role'] ?? '');
$sessionSuper = !empty($_SESSION['is_superadmin']) ? '1' : '0';
$adminOverride = isAdminOverride($currentUser) ? '1' : '0';

$dbInfo = fetchDbInfo($pdo);

$mailTableExists = tableExists($pdo, 'mail_accounts');
$mailColumns = $mailTableExists ? fetchColumns($pdo, 'mail_accounts') : [];
$mailCount = null;
$mailCountError = '';
if ($mailTableExists) {
    try {
        $stmt = $pdo->query('SELECT COUNT(*) AS cnt FROM mail_accounts');
        $mailCount = $stmt ? (int)$stmt->fetchColumn() : null;
    } catch (Throwable $e) {
        $mailCountError = $e->getMessage();
    }
}

$sensitiveColumns = [
    'password_enc',
    'smtp_pass',
    'imap_pass',
    'smtp_pass_enc',
    'imap_pass_enc',
];
$mailColumnsDisplay = [];
foreach ($mailColumns as $row) {
    $field = (string)($row['Field'] ?? '');
    if ($field === '' || in_array($field, $sensitiveColumns, true)) {
        continue;
    }
    $mailColumnsDisplay[] = $row;
}

$wantedMailCols = [
    'id',
    'user_id',
    'email_address',
    'username',
    'is_active',
    'imap_host',
    'imap_port',
    'imap_encryption',
    'smtp_host',
    'smtp_port',
    'smtp_encryption',
    'created_at',
    'last_sync_at',
];
$mailAccountRows = [];
$mailAccountError = '';
if ($mailTableExists) {
    $columnNames = array_map(
        static fn(array $row): string => strtolower((string)($row['Field'] ?? '')),
        $mailColumns
    );
    $selectParts = [];
    foreach ($wantedMailCols as $col) {
        if (in_array(strtolower($col), $columnNames, true)) {
            $selectParts[] = '`' . $col . '`';
        } else {
            $selectParts[] = 'NULL AS `' . $col . '`';
        }
    }
    try {
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $selectParts) . ' FROM mail_accounts WHERE user_id = :uid');
        $stmt->execute([':uid' => $uid]);
        $mailAccountRows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        $mailAccountError = $e->getMessage();
    }
}

$userTable = pickUserTable($pdo);
$userRow = null;
$userError = '';
if ($userTable !== '') {
    $userColumns = fetchColumns($pdo, $userTable);
    $userColumnNames = array_map(
        static fn(array $row): string => strtolower((string)($row['Field'] ?? '')),
        $userColumns
    );
    $wantedUserCols = ['id', 'login', 'rola', 'email'];
    $selectParts = [];
    foreach ($wantedUserCols as $col) {
        if (in_array(strtolower($col), $userColumnNames, true)) {
            $selectParts[] = '`' . $col . '`';
        } else {
            $selectParts[] = 'NULL AS `' . $col . '`';
        }
    }
    try {
        $stmt = $pdo->prepare('SELECT ' . implode(', ', $selectParts) . ' FROM `' . $userTable . '` WHERE id = :uid');
        $stmt->execute([':uid' => $uid]);
        $userRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $userError = $e->getMessage();
    }
}

$accountFound = $mailTableExists && !empty($mailAccountRows);
?><!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <title>Debug mail</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; color: #1b1b1b; }
    table { border-collapse: collapse; width: 100%; margin: 8px 0 18px; }
    th, td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; vertical-align: top; }
    th { background: #f3f3f3; }
    .section-title { margin-top: 18px; font-size: 18px; }
    .note { font-size: 13px; color: #555; }
    .warn { color: #a23; font-weight: 600; }
  </style>
</head>
<body>
  <h1>Debug mail</h1>
  <div class="note">Use: <code>?uid=2</code>. Default uid = session user_id.</div>

  <div class="section-title">SESSION</div>
  <table>
    <tr><th>login</th><td><?= h($sessionLogin !== '' ? $sessionLogin : '-') ?></td></tr>
    <tr><th>user_id</th><td><?= (int)$sessionUserId ?></td></tr>
    <tr><th>rola</th><td><?= h($sessionRole !== '' ? $sessionRole : '-') ?></td></tr>
    <tr><th>admin_override</th><td><?= h($adminOverride) ?></td></tr>
    <tr><th>is_superadmin</th><td><?= h($sessionSuper) ?></td></tr>
  </table>

  <div class="section-title">DB environment</div>
  <table>
    <tr><th>db</th><td><?= h($dbInfo['db'] !== '' ? $dbInfo['db'] : '-') ?></td></tr>
    <tr><th>db_user</th><td><?= h($dbInfo['db_user'] !== '' ? $dbInfo['db_user'] : '-') ?></td></tr>
    <tr><th>db_host</th><td><?= h($dbInfo['db_host'] !== '' ? $dbInfo['db_host'] : '-') ?></td></tr>
    <tr><th>db_port</th><td><?= h($dbInfo['db_port'] !== '' ? $dbInfo['db_port'] : '-') ?></td></tr>
    <tr><th>error</th><td><?= h($dbInfo['error'] !== '' ? $dbInfo['error'] : '-') ?></td></tr>
  </table>
  <div class="note warn">If DB here differs from phpMyAdmin, the app connects to a different database.</div>

  <div class="section-title">mail_accounts validation</div>
  <table>
    <tr><th>SHOW TABLES LIKE 'mail_accounts'</th><td><?= $mailTableExists ? 'YES' : 'NO' ?></td></tr>
    <tr><th>COUNT(*)</th><td><?= $mailCountError !== '' ? h('ERROR: ' . $mailCountError) : (string)($mailCount ?? '-') ?></td></tr>
  </table>

  <div class="section-title">mail_accounts columns (sensitive hidden)</div>
  <table>
    <thead>
      <tr>
        <th>Field</th>
        <th>Type</th>
        <th>Null</th>
        <th>Key</th>
        <th>Default</th>
        <th>Extra</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$mailTableExists): ?>
        <tr><td colspan="6">Table does not exist.</td></tr>
      <?php elseif (!$mailColumnsDisplay): ?>
        <tr><td colspan="6">No columns to display (sensitive columns hidden).</td></tr>
      <?php else: ?>
        <?php foreach ($mailColumnsDisplay as $col): ?>
          <tr>
            <td><?= h((string)($col['Field'] ?? '')) ?></td>
            <td><?= h((string)($col['Type'] ?? '')) ?></td>
            <td><?= h((string)($col['Null'] ?? '')) ?></td>
            <td><?= h((string)($col['Key'] ?? '')) ?></td>
            <td><?= h((string)($col['Default'] ?? '')) ?></td>
            <td><?= h((string)($col['Extra'] ?? '')) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="section-title">mail_accounts rows for uid=<?= (int)$uid ?></div>
  <table>
    <thead>
      <tr>
        <?php foreach ($wantedMailCols as $col): ?>
          <th><?= h($col) ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php if ($mailAccountError !== ''): ?>
        <tr><td colspan="<?= count($wantedMailCols) ?>"><?= h('ERROR: ' . $mailAccountError) ?></td></tr>
      <?php elseif (!$mailTableExists): ?>
        <tr><td colspan="<?= count($wantedMailCols) ?>">Table does not exist.</td></tr>
      <?php elseif (!$mailAccountRows): ?>
        <tr><td colspan="<?= count($wantedMailCols) ?>">No rows.</td></tr>
      <?php else: ?>
        <?php foreach ($mailAccountRows as $row): ?>
          <tr>
            <?php foreach ($wantedMailCols as $col): ?>
              <td><?= h((string)($row[$col] ?? '')) ?></td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="section-title">User validation (table: <?= h($userTable !== '' ? $userTable : '-') ?>)</div>
  <table>
    <thead>
      <tr>
        <th>id</th>
        <th>login</th>
        <th>rola</th>
        <th>email</th>
      </tr>
    </thead>
    <tbody>
      <?php if ($userTable === ''): ?>
        <tr><td colspan="4">User table not found.</td></tr>
      <?php elseif ($userError !== ''): ?>
        <tr><td colspan="4"><?= h('ERROR: ' . $userError) ?></td></tr>
      <?php elseif (!$userRow): ?>
        <tr><td colspan="4">No user row.</td></tr>
      <?php else: ?>
        <tr>
          <td><?= (int)($userRow['id'] ?? 0) ?></td>
          <td><?= h((string)($userRow['login'] ?? '')) ?></td>
          <td><?= h((string)($userRow['rola'] ?? '')) ?></td>
          <td><?= h((string)($userRow['email'] ?? '')) ?></td>
        </tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div class="section-title">Conclusion</div>
  <pre>mail_accounts record for uid=<?= (int)$uid ?>: <?= $accountFound ? 'FOUND' : 'NOT FOUND' ?><?php if (!$accountFound): ?>
Najczestsza przyczyna: zapis nie wykonuje INSERT albo zapisuje pod innym uid.
<?php endif; ?></pre>
</body>
</html>
