<?php
require_once __DIR__ . '/includes/auth.php';
if (!isset($_SESSION['user_id'])) {
    http_response_code(403);
    echo "Brak autoryzacji";
    exit;
}

require_once '../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';
ensureEmisjeSpotowTable($pdo);

$spot_id = $_POST['id'] ?? null;

if (!$spot_id || !is_numeric($spot_id)) {
    http_response_code(400);
    echo "Nieprawidłowy identyfikator spotu";
    exit;
}

try {
    // Usuń powiązane emisje
    $stmtWeekly = $pdo->prepare("DELETE FROM emisje_spotow WHERE spot_id = ?");
    $stmtWeekly->execute([$spot_id]);

    $stmt1 = $pdo->prepare("DELETE FROM spoty_emisje WHERE spot_id = ?");
    $stmt1->execute([$spot_id]);

    // Usuń sam spot
    $stmt2 = $pdo->prepare("DELETE FROM spoty WHERE id = ?");
    $stmt2->execute([$spot_id]);

    echo "✅ Spot został trwale usunięty";
} catch (Exception $e) {
    http_response_code(500);
    echo "❌ Błąd podczas usuwania spotu";
}

