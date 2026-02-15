<?php
require_once __DIR__ . '../../config/config.php';
require_once __DIR__ . '/includes/company_view_model.php';
$pageTitle = "Wyszukiwarka klientów";
require_once __DIR__ . '/includes/header.php';

$wyniki = [];
$query = '';
if (isset($_GET['q']) && strlen(trim($_GET['q'])) > 1) {
    $query = trim($_GET['q']);
    $sql = "SELECT
                k.id AS k_id,
                k.nazwa_firmy AS k_nazwa_firmy,
                k.nip AS k_nip,
                k.regon AS k_regon,
                k.miejscowosc AS k_miejscowosc,
                k.telefon AS k_telefon,
                k.data_dodania AS k_data_dodania,
                c.name_full AS c_name_full,
                c.name_short AS c_name_short,
                c.nip AS c_nip,
                c.regon AS c_regon,
                c.city AS c_city
            FROM klienci k
            LEFT JOIN companies c ON c.id = k.company_id
            WHERE COALESCE(c.nip, k.nip) LIKE :q
               OR COALESCE(c.name_full, c.name_short, k.nazwa_firmy) LIKE :q
               OR COALESCE(c.city, k.miejscowosc) LIKE :q
               OR k.telefon LIKE :q
            ORDER BY k.data_dodania DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['q' => "%{$query}%"]);
    $rows = $stmt->fetchAll();
    $wyniki = [];
    foreach ($rows as $row) {
        $wyniki[] = [
            'id' => (int)$row['k_id'],
            'telefon' => $row['k_telefon'] ?? null,
            'data_dodania' => $row['k_data_dodania'] ?? null,
            'vm' => buildCompanyViewModelFromJoined($row),
        ];
    }
}
?>

<div class="container mt-4">
    <h2>Wyszukiwarka klientów</h2>
    <form method="get" class="row mb-3">
        <div class="col-md-10">
            <input type="text" name="q" class="form-control" placeholder="Szukaj po NIP, nazwie, mieście lub telefonie..." value="<?= htmlspecialchars($query) ?>" required>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Szukaj</button>
        </div>
    </form>

    <?php if (isset($_GET['q'])): ?>
        <h5 class="mb-3">Wyniki dla: <em><?= htmlspecialchars($query) ?></em></h5>
        <?php if (count($wyniki) > 0): ?>
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>NIP</th>
                        <th>Nazwa firmy</th>
                        <th>Miasto</th>
                        <th>Telefon</th>
                        <th>Dodano</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($wyniki as $k): ?>
                    <?php $vm = $k['vm'] ?? []; ?>
                    <tr>
                        <td><?= htmlspecialchars($vm['nip_display'] ?? '') ?></td>
                        <td><?= htmlspecialchars($vm['company_name_display'] ?? '') ?></td>
                        <td><?= htmlspecialchars($vm['address_city_display'] ?? '') ?></td>
                        <td><?= htmlspecialchars($k['telefon']) ?></td>
                        <td><?= $k['data_dodania'] ? date('d.m.Y', strtotime($k['data_dodania'])) : '' ?></td>
                        <td>
                            <a href="edytuj_klienta.php?id=<?= $k['id'] ?>" class="btn btn-sm btn-outline-primary">Edytuj</a>
                            <a href="usun_klienta.php?id=<?= $k['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Na pewno usunąć?')">Usuń</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <div class="alert alert-warning">Nie znaleziono klientów.</div>
        <?php endif; ?>
    <?php endif; ?>
</div>

