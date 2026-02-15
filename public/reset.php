<?php
$pageTitle = "Resetowanie hasła";
include 'includes/header.php';
require_once '../config/config.php';

$komunikat = '';
$link_resetowy = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');

    if ($login === '') {
        $komunikat = '⚠️ Wprowadź login.';
    } else {
        // Sprawdź, czy użytkownik istnieje
        $stmt = $pdo->prepare("SELECT id FROM uzytkownicy WHERE login = ?");
        $stmt->execute([$login]);
        $user = $stmt->fetch();

        if ($user) {
            // Wygeneruj token i czas wygaśnięcia
            $token = bin2hex(random_bytes(16));
            $wazne_do = (new DateTime('+30 minutes'))->format('Y-m-d H:i:s');

            // Zapisz token w bazie
            $stmt = $pdo->prepare("UPDATE uzytkownicy SET reset_token = ?, reset_token_expires = ? WHERE login = ?");
            $stmt->execute([$token, $wazne_do, $login]);

            // Przygotuj link
            $link_resetowy = "reset_hasla.php?token=" . urlencode($token);
            $komunikat = "✅ Link do zmiany hasła został wygenerowany.";
        } else {
            $komunikat = "❌ Taki login nie istnieje.";
        }
    }
}
?>

<div class="container">
    <h1 class="mb-4">Resetowanie hasła</h1>

    <?php if ($komunikat): ?>
        <div class="alert alert-info"><?php echo $komunikat; ?></div>
    <?php endif; ?>

    <form method="post" class="mb-4">
        <div class="mb-3">
            <label for="login" class="form-label">Wprowadź swój login:</label>
            <input type="text" name="login" id="login" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary">🔑 Wygeneruj link</button>
    </form>

    <?php if ($link_resetowy): ?>
        <div class="alert alert-success">
            Kliknij w poniższy link, aby zmienić hasło:<br>
            <a href="<?php echo $link_resetowy; ?>"><?php echo $link_resetowy; ?></a><br>
            <small>Link jest ważny przez 30 minut.</small>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>


