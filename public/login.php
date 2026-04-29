<?php
require_once __DIR__ . '/includes/config.php';
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';

$error = '';
$themeAssetCandidates = [
    __DIR__ . '/assets/css/themes/tokens.css',
    __DIR__ . '/assets/css/themes/theme-light.css',
    __DIR__ . '/assets/css/themes/theme-dark.css',
    __DIR__ . '/assets/css/themes/overrides-bootstrap.css',
    __DIR__ . '/assets/css/style.css',
    __DIR__ . '/assets/js/theme.js',
];
$themeAssetVersion = 0;
foreach ($themeAssetCandidates as $themeAssetPath) {
    $mtime = @filemtime($themeAssetPath);
    if ($mtime !== false && $mtime > $themeAssetVersion) {
        $themeAssetVersion = (int)$mtime;
    }
}
if ($themeAssetVersion <= 0) {
    $themeAssetVersion = time();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = isset($_POST['login']) ? trim($_POST['login']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    
    if (empty($login) || empty($password)) {
        $error = "Wypełnij wszystkie pola!";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM uzytkownicy WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch();
        if ($user) {
            if (password_verify($password, $user['haslo_hash'])) {
                $normalizedRole = normalizeRole($user);
                $_SESSION['user_id'] = (int)$user['id'];
                $_SESSION['login'] = $user['login'];
                $_SESSION['user_login'] = $user['login'];
                $_SESSION['rola'] = $normalizedRole;
                $_SESSION['user_role'] = $normalizedRole;
                if (strtolower($user['login']) === 'admin' || (int)$user['id'] === 1) {
                    $_SESSION['rola'] = 'Administrator';
                    $_SESSION['user_role'] = 'Administrator';
                    $_SESSION['is_superadmin'] = true;
                }
                $_SESSION['user_email'] = $user['email'] ?? '';
                header('Location: ' . BASE_URL . '/dashboard.php');
                exit;
            } else {
                $error = "Niepoprawne hasło!";
            }
        } else {
            $error = "Niepoprawny login!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Logowanie - Ads Manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <script>
    (function () {
      var key = 'adsmanager_theme';
      var theme = 'light';
      try {
        var stored = localStorage.getItem(key);
        if (stored === 'dark' || stored === 'light') {
          theme = stored;
        }
      } catch (e) {}
      document.documentElement.setAttribute('data-theme', theme);
      document.documentElement.style.colorScheme = theme === 'dark' ? 'dark' : 'light';
    })();
  </script>
  <!-- Bootstrap CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/themes/tokens.css?v=<?= (int)$themeAssetVersion ?>">
  <link rel="stylesheet" href="assets/css/themes/theme-light.css?v=<?= (int)$themeAssetVersion ?>">
  <link rel="stylesheet" href="assets/css/themes/theme-dark.css?v=<?= (int)$themeAssetVersion ?>">
  <link rel="stylesheet" href="assets/css/style.css?v=<?= (int)$themeAssetVersion ?>">
  <link rel="stylesheet" href="assets/css/themes/overrides-bootstrap.css?v=<?= (int)$themeAssetVersion ?>">
</head>
<body>
  <div class="login-form">
    <h2 class="text-center">Logowanie</h2>
    <?php if ($error): ?>
      <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <form action="<?= BASE_URL ?>/login.php" method="post">
      <div class="mb-3">
        <label for="login" class="form-label">Login:</label>
        <input type="text" name="login" id="login" class="form-control" required>
      </div>
      <div class="mb-3">
        <label for="password" class="form-label">Hasło:</label>
        <input type="password" name="password" id="password" class="form-control" required>
      </div>
      <p class="mt-2"><a href="reset.php">Nie pamiętasz hasła?</a></p>
      <button type="submit" class="btn btn-primary w-100">Zaloguj się</button>
    </form>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/theme.js?v=<?= (int)$themeAssetVersion ?>"></script>
</body>
</html>
