<?php
declare(strict_types=1);

require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/briefs.php';

requireLogin();
requireCapability('brief.view');

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
ensureLeadBriefsTable($pdo);
ensureKampanieOwnershipColumns($pdo);

try {
    $pdo->exec("ALTER TABLE lead_briefs MODIFY COLUMN status ENUM('draft','sent','submitted','revision_requested','approved_internal','closed') NOT NULL DEFAULT 'draft'");
} catch (Throwable $e) {
    error_log('briefy: cannot extend lead_briefs.status enum: ' . $e->getMessage());
}

$statusDefinitions = [
    'draft' => ['label' => 'Szkic', 'badge' => 'brief-status-draft'],
    'sent' => ['label' => 'Wyslany do klienta', 'badge' => 'brief-status-sent'],
    'submitted' => ['label' => 'Uzupelniony przez klienta', 'badge' => 'brief-status-submitted'],
    'revision_requested' => ['label' => 'Do poprawy', 'badge' => 'brief-status-revision'],
    'approved_internal' => ['label' => 'Zaakceptowany', 'badge' => 'brief-status-approved'],
    'closed' => ['label' => 'Zamkniety', 'badge' => 'brief-status-closed'],
];

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$redirectWithCurrentFilters = static function (): string {
    $keep = [];
    foreach (['status', 'owner', 'q', 'date_from', 'date_to'] as $key) {
        $value = trim((string)($_GET[$key] ?? ''));
        if ($value !== '') {
            $keep[$key] = $value;
        }
    }
    return 'briefy.php' . ($keep ? ('?' . http_build_query($keep)) : '');
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $message = ['type' => 'warning', 'msg' => 'Nie rozpoznano akcji.'];
    $action = trim((string)($_POST['brief_action'] ?? ''));

    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $message = ['type' => 'danger', 'msg' => 'Niepoprawny token formularza.'];
    } elseif ($action === 'change_status') {
        $briefId = (int)($_POST['brief_id'] ?? 0);
        $newStatus = trim((string)($_POST['new_status'] ?? ''));
        if ($briefId <= 0) {
            $message = ['type' => 'warning', 'msg' => 'Brak briefu do zmiany statusu.'];
        } elseif (!isset($statusDefinitions[$newStatus])) {
            $message = ['type' => 'warning', 'msg' => 'Niepoprawny status briefu.'];
        } else {
            try {
                $editable = in_array($newStatus, ['draft', 'sent', 'revision_requested'], true) ? 1 : 0;
                $submittedAtSql = $newStatus === 'submitted' ? ', submitted_at = COALESCE(submitted_at, NOW())' : '';
                $stmt = $pdo->prepare("UPDATE lead_briefs
                    SET status = :status,
                        is_customer_editable = :editable
                        $submittedAtSql
                    WHERE id = :id");
                $stmt->execute([
                    ':status' => $newStatus,
                    ':editable' => $editable,
                    ':id' => $briefId,
                ]);
                $message = ['type' => 'success', 'msg' => 'Status briefu zostal zmieniony.'];
            } catch (Throwable $e) {
                $message = ['type' => 'danger', 'msg' => 'Nie udalo sie zmienic statusu briefu.'];
                error_log('briefy: status update failed: ' . $e->getMessage());
            }
        }
    }

    $_SESSION['flash'] = $message;
    header('Location: ' . BASE_URL . '/' . $redirectWithCurrentFilters());
    exit;
}

$filters = [
    'status' => trim((string)($_GET['status'] ?? '')),
    'owner' => trim((string)($_GET['owner'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')),
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
];

if ($filters['status'] !== '' && !isset($statusDefinitions[$filters['status']])) {
    $filters['status'] = '';
}
if ($filters['owner'] !== '' && !ctype_digit($filters['owner'])) {
    $filters['owner'] = '';
}

$users = [];
try {
    $users = $pdo->query("SELECT id, login, imie, nazwisko FROM uzytkownicy WHERE aktywny = 1 ORDER BY COALESCE(NULLIF(nazwisko, ''), login), imie, login")->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log('briefy: cannot load owners: ' . $e->getMessage());
}

$where = ['COALESCE(k.propozycja, 0) = 0'];
$params = [];
if ($filters['status'] !== '') {
    $where[] = 'COALESCE(lb.status, "draft") = :status';
    $params[':status'] = $filters['status'];
}
if ($filters['owner'] !== '') {
    $where[] = 'k.owner_user_id = :owner';
    $params[':owner'] = (int)$filters['owner'];
}
if ($filters['date_from'] !== '') {
    $where[] = 'DATE(COALESCE(lb.created_at, k.created_at)) >= :date_from';
    $params[':date_from'] = $filters['date_from'];
}
if ($filters['date_to'] !== '') {
    $where[] = 'DATE(COALESCE(lb.created_at, k.created_at)) <= :date_to';
    $params[':date_to'] = $filters['date_to'];
}
if ($filters['q'] !== '') {
    $where[] = '(k.klient_nazwa LIKE :q OR cli.nazwa_firmy LIKE :q OR leady.nazwa_firmy LIKE :q OR cli.kontakt_imie_nazwisko LIKE :q OR leady.kontakt_imie_nazwisko LIKE :q)';
    $params[':q'] = '%' . $filters['q'] . '%';
}

$sql = "SELECT
        k.id AS campaign_id,
        k.klient_nazwa AS campaign_name,
        k.created_at AS campaign_created_at,
        k.owner_user_id,
        lb.id AS brief_id,
        lb.token,
        COALESCE(lb.status, 'draft') AS brief_status,
        lb.created_at AS brief_created_at,
        lb.updated_at AS brief_updated_at,
        lb.submitted_at,
        COALESCE(NULLIF(cli.nazwa_firmy, ''), NULLIF(k.klient_nazwa, ''), NULLIF(leady.nazwa_firmy, '')) AS client_name,
        COALESCE(NULLIF(cli.kontakt_imie_nazwisko, ''), NULLIF(leady.kontakt_imie_nazwisko, '')) AS contact_person,
        COALESCE(NULLIF(cli.kontakt_telefon, ''), NULLIF(cli.telefon, ''), NULLIF(leady.kontakt_telefon, ''), NULLIF(leady.telefon, '')) AS contact_phone,
        COALESCE(NULLIF(cli.kontakt_email, ''), NULLIF(cli.email, ''), NULLIF(leady.kontakt_email, ''), NULLIF(leady.email, '')) AS contact_email,
        owner.login AS owner_login,
        owner.imie AS owner_imie,
        owner.nazwisko AS owner_nazwisko
    FROM kampanie k
    LEFT JOIN lead_briefs lb ON lb.campaign_id = k.id
    LEFT JOIN klienci cli ON cli.id = k.klient_id
    LEFT JOIN leady leady ON leady.id = k.source_lead_id
    LEFT JOIN uzytkownicy owner ON owner.id = k.owner_user_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY COALESCE(lb.updated_at, k.created_at) DESC, k.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll() ?: [];

if (normalizeRole($currentUser) === 'Handlowiec') {
    $rows = array_values(array_filter($rows, static function (array $row) use ($pdo, $currentUser): bool {
        return canUserAccessKampania($pdo, $currentUser, (int)($row['campaign_id'] ?? 0));
    }));
}

$fallback = static function ($value): string {
    $text = trim((string)($value ?? ''));
    return $text !== '' ? $text : 'Brak danych';
};

$formatDateTime = static function ($value): string {
    $raw = trim((string)($value ?? ''));
    if ($raw === '') {
        return 'Brak danych';
    }
    try {
        return (new DateTimeImmutable($raw))->format('d.m.Y H:i');
    } catch (Throwable $e) {
        return $raw;
    }
};

$ownerLabel = static function (array $row): string {
    $full = trim((string)($row['owner_imie'] ?? '') . ' ' . (string)($row['owner_nazwisko'] ?? ''));
    if ($full !== '') {
        return $full;
    }
    $login = trim((string)($row['owner_login'] ?? ''));
    return $login !== '' ? $login : 'Brak danych';
};

$pageTitle = 'Briefy';
$pageStyles = ['briefs'];
include 'includes/header.php';
?>
<main class="page-shell brief-list-page">
  <div class="app-header">
    <div>
      <h1 class="app-title">Briefy</h1>
      <p class="app-subtitle">Lista briefow kampanii i spotow reklamowych.</p>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars((string)($flash['type'] ?? 'info')) ?>">
      <?= htmlspecialchars((string)($flash['msg'] ?? '')) ?>
    </div>
  <?php endif; ?>

  <section class="card brief-filter-card">
    <div class="card-body">
      <form class="brief-filter-form" method="get">
        <div>
          <label class="form-label" for="status">Status</label>
          <select class="form-select" id="status" name="status">
            <option value="">Wszystkie</option>
            <?php foreach ($statusDefinitions as $key => $meta): ?>
              <option value="<?= htmlspecialchars($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= htmlspecialchars($meta['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="form-label" for="owner">Opiekun</label>
          <select class="form-select" id="owner" name="owner">
            <option value="">Wszyscy</option>
            <?php foreach ($users as $user): ?>
              <?php $label = trim((string)($user['imie'] ?? '') . ' ' . (string)($user['nazwisko'] ?? '')) ?: (string)($user['login'] ?? ''); ?>
              <option value="<?= (int)$user['id'] ?>" <?= $filters['owner'] === (string)$user['id'] ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="brief-filter-wide">
          <label class="form-label" for="q">Klient / nazwa kampanii</label>
          <input class="form-control" id="q" name="q" value="<?= htmlspecialchars($filters['q']) ?>" placeholder="Wpisz klienta, firme lub kampanie">
        </div>
        <div>
          <label class="form-label" for="date_from">Data od</label>
          <input class="form-control" id="date_from" type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>">
        </div>
        <div>
          <label class="form-label" for="date_to">Data do</label>
          <input class="form-control" id="date_to" type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>">
        </div>
        <div class="brief-filter-actions">
          <button class="btn btn-primary" type="submit">Filtruj</button>
          <a class="btn btn-outline-secondary" href="briefy.php">Wyczysc</a>
        </div>
      </form>
    </div>
  </section>

  <section class="card brief-table-card">
    <div class="card-header">
      <div class="section-header">
        <div>
          <h2 class="section-header-title">Lista briefow</h2>
          <p class="section-header-copy">Znaleziono: <?= count($rows) ?></p>
        </div>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
        <table class="table brief-table mb-0">
          <thead>
            <tr>
              <th>Nazwa kampanii / briefu</th>
              <th>Status briefu</th>
              <th>Wlasciciel / opiekun</th>
              <th>Data utworzenia</th>
              <th>Ostatnia aktualizacja</th>
              <th>Akcje</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$rows): ?>
              <tr><td colspan="6" class="text-muted p-4">Brak briefow spelniajacych wybrane filtry.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
              <?php
                $statusKey = (string)($row['brief_status'] ?? 'draft');
                $statusMeta = $statusDefinitions[$statusKey] ?? $statusDefinitions['draft'];
                $publicUrl = trim((string)($row['token'] ?? '')) !== '' ? (BASE_URL . '/brief/' . urlencode((string)$row['token'])) : '';
              ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($fallback($row['campaign_name'] ?? null)) ?></strong>
                  <div class="brief-muted">Kampania #<?= (int)($row['campaign_id'] ?? 0) ?></div>
                </td>
                <td><span class="brief-status-badge <?= htmlspecialchars($statusMeta['badge']) ?>"><?= htmlspecialchars($statusMeta['label']) ?></span></td>
                <td><?= htmlspecialchars($ownerLabel($row)) ?></td>
                <td><?= htmlspecialchars($formatDateTime($row['brief_created_at'] ?? $row['campaign_created_at'] ?? null)) ?></td>
                <td><?= htmlspecialchars($formatDateTime($row['brief_updated_at'] ?? $row['campaign_created_at'] ?? null)) ?></td>
                <td>
                  <div class="brief-row-actions">
                    <a class="btn btn-sm btn-outline-secondary" href="kampania_podglad.php?id=<?= (int)($row['campaign_id'] ?? 0) ?>">Otworz w CRM</a>
                    <?php if ($publicUrl !== ''): ?>
                      <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" rel="noopener">Otworz publiczny link</a>
                      <button class="btn btn-sm btn-outline-secondary" type="button" data-copy-link="<?= htmlspecialchars($publicUrl) ?>">Kopiuj link</button>
                    <?php else: ?>
                      <span class="brief-link-missing">Brak linku</span>
                    <?php endif; ?>
                    <?php if ((int)($row['brief_id'] ?? 0) > 0): ?>
                      <form method="post" class="brief-status-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                        <input type="hidden" name="brief_action" value="change_status">
                        <input type="hidden" name="brief_id" value="<?= (int)$row['brief_id'] ?>">
                        <select class="form-select form-select-sm" name="new_status" aria-label="Zmien status briefu">
                          <?php foreach ($statusDefinitions as $key => $meta): ?>
                            <option value="<?= htmlspecialchars($key) ?>" <?= $statusKey === $key ? 'selected' : '' ?>><?= htmlspecialchars($meta['label']) ?></option>
                          <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-primary" type="submit">Zmien status</button>
                      </form>
                    <?php else: ?>
                      <button class="btn btn-sm btn-primary" type="button" disabled>Zmien status</button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</main>
<script>
document.querySelectorAll('[data-copy-link]').forEach(function (button) {
  button.addEventListener('click', function () {
    var link = button.getAttribute('data-copy-link') || '';
    if (!link) return;
    navigator.clipboard.writeText(link).then(function () {
      button.textContent = 'Skopiowano';
      window.setTimeout(function () { button.textContent = 'Kopiuj link'; }, 1400);
    }).catch(function () {
      window.prompt('Skopiuj link:', link);
    });
  });
});
</script>
<?php include 'includes/footer.php'; ?>
