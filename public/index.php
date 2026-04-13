<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
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
if (isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Logowanie - Ads Manager</title>
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
  <!-- Bootstrap CSS z CDN -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/themes/tokens.css?v=<?= (int)$themeAssetVersion ?>">
  <link rel="stylesheet" href="assets/css/themes/theme-light.css?v=<?= (int)$themeAssetVersion ?>">
  <link rel="stylesheet" href="assets/css/themes/theme-dark.css?v=<?= (int)$themeAssetVersion ?>">
  <!-- Wspólny arkusz stylów -->
  <link rel="stylesheet" href="assets/css/style.css?v=<?= (int)$themeAssetVersion ?>">
  <link rel="stylesheet" href="assets/css/themes/overrides-bootstrap.css?v=<?= (int)$themeAssetVersion ?>">
</head>
<body>
  <div class="login-form">
    <h2 class="text-center">Logowanie</h2>
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
  <!-- Bootstrap JS Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
  <script src="assets/js/theme.js?v=<?= (int)$themeAssetVersion ?>"></script>
</body>
</html>
