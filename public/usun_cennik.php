<?php
require_once __DIR__ . '/includes/auth.php';
requireRole(['Administrator', 'Manager']);
require_once '../config/config.php';

$typ = $_GET['typ'] ?? null;
$id = $_POST['id'] ?? null;

function redirectCennikDeleteError(?string $typ): void
{
    header('Location: ' . BASE_URL . '/cenniki.php?msg=error#' . ($typ ?? ''));
    exit;
}

function ensureCennikPatronatTable(PDO $pdo): void
{
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS cennik_patronat (
                id INT AUTO_INCREMENT PRIMARY KEY,
                pozycja VARCHAR(255) NOT NULL,
                stawka_netto DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                stawka_vat DECIMAL(5,2) NOT NULL DEFAULT 23.00,
                data_modyfikacji TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    } catch (Throwable $e) {
        error_log('usun_cennik.php: cannot ensure cennik_patronat: ' . $e->getMessage());
    }
}

if ($typ === 'patronat') {
    ensureCennikPatronatTable($pdo);
}

if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
    redirectCennikDeleteError($typ);
}

$tabela = [
    'spoty'   => 'cennik_spoty',
    'sygnaly' => 'cennik_sygnaly',
    'wywiady' => 'cennik_wywiady',
    'display' => 'cennik_display',
    'social'  => 'cennik_social',
    'patronat' => 'cennik_patronat'
][$typ] ?? null;

if ($tabela && $id && is_numeric($id)) {
    $stmt = $pdo->prepare("DELETE FROM $tabela WHERE id = ?");
    $stmt->execute([$id]);
    header('Location: ' . BASE_URL . '/cenniki.php?msg=deleted#' . $typ);
} else {
    redirectCennikDeleteError($typ);
}
exit;
