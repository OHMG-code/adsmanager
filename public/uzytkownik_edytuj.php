<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
requireLogin();

require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/crypto.php';
$pageTitle = 'Zespół i uprawnienia - Poczta użytkownika i podpis';
ensureSystemConfigColumns($pdo);
ensureUserColumns($pdo);
ensureCrmMailTables($pdo);

function mailFormDefaultsForSmtp(): array {
    return [
        'smtp_enabled' => 0,
        'smtp_host' => '',
        'smtp_port' => '',
        'smtp_secure' => 'tls',
        'smtp_user' => '',
        'smtp_pass_enc' => '',
        'smtp_from_email' => '',
        'smtp_from_name' => '',
        'imap_enabled' => 0,
        'imap_host' => '',
        'imap_port' => '',
        'imap_secure' => 'tls',
        'imap_user' => '',
        'imap_mailbox' => 'INBOX',
        'imap_pass_enc' => '',
    ];
}

function mapMailEncryptionForForm(?string $value, string $default): string {
    $normalized = strtolower(trim((string)$value));
    if ($normalized === 'none') {
        return '';
    }
    if (in_array($normalized, ['tls', 'ssl'], true)) {
        return $normalized;
    }
    return $default;
}

function fetchMailAccountForUser(PDO $pdo, int $userId): ?array {
    if ($userId <= 0) {
        return null;
    }
    try {
        $stmt = $pdo->prepare('SELECT * FROM mail_accounts WHERE user_id = :uid LIMIT 1');
        $stmt->execute([':uid' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('uzytkownik_edytuj: cannot load mail account: ' . $e->getMessage());
        return null;
    }
}

function mapMailAccountToSmtpForm(?array $mailAccount): array {
    $form = mailFormDefaultsForSmtp();
    if (!$mailAccount) {
        return $form;
    }

    $smtpHost = trim((string)($mailAccount['smtp_host'] ?? ''));
    $imapHost = trim((string)($mailAccount['imap_host'] ?? ''));
    $isActive = !empty($mailAccount['is_active']);
    $form['smtp_enabled'] = !empty($mailAccount['smtp_enabled']) || ($isActive && $smtpHost !== '') ? 1 : 0;
    $form['imap_enabled'] = !empty($mailAccount['imap_enabled']) || ($isActive && $imapHost !== '') ? 1 : 0;
    $form['smtp_host'] = $smtpHost;
    $form['smtp_port'] = (int)($mailAccount['smtp_port'] ?? 0) ?: '';
    $form['smtp_secure'] = mapMailEncryptionForForm($mailAccount['smtp_encryption'] ?? null, $form['smtp_secure']);
    $form['smtp_user'] = trim((string)($mailAccount['smtp_user'] ?? ($mailAccount['username'] ?? '')));
    $form['smtp_from_email'] = trim((string)($mailAccount['email_address'] ?? ''));
    $form['smtp_from_name'] = trim((string)($mailAccount['smtp_from_name'] ?? ''));

    $form['imap_host'] = $imapHost;
    $form['imap_port'] = (int)($mailAccount['imap_port'] ?? 0) ?: '';
    $form['imap_secure'] = mapMailEncryptionForForm($mailAccount['imap_encryption'] ?? null, $form['imap_secure']);
    $form['imap_user'] = trim((string)($mailAccount['imap_user'] ?? ($mailAccount['username'] ?? '')));
    $form['imap_mailbox'] = trim((string)($mailAccount['imap_mailbox'] ?? 'INBOX')) ?: 'INBOX';

    $hasPassword = !empty($mailAccount['password_enc']) || !empty($mailAccount['smtp_pass_enc']) || !empty($mailAccount['imap_pass_enc']);
    $form['smtp_pass_enc'] = $hasPassword ? 'set' : '';
    $form['imap_pass_enc'] = $hasPassword ? 'set' : '';

    return $form;
}

$userColumns = getTableColumns($pdo, 'uzytkownicy');
$userSelect = [
    'u.`id`',
    'u.`login`',
    selectOrEmpty('u', 'email', $userColumns),
    selectOrEmpty('u', 'rola', $userColumns),
    selectOrEmpty('u', 'imie', $userColumns),
    selectOrEmpty('u', 'nazwisko', $userColumns),
    selectOrEmpty('u', 'smtp_enabled', $userColumns),
    selectOrEmpty('u', 'smtp_host', $userColumns),
    selectOrEmpty('u', 'smtp_port', $userColumns),
    selectOrEmpty('u', 'smtp_secure', $userColumns),
    selectOrEmpty('u', 'smtp_user', $userColumns),
    selectOrEmpty('u', 'smtp_pass_enc', $userColumns),
    selectOrEmpty('u', 'smtp_from_email', $userColumns),
    selectOrEmpty('u', 'smtp_from_name', $userColumns),
    selectOrEmpty('u', 'email_signature', $userColumns),
];

$currentUserStmt = $pdo->prepare('SELECT ' . implode(', ', $userSelect) . ' FROM uzytkownicy AS u WHERE u.`id` = :id');
$currentUserStmt->execute([':id' => $_SESSION['user_id']]);
$currentUser = $currentUserStmt->fetch(PDO::FETCH_ASSOC);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

$canManageSystem = canManageSystem($currentUser);
if (!$canManageSystem) {
    http_response_code(403);
    include 'includes/header.php';
    echo '<div class="alert alert-danger">Brak uprawnien do edycji SMTP.</div>';
    include 'includes/footer.php';
    exit;
}

$userId = isset($_GET['id']) ? max(1, (int)$_GET['id']) : (int)$currentUser['id'];
$isAdmin = normalizeRole($currentUser) === 'Administrator';

$stmt = $pdo->prepare('SELECT ' . implode(', ', $userSelect) . ' FROM uzytkownicy AS u WHERE u.`id` = :id');
$stmt->execute([':id' => $userId]);
$editedUser = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$editedUser) {
    http_response_code(404);
    include 'includes/header.php';
    echo '<div class="alert alert-warning">Nie znaleziono uzytkownika.</div>';
    include 'includes/footer.php';
    exit;
}
$mailAccount = fetchMailAccountForUser($pdo, (int)$editedUser['id']);
$editedUser = array_merge($editedUser, mapMailAccountToSmtpForm($mailAccount));

$errors = [];
$success = false;
$csrfToken = getCsrfToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Niepoprawny token CSRF.';
    }
    $postedUserId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $smtpEnabled    = isset($_POST['smtp_enabled']) ? 1 : 0;
    $smtpHost       = trim($_POST['smtp_host'] ?? '');
    $smtpPort       = (int)($_POST['smtp_port'] ?? 0);
    $smtpSecure     = in_array($_POST['smtp_secure'] ?? '', ['', 'tls', 'ssl'], true) ? (string)($_POST['smtp_secure'] ?? '') : '';
    $smtpUser       = trim($_POST['smtp_user'] ?? '');
    $smtpPassInput  = (string)($_POST['smtp_pass'] ?? '');
    $smtpPassClear  = !empty($_POST['smtp_pass_clear']);
    $smtpFromEmail  = trim($_POST['smtp_from_email'] ?? '');
    $smtpFromName   = trim($_POST['smtp_from_name'] ?? '');

    $imapEnabled    = isset($_POST['imap_enabled']) ? 1 : 0;
    $imapHost       = trim($_POST['imap_host'] ?? '');
    $imapPort       = (int)($_POST['imap_port'] ?? 0);
    $imapSecure     = in_array($_POST['imap_secure'] ?? '', ['', 'tls', 'ssl'], true) ? (string)($_POST['imap_secure'] ?? '') : '';
    $imapUser       = trim($_POST['imap_user'] ?? '');
    $imapPassInput  = (string)($_POST['imap_pass'] ?? '');
    $imapPassClear  = !empty($_POST['imap_pass_clear']);
    $imapMailbox    = trim($_POST['imap_mailbox'] ?? 'INBOX');
    if ($imapMailbox === '') {
        $imapMailbox = 'INBOX';
    }

    $emailSignature = trim($_POST['email_signature'] ?? '');

    if ($postedUserId <= 0 || $postedUserId !== $userId) {
        $errors[] = 'Nieprawidlowy identyfikator edytowanego uzytkownika.';
    }
    if ($smtpFromEmail !== '' && !filter_var($smtpFromEmail, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Adres e-mail nadawcy jest nieprawidlowy.';
    }

    if (empty($errors)) {
        try {
            $update = $pdo->prepare('UPDATE uzytkownicy SET email_signature = :email_signature WHERE id = :id');
            $update->execute([
                ':email_signature' => $emailSignature !== '' ? $emailSignature : null,
                ':id' => $editedUser['id'],
            ]);
            $editedUser['email_signature'] = $emailSignature;
        } catch (Throwable $e) {
            error_log('[MAIL_SAVE] exception: ' . $e);
            $errors[] = 'Nie udalo sie zapisac zmian uzytkownika.';
        }
    }

    if (empty($errors)) {
        $mailEmail = $smtpFromEmail !== '' ? $smtpFromEmail : trim((string)($editedUser['email'] ?? ''));
        $mailUsername = $imapUser !== '' ? $imapUser : $smtpUser;
        $smtpEncryption = $smtpSecure !== '' ? $smtpSecure : 'none';
        $imapEncryption = $imapSecure !== '' ? $imapSecure : 'none';
        $smtpPort = $smtpPort > 0 ? $smtpPort : 587;
        $imapPort = $imapPort > 0 ? $imapPort : 993;
        $isActive = ($smtpEnabled || $imapEnabled) ? 1 : 0;
        $passwordAction = 'keep';
        $mailPassword = '';
        if ($imapPassClear || $smtpPassClear) {
            $passwordAction = 'clear';
        } elseif ($imapPassInput !== '') {
            $passwordAction = 'set';
            $mailPassword = $imapPassInput;
        } elseif ($smtpPassInput !== '') {
            $passwordAction = 'set';
            $mailPassword = $smtpPassInput;
        }

        try {
            $existingStmt = $pdo->prepare('SELECT id FROM mail_accounts WHERE user_id = :user_id LIMIT 1');
            $existingStmt->execute([':user_id' => (int)$editedUser['id']]);
            $existingId = (int)$existingStmt->fetchColumn();
            $params = [
                ':user_id' => (int)$editedUser['id'],
                ':imap_host' => $imapHost,
                ':imap_port' => $imapPort,
                ':imap_encryption' => $imapEncryption,
                ':imap_mailbox' => $imapMailbox,
                ':smtp_host' => $smtpHost,
                ':smtp_port' => $smtpPort,
                ':smtp_encryption' => $smtpEncryption,
                ':email_address' => $mailEmail,
                ':username' => $mailUsername,
                ':smtp_from_name' => $smtpFromName,
                ':is_active' => $isActive,
            ];
            if ($existingId > 0) {
                $updateSql = 'UPDATE mail_accounts SET
                    imap_host = :imap_host,
                    imap_port = :imap_port,
                    imap_encryption = :imap_encryption,
                    imap_mailbox = :imap_mailbox,
                    smtp_host = :smtp_host,
                    smtp_port = :smtp_port,
                    smtp_encryption = :smtp_encryption,
                    email_address = :email_address,
                    username = :username,
                    smtp_from_name = :smtp_from_name,
                    is_active = :is_active';
                if ($passwordAction === 'set') {
                    $updateSql .= ', password_enc = :password_enc';
                    $params[':password_enc'] = encryptMailSecret($mailPassword);
                } elseif ($passwordAction === 'clear') {
                    $updateSql .= ', password_enc = NULL';
                }
                $updateSql .= ' WHERE user_id = :user_id';
                $mailStmt = $pdo->prepare($updateSql);
                $mailStmt->execute($params);
            } else {
                $insertSql = 'INSERT INTO mail_accounts
                    (user_id, imap_host, imap_port, imap_encryption, imap_mailbox, smtp_host, smtp_port, smtp_encryption, email_address, username, smtp_from_name, password_enc, is_active)
                 VALUES
                    (:user_id, :imap_host, :imap_port, :imap_encryption, :imap_mailbox, :smtp_host, :smtp_port, :smtp_encryption, :email_address, :username, :smtp_from_name, :password_enc, :is_active)';
                if ($passwordAction === 'set') {
                    $params[':password_enc'] = encryptMailSecret($mailPassword);
                } else {
                    $params[':password_enc'] = null;
                }
                $mailStmt = $pdo->prepare($insertSql);
                $mailStmt->execute($params);
            }
            $success = true;
            $mailAccount = fetchMailAccountForUser($pdo, (int)$editedUser['id']);
            $editedUser = array_merge($editedUser, mapMailAccountToSmtpForm($mailAccount));
        } catch (Throwable $e) {
            error_log('[MAIL_SAVE] exception: ' . $e);
            $errors[] = 'Nie udalo sie zapisac konfiguracji poczty.';
        }
    }

    if (!$success && empty($errors)) {
        $errors[] = 'Nie udalo sie zapisac zmian.';
    }

    if ($errors) {
        $editedUser['smtp_enabled'] = $smtpEnabled;
        $editedUser['smtp_host'] = $smtpHost;
        $editedUser['smtp_port'] = $smtpPort;
        $editedUser['smtp_secure'] = $smtpSecure;
        $editedUser['smtp_user'] = $smtpUser;
        $editedUser['smtp_from_email'] = $smtpFromEmail;
        $editedUser['smtp_from_name'] = $smtpFromName;
        $editedUser['imap_enabled'] = $imapEnabled;
        $editedUser['imap_host'] = $imapHost;
        $editedUser['imap_port'] = $imapPort;
        $editedUser['imap_secure'] = $imapSecure;
        $editedUser['imap_user'] = $imapUser;
        $editedUser['imap_mailbox'] = $imapMailbox;
        if ($smtpPassClear || $imapPassClear || $smtpPassInput !== '' || $imapPassInput !== '') {
            $passFlag = ($smtpPassClear || $imapPassClear) ? '' : 'set';
            $editedUser['smtp_pass_enc'] = $passFlag;
            $editedUser['imap_pass_enc'] = $passFlag;
        }
    }
}

include 'includes/header.php';
$displayName = trim(((string)($editedUser['imie'] ?? '')) . ' ' . ((string)($editedUser['nazwisko'] ?? '')));
if ($displayName === '') {
    $displayName = $editedUser['login'] ?? ('Uzytkownik #' . $editedUser['id']);
}
?>
<div class="container-fluid py-3">
  <div class="card shadow-sm mb-4">
    <div class="card-body">
      <p class="text-uppercase text-muted fw-semibold small mb-1">Zespół i uprawnienia</p>
      <h1 class="h3 mb-2">Poczta użytkownika i podpis</h1>
      <p class="text-muted mb-0">Legacy ekran pomocniczy dla zaawansowanej konfiguracji poczty i podpisu e-mail.</p>
    </div>
  </div>
  <div class="d-flex justify-content-between align-items-center mb-3">
    <div>
      <h2 class="h4 mb-0">Uzytkownik: <?= htmlspecialchars($displayName ?: '-') ?></h2>
      <small class="text-muted">Login: <?= htmlspecialchars($editedUser['login']) ?></small>
    </div>
    <div>
      <?php if ($isAdmin): ?>
        <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/uzytkownicy.php">Powrót do zespołu</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($success): ?>
    <div class="alert alert-success">Zapisano.</div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $error): ?>
          <li><?= htmlspecialchars($error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        <input type="hidden" name="user_id" value="<?= (int)$editedUser['id'] ?>">
        <div class="row g-4">
          <div class="col-12">
            <div class="fw-semibold text-muted small text-uppercase">SMTP</div>
          </div>
          <div class="col-md-4">
            <div class="form-check form-switch mt-4">
              <input class="form-check-input" type="checkbox" role="switch" id="smtpEnabled" name="smtp_enabled"
                     value="1" <?= !empty($editedUser['smtp_enabled']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="smtpEnabled">SMTP aktywne</label>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Host SMTP</label>
            <input type="text" class="form-control" name="smtp_host" value="<?= htmlspecialchars($editedUser['smtp_host'] ?? '') ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Port</label>
            <input type="number" class="form-control" name="smtp_port" value="<?= htmlspecialchars((string)($editedUser['smtp_port'] ?? '')) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Szyfrowanie</label>
            <?php $smtpSecure = $editedUser['smtp_secure'] ?? 'tls'; ?>
            <select name="smtp_secure" class="form-select">
              <option value="" <?= $smtpSecure === '' ? 'selected' : '' ?>>Brak</option>
              <option value="tls" <?= $smtpSecure === 'tls' ? 'selected' : '' ?>>TLS</option>
              <option value="ssl" <?= $smtpSecure === 'ssl' ? 'selected' : '' ?>>SSL</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">SMTP user</label>
            <input type="text" class="form-control" name="smtp_user" value="<?= htmlspecialchars($editedUser['smtp_user'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">SMTP haslo</label>
            <input type="password" class="form-control" name="smtp_pass" placeholder="Pozostaw puste bez zmian">
            <?php if (!empty($editedUser['smtp_pass_enc'])): ?>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="smtp_pass_clear" name="smtp_pass_clear" value="1">
                <label class="form-check-label" for="smtp_pass_clear">Usun zapisane haslo</label>
              </div>
            <?php endif; ?>
          </div>
          <div class="col-md-4">
            <label class="form-label">From email</label>
            <input type="email" class="form-control" name="smtp_from_email" value="<?= htmlspecialchars($editedUser['smtp_from_email'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">From name</label>
            <input type="text" class="form-control" name="smtp_from_name" value="<?= htmlspecialchars($editedUser['smtp_from_name'] ?? '') ?>">
          </div>

          <div class="col-12 pt-2">
            <div class="fw-semibold text-muted small text-uppercase">IMAP</div>
          </div>
          <div class="col-md-4">
            <div class="form-check form-switch mt-4">
              <input class="form-check-input" type="checkbox" role="switch" id="imapEnabled" name="imap_enabled"
                     value="1" <?= !empty($editedUser['imap_enabled']) ? 'checked' : '' ?>>
              <label class="form-check-label" for="imapEnabled">IMAP aktywny</label>
            </div>
          </div>
          <div class="col-md-4">
            <label class="form-label">Host IMAP</label>
            <input type="text" class="form-control" name="imap_host" value="<?= htmlspecialchars($editedUser['imap_host'] ?? '') ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Port</label>
            <input type="number" class="form-control" name="imap_port" value="<?= htmlspecialchars((string)($editedUser['imap_port'] ?? '')) ?>">
          </div>
          <div class="col-md-2">
            <label class="form-label">Szyfrowanie</label>
            <?php $imapSecure = $editedUser['imap_secure'] ?? 'tls'; ?>
            <select name="imap_secure" class="form-select">
              <option value="" <?= $imapSecure === '' ? 'selected' : '' ?>>Brak</option>
              <option value="tls" <?= $imapSecure === 'tls' ? 'selected' : '' ?>>TLS</option>
              <option value="ssl" <?= $imapSecure === 'ssl' ? 'selected' : '' ?>>SSL</option>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">IMAP user</label>
            <input type="text" class="form-control" name="imap_user" value="<?= htmlspecialchars($editedUser['imap_user'] ?? '') ?>">
          </div>
          <div class="col-md-4">
            <label class="form-label">IMAP haslo</label>
            <input type="password" class="form-control" name="imap_pass" placeholder="Pozostaw puste bez zmian">
            <?php if (!empty($editedUser['imap_pass_enc'])): ?>
              <div class="form-check mt-2">
                <input class="form-check-input" type="checkbox" id="imap_pass_clear" name="imap_pass_clear" value="1">
                <label class="form-check-label" for="imap_pass_clear">Usun zapisane haslo</label>
              </div>
            <?php endif; ?>
          </div>
          <div class="col-md-4">
            <label class="form-label">Folder (mailbox)</label>
            <input type="text" class="form-control" name="imap_mailbox" value="<?= htmlspecialchars($editedUser['imap_mailbox'] ?? 'INBOX') ?>">
          </div>
        </div>

        <div class="mt-4">
          <label class="form-label">Podpis e-mail</label>
          <textarea class="form-control" rows="4" name="email_signature"><?= htmlspecialchars($editedUser['email_signature'] ?? '') ?></textarea>
          <div class="form-text">Zostanie dodany pod trescia wiadomosci.</div>
        </div>

        <div class="mt-4 d-flex justify-content-end gap-2">
          <?php if ($isAdmin): ?>
            <a class="btn btn-outline-secondary" href="<?= htmlspecialchars(BASE_URL) ?>/uzytkownicy.php">Powrót do zespołu</a>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary">Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php include 'includes/footer.php'; ?>
