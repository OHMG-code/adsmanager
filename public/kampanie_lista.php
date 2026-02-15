<?php
declare(strict_types=1);
require_once '../config/config.php';
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pageTitle = "Lista kampanii";
include 'includes/header.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$rows = [];
$loadErrors = [];

try {
    $stmt = $pdo->query('SELECT id, klient_nazwa, data_start, data_koniec, razem_brutto, created_at, propozycja
                          FROM kampanie
                          ORDER BY id DESC');
    foreach ($stmt as $row) {
        $row['__source'] = 'standard';
        $row['propozycja'] = !empty($row['propozycja']);
        $rows[] = $row;
    }
} catch (Throwable $e) {
    $loadErrors[] = 'Nie udalo sie pobrac kampanii z tabeli kampanie.';
    error_log('kampanie_lista.php: kampanie query failed: ' . $e->getMessage());
}

try {
    $stmt = $pdo->query('SELECT id, klient_nazwa, data_start, data_koniec, razem_brutto, created_at
                          FROM kampanie_tygodniowe
                          ORDER BY id DESC');
    foreach ($stmt as $row) {
        $row['__source'] = 'legacy';
        $row['propozycja'] = false;
        $rows[] = $row;
    }
} catch (Throwable $e) {
    $loadErrors[] = 'Nie udalo sie pobrac kampanii z tabeli kampanie_tygodniowe.';
    error_log('kampanie_lista.php: kampanie_tygodniowe query failed: ' . $e->getMessage());
}

usort($rows, static function (array $a, array $b): int {
    $aTime = 0;
    if (!empty($a['created_at'])) {
        $parsed = strtotime((string)$a['created_at']);
        if ($parsed !== false) {
            $aTime = $parsed;
        }
    }
    $bTime = 0;
    if (!empty($b['created_at'])) {
        $parsed = strtotime((string)$b['created_at']);
        if ($parsed !== false) {
            $bTime = $parsed;
        }
    }

    if ($aTime === $bTime) {
        return ($b['id'] ?? 0) <=> ($a['id'] ?? 0);
    }

    return $bTime <=> $aTime;
});
?>
<div class="container">
  <div class="app-header">
    <div>
      <h1 class="app-title">Lista kampanii</h1>
      <div class="app-subtitle">Zestawienie kampanii z systemu.</div>
    </div>
  </div>
  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info') ?> mt-2">
      <?= htmlspecialchars($flash['msg'] ?? '') ?>
    </div>
  <?php endif; ?>
  <?php if ($loadErrors): ?>
    <div class="alert alert-warning mt-3">
      <?php foreach ($loadErrors as $err): ?>
        <div><?= htmlspecialchars($err) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($rows): ?>
    <div class="table-responsive mt-3">
      <table class="table table-zebra table-sm align-middle">
        <thead class="table-light">
          <tr>
            <th>ID</th>
            <th>Klient</th>
            <th>Okres</th>
            <th>Brutto</th>
            <th>Typ</th>
            <th>Utworzono</th>
            <th class="text-end">Akcje</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td><?= (int)($r['id'] ?? 0) ?></td>
            <td><?= htmlspecialchars($r['klient_nazwa'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['data_start'] ?? '') ?> - <?= htmlspecialchars($r['data_koniec'] ?? '') ?></td>
            <td><?= number_format((float)($r['razem_brutto'] ?? 0), 2, ',', ' ') ?> zl</td>
            <td>
              <?php if (!empty($r['propozycja'])): ?>
                <span class="badge badge-warning">Propozycja</span>
              <?php else: ?>
                <span class="badge badge-success"><?= $r['__source'] === 'legacy' ? 'Archiwalna' : 'Aktywna' ?></span>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($r['created_at'] ?? '') ?></td>
            <td class="text-end table-actions">
              <a class="btn btn-sm btn-primary" href="kampania_podglad.php?id=<?= (int)($r['id'] ?? 0) ?>">Podglad</a>
              <form method="post" action="<?= BASE_URL ?>/kampania_usun.php" onsubmit="return confirm('Usunac kampanie #<?= (int)($r['id'] ?? 0) ?>?');" style="display:inline-block;">
                <input type="hidden" name="id" value="<?= (int)($r['id'] ?? 0) ?>">
                <button class="btn btn-sm btn-danger" type="submit">Usun</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <p class="text-muted mt-3">Brak zapisanych kampanii w bazie.</p>
  <?php endif; ?>
</div>
<?php include 'includes/footer.php'; ?>


