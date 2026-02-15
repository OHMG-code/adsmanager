<?php
$pageTitle = "Ustaw nowe hasło";
include 'includes/header.php';
require_once '../config/config.php';

$token = $_GET['token'] ?? '';
$komunikat = '';
$pokaz_formularz = false;

// Sprawdzenie tokenu
if ($token !== '') {
    $stmt = $pdo->prepare("SELECT * FROM uzytkownicy WHERE reset_token = ? AND reset_token_expires >= NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $pokaz_formularz = true;

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $haslo1 = $_POST['haslo1'] ?? '';
            $haslo2 = $_POST['haslo2'] ?? '';

            if ($haslo1 === '' || $haslo2 === '') {
                $komunikat = '⚠️ Wprowadź oba pola hasła.';
            } elseif ($haslo1 !== $haslo2) {
                $komunikat = '❌ Hasła nie są takie same.';
            } elseif (strlen($haslo1) < 6) {
                $komunikat = '🔐 Hasło musi mieć przynajmniej 6 znaków.';
            } else {
                // Zapis hasła
                $hash = password_hash($haslo1, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE uzytkownicy SET haslo_hash = ?, reset_token = NULL, reset_token_expires = NULL WHERE id = ?");
                $stmt->execute([$hash, $user['id']]);

                $komunikat = '✅ Hasło zostało zmienione. Możesz się teraz zalogować.';
                $pokaz_formularz = false;
            }
        }
    } else {
        $komunikat = '❌ Nieprawidłowy lub wygasły token.';
    }
} else {
    $komunikat = '⚠️ Brak tokenu resetującego hasło.';
}
?>

<div class="container">
    <h1 class="mb-4">Ustaw nowe hasło</h1>

    <?php if ($komunikat): ?>
        <div class="alert alert-info"><?php echo $komunikat; ?></div>
    <?php endif; ?>

    <?php if ($pokaz_formularz): ?>
        <form method="post">
            <div class="mb-3">
                <label for="haslo1" class="form-label">Nowe hasło:</label>
                <input type="password" name="haslo1" id="haslo1" class="form-control" required>
            </div>
            <div class="mb-3">
                <label for="haslo2" class="form-label">Powtórz nowe hasło:</label>
                <input type="password" name="haslo2" id="haslo2" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-success">💾 Zapisz nowe hasło</button>
        </form>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>


