<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/auth.php';

ensureSystemConfigColumns($pdo);
ensureUserColumns($pdo);

$pageTitle = 'Dodaj użytkownika';
$errors = [];
$success = false;

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

requireRole(['Administrator']);

$userColumns = getTableColumns($pdo, 'uzytkownicy');
$hasPasswordColumn = hasColumn($userColumns, 'haslo_hash');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Nieprawidłowy token bezpieczeństwa.';
    } else {
        if (!$hasPasswordColumn) {
            $errors[] = 'Brak kolumny na hasło (haslo_hash) w tabeli uzytkownicy.';
        }
        $login    = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        $email    = trim($_POST['email'] ?? '');
        $phone    = trim($_POST['telefon'] ?? '');
        $function = trim($_POST['funkcja'] ?? '');
        $role     = $_POST['rola'] ?? 'Handlowiec';
        $first    = trim($_POST['imie'] ?? '');
        $last     = trim($_POST['nazwisko'] ?? '');

        $allowedRoles = ['Administrator', 'Manager', 'Handlowiec'];
        if (!in_array($role, $allowedRoles, true)) {
            $role = 'Handlowiec';
        }

        if ($role === 'Handlowiec' && $function === '') {
            $function = DEFAULT_HANDLOWIEC_FUNCTION;
        }

        if ($login === '') {
            $errors[] = 'Login jest wymagany.';
        }
        if ($password === '') {
            $errors[] = 'Hasło jest wymagane.';
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Podaj poprawny adres e-mail.';
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM uzytkownicy WHERE login = :login');
        $stmt->execute([':login' => $login]);
        if ((int)$stmt->fetchColumn() > 0) {
            $errors[] = 'Podany login już istnieje.';
        }

        if (empty($errors)) {
            $columns = ['login', 'haslo_hash'];
            $placeholders = [':login', ':haslo_hash'];
            $params = [
                ':login'      => $login,
                ':haslo_hash' => password_hash($password, PASSWORD_DEFAULT),
            ];

            if (hasColumn($userColumns, 'email')) {
                $columns[] = 'email';
                $placeholders[] = ':email';
                $params[':email'] = $email !== '' ? $email : null;
            }
            if (hasColumn($userColumns, 'rola')) {
                $columns[] = 'rola';
                $placeholders[] = ':rola';
                $params[':rola'] = $role;
            }
            if (hasColumn($userColumns, 'imie')) {
                $columns[] = 'imie';
                $placeholders[] = ':imie';
                $params[':imie'] = $first !== '' ? $first : null;
            }
            if (hasColumn($userColumns, 'nazwisko')) {
                $columns[] = 'nazwisko';
                $placeholders[] = ':nazwisko';
                $params[':nazwisko'] = $last !== '' ? $last : null;
            }
            if (hasColumn($userColumns, 'telefon')) {
                $columns[] = 'telefon';
                $placeholders[] = ':telefon';
                $params[':telefon'] = $phone !== '' ? $phone : null;
            }
            if (hasColumn($userColumns, 'funkcja')) {
                $columns[] = 'funkcja';
                $placeholders[] = ':funkcja';
                $params[':funkcja'] = $function !== '' ? $function : null;
            }
            if (hasColumn($userColumns, 'aktywny')) {
                $columns[] = 'aktywny';
                $placeholders[] = ':aktywny';
                $params[':aktywny'] = 1;
            }

            $sql = 'INSERT INTO uzytkownicy (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')';
            $success = $pdo->prepare($sql)->execute($params);
            if ($success) {
                header('Location: ' . BASE_URL . '/uzytkownicy.php');
                exit;
            } else {
                $errors[] = 'Nie udało się dodać użytkownika.';
            }
        }
    }
}

$csrf = getCsrfToken();

include 'includes/header.php';
?>
<div class="container-fluid">
  <div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h4 mb-0">Dodaj użytkownika</h2>
    <a class="btn btn-outline-secondary" href="uzytkownicy.php">← Powrót</a>
  </div>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
          <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="card shadow-sm">
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label">Login *</label>
            <input type="text" name="login" class="form-control" required value="<?= htmlspecialchars($_POST['login'] ?? '') ?>">
          </div>
          <div class="col-md-6">
            <label class="form-label">Hasło *</label>
            <input type="password" name="password" class="form-control" required>
          </div>
        </div>

        <?php if (hasColumn($userColumns, 'email')): ?>
        <div class="row g-3 mt-2">
          <div class="col-md-6">
            <label class="form-label">E-mail</label>
            <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
          </div>
          <?php if (hasColumn($userColumns, 'telefon')): ?>
          <div class="col-md-6">
            <label class="form-label">Telefon</label>
            <input type="text" name="telefon" class="form-control" value="<?= htmlspecialchars($_POST['telefon'] ?? '') ?>">
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (hasColumn($userColumns, 'imie') || hasColumn($userColumns, 'nazwisko')): ?>
        <div class="row g-3 mt-2">
          <?php if (hasColumn($userColumns, 'imie')): ?>
            <div class="col-md-6">
              <label class="form-label">Imię</label>
              <input type="text" name="imie" class="form-control" value="<?= htmlspecialchars($_POST['imie'] ?? '') ?>">
            </div>
          <?php endif; ?>
          <?php if (hasColumn($userColumns, 'nazwisko')): ?>
            <div class="col-md-6">
              <label class="form-label">Nazwisko</label>
              <input type="text" name="nazwisko" class="form-control" value="<?= htmlspecialchars($_POST['nazwisko'] ?? '') ?>">
            </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php if (hasColumn($userColumns, 'funkcja')): ?>
        <div class="mt-3">
          <label class="form-label">Funkcja</label>
          <input type="text" name="funkcja" class="form-control" value="<?= htmlspecialchars($_POST['funkcja'] ?? '') ?>" placeholder="<?= htmlspecialchars(DEFAULT_HANDLOWIEC_FUNCTION) ?>">
        </div>
        <?php endif; ?>

        <?php if (hasColumn($userColumns, 'rola')): ?>
        <div class="mt-3">
          <label class="form-label">Rola</label>
          <select name="rola" class="form-select">
            <?php
            $roleVal = $_POST['rola'] ?? 'Handlowiec';
            $options = ['Administrator' => 'Administrator', 'Manager' => 'Manager', 'Handlowiec' => 'Handlowiec'];
            foreach ($options as $val => $label):
            ?>
              <option value="<?= htmlspecialchars($val) ?>" <?= $roleVal === $val ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <div class="mt-4 d-flex justify-content-end gap-2">
          <a class="btn btn-outline-secondary" href="uzytkownicy.php">Anuluj</a>
          <button type="submit" class="btn btn-primary">Dodaj</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php include 'includes/footer.php'; ?>

