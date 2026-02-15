<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/mailbox_service.php';
require_once __DIR__ . '/../config/config.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

$pageTitle = 'Skrzynka';
ensureCrmMailTables($pdo);

$mailAccount = getMailAccountForUser($pdo, (int)$currentUser['id']);
$mailAccountInactive = mailAccountInactiveFound((int)$currentUser['id']);
$debugRows = mailAccountDebugRows((int)$currentUser['id']);
$mailSecretError = '';
if ($mailAccount) {
    try {
        mailSecretKey();
    } catch (Throwable $e) {
        $mailSecretError = $e->getMessage();
    }
}

$mailSyncFlash = $_SESSION['mail_sync_flash'] ?? null;
unset($_SESSION['mail_sync_flash']);

$filter = trim((string)($_GET['filter'] ?? 'assigned'));
if (!in_array($filter, ['assigned', 'unassigned', 'all'], true)) {
    $filter = 'assigned';
}

$messages = [];
if ($mailAccount) {
    $where = 'm.mail_account_id = :account_id';
    if ($filter === 'assigned') {
        $where .= ' AND m.entity_type IS NOT NULL AND m.entity_id IS NOT NULL';
    } elseif ($filter === 'unassigned') {
        $where .= ' AND (m.entity_type IS NULL OR m.entity_id IS NULL)';
    }

    $stmt = $pdo->prepare(
        "SELECT m.*, k.nazwa_firmy AS klient_nazwa, l.nazwa_firmy AS lead_nazwa
         FROM mail_messages AS m
         LEFT JOIN klienci AS k ON k.id = m.entity_id AND m.entity_type = 'client'
         LEFT JOIN leady AS l ON l.id = m.entity_id AND m.entity_type = 'lead'
         WHERE $where
         ORDER BY COALESCE(m.received_at, m.sent_at, m.created_at) DESC
         LIMIT 100"
    );
    $stmt->execute([':account_id' => (int)$mailAccount['id']]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

include 'includes/header.php';
?>
<div class="container-fluid">
  <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <div>
      <h2 class="h4 mb-1">Skrzynka</h2>
      <?php if ($mailAccount): ?>
        <div class="text-muted small">
          Skrzynka: <?= htmlspecialchars($mailAccount['email_address'] ?? '') ?>
          <?php if (!empty($mailAccount['last_sync_at'])): ?>
            • Ostatnia synchronizacja: <?= htmlspecialchars($mailAccount['last_sync_at']) ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="d-flex gap-2">
      <?php if ($mailAccount && $mailSecretError === ''): ?>
        <form method="post" action="mail_sync.php" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
          <input type="hidden" name="account_id" value="<?= (int)$mailAccount['id'] ?>">
          <input type="hidden" name="return_to" value="<?= htmlspecialchars(BASE_URL . '/skrzynka.php?filter=' . urlencode($filter)) ?>">
          <button type="submit" class="btn btn-outline-primary btn-sm">Synchronizuj</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!empty($mailSyncFlash)): ?>
    <div class="alert alert-<?= htmlspecialchars($mailSyncFlash['type'] ?? 'info') ?>">
      <?= htmlspecialchars($mailSyncFlash['msg'] ?? '') ?>
    </div>
  <?php endif; ?>

  <?php if (!$mailAccount): ?>
    <?php
      $uid = (int)($_SESSION['user_id'] ?? 0);
      $login = (string)($_SESSION['login'] ?? '');
      $found = !empty($debugRows) ? 1 : 0;
      $active = 0;
      $dbInfo = ['db' => '-', 'host' => '-'];
      try {
          $dbStmt = $pdo->query('SELECT DATABASE() AS db, @@hostname AS host');
          $dbRow = $dbStmt ? ($dbStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
          $dbInfo['db'] = (string)($dbRow['db'] ?? '-');
          $dbInfo['host'] = (string)($dbRow['host'] ?? '-');
      } catch (Throwable $e) {
          $dbInfo['db'] = 'ERR';
          $dbInfo['host'] = 'ERR';
      }
      error_log(
          '[MAIL_GET] session_uid=' . $uid
          . ' login=' . $login
          . ' db=' . $dbInfo['db']
          . ' host=' . $dbInfo['host']
          . ' found=' . $found
          . ' active=' . $active
      );
      $debugOn = !empty($_GET['debug']);
      $isAdmin = isAdminOverride($currentUser) || normalizeRole($currentUser) === 'Administrator';
      $showDebug = $debugOn || $isAdmin;
    ?>
  <?php endif; ?>
  <?php if (!$mailAccount): ?>
    <div class="alert alert-warning">Brak skonfigurowanego konta pocztowego dla uzytkownika.</div>
  <?php elseif ($mailSecretError !== ''): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($mailSecretError) ?></div>
  <?php endif; ?>
  <?php if (!$mailAccount && $showDebug): ?>
    <div class="alert alert-secondary small">
      Debug: login=<?= htmlspecialchars((string)($_SESSION['login'] ?? '')) ?>,
      uid=<?= (int)($_SESSION['user_id'] ?? 0) ?>,
      rows=<?= count($debugRows) ?>,
      first_id=<?= htmlspecialchars((string)($debugRows[0]['id'] ?? '-')) ?>,
      first_active=<?= htmlspecialchars((string)($debugRows[0]['is_active'] ?? '-')) ?>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 align-items-end">
        <div class="col-md-3">
          <label class="form-label">Filtr</label>
          <select name="filter" class="form-select">
            <option value="assigned" <?= $filter === 'assigned' ? 'selected' : '' ?>>Przypisane</option>
            <option value="unassigned" <?= $filter === 'unassigned' ? 'selected' : '' ?>>Nieprzypisane</option>
            <option value="all" <?= $filter === 'all' ? 'selected' : '' ?>>Wszystkie</option>
          </select>
        </div>
        <div class="col-md-2">
          <button type="submit" class="btn btn-outline-secondary">Filtruj</button>
        </div>
      </form>
    </div>
  </div>

  <div class="card shadow-sm">
    <div class="card-body table-responsive">
      <table class="table table-striped align-middle">
        <thead>
          <tr>
            <th>Data</th>
            <th>Kierunek</th>
            <th>Temat</th>
            <th>Od/Do</th>
            <th>Przypisanie</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$messages): ?>
          <tr>
            <td colspan="5" class="text-center text-muted">Brak wiadomosci.</td>
          </tr>
        <?php else: ?>
          <?php foreach ($messages as $message): ?>
            <?php
              $direction = strtolower((string)($message['direction'] ?? 'in'));
              $directionLabel = $direction === 'out' ? 'OUT' : 'IN';
              $dateVal = $message['received_at'] ?: ($message['sent_at'] ?: $message['created_at']);
              $subjectVal = trim((string)($message['subject'] ?? ''));
              $subjectVal = $subjectVal !== '' ? $subjectVal : '(bez tematu)';
              $fromTo = $direction === 'out' ? ($message['to_email'] ?? $message['to_emails'] ?? '-') : ($message['from_email'] ?? '-');
              $assigned = '-';
              $link = '';
              if ($message['entity_type'] === 'client' && !empty($message['entity_id'])) {
                  $assigned = 'Klient: ' . ($message['klient_nazwa'] ?? ('#' . $message['entity_id']));
                  $link = 'klient_szczegoly.php?id=' . (int)$message['entity_id'] . '#client-mail-pane';
              } elseif ($message['entity_type'] === 'lead' && !empty($message['entity_id'])) {
                  $assigned = 'Lead: ' . ($message['lead_nazwa'] ?? ('#' . $message['entity_id']));
                  $link = 'lead_szczegoly.php?id=' . (int)$message['entity_id'] . '#lead-mail-pane';
              }
            ?>
            <tr>
              <td><?= htmlspecialchars($dateVal ?? '') ?></td>
              <td><span class="badge <?= $direction === 'out' ? 'bg-warning text-dark' : 'bg-success' ?>"><?= htmlspecialchars($directionLabel) ?></span></td>
              <td>
                <?php if ($link !== ''): ?>
                  <a href="<?= htmlspecialchars($link) ?>"><?= htmlspecialchars($subjectVal) ?></a>
                <?php else: ?>
                  <?= htmlspecialchars($subjectVal) ?>
                <?php endif; ?>
              </td>
              <td><?= htmlspecialchars($fromTo !== '' ? $fromTo : '-') ?></td>
              <td><?= htmlspecialchars($assigned) ?></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include 'includes/footer.php'; ?>
