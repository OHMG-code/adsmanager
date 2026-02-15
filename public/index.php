<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
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
  <!-- Bootstrap CSS z CDN -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Wspólny arkusz stylów -->
  <link rel="stylesheet" href="assets/css/style.css">
  <style>
    /* Minimalny styl dla formularza logowania */
    .login-form {
      max-width: 400px;
      margin: 100px auto;
      padding: 20px;
      background-color: #fff;
      border: 1px solid #ddd;
      border-radius: 5px;
    }
  </style>
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
</body>
</html>

