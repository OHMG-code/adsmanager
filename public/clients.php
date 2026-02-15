<?php
require_once __DIR__ . '../../config/config.php';
require_once __DIR__ . '/includes/header.php';

try {
    $stmt = $pdo->query("SELECT * FROM clients ORDER BY created_at DESC");
    $clients = $stmt->fetchAll();
} catch (PDOException $e) {
    echo "<div class='alert alert-danger'>Błąd pobierania danych: " . $e->getMessage() . "</div>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <title>Lista klientów</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-4">
    <h2 class="mb-4">Lista klientów</h2>

    <a href="add_client.php" class="btn btn-success mb-3">+ Dodaj klienta</a>

    <table class="table table-bordered">
        <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Imię i nazwisko</th>
                <th>Email</th>
                <th>Telefon</th>
                <th>Data dodania</th>
                <th>Akcje</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($clients) > 0): ?>
                <?php foreach ($clients as $client): ?>
                    <tr>
                        <td><?= htmlspecialchars($client['id']) ?></td>
                        <td><?= htmlspecialchars($client['name']) ?></td>
                        <td><?= htmlspecialchars($client['email']) ?></td>
                        <td><?= htmlspecialchars($client['phone']) ?></td>
                        <td><?= htmlspecialchars($client['created_at']) ?></td>
                        <td>
                            <a href="edit_client.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-warning">Edytuj</a>
                            <a href="delete_client.php?id=<?= $client['id'] ?>" class="btn btn-sm btn-danger" onclick="return confirm('Na pewno usunąć klienta?');">Usuń</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="6" class="text-center">Brak klientów w bazie.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>
</body>
</html>
