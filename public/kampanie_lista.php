<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
requireLogin();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pageTitle = "Lista kampanii";
include 'includes/header.php';

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$rows = [];
$loadErrors = [];

function campaignStatusKey(?string $status): string
{
    $status = trim((string)$status);
    if ($status === '') {
        return '';
    }

    $status = preg_replace('/\s+/', ' ', $status) ?? $status;
    if (function_exists('mb_strtolower')) {
        $status = mb_strtolower($status, 'UTF-8');
    } else {
        $status = strtolower($status);
    }

    return strtr($status, [
        'ą' => 'a',
        'ć' => 'c',
        'ę' => 'e',
        'ł' => 'l',
        'ń' => 'n',
        'ó' => 'o',
        'ś' => 's',
        'ż' => 'z',
        'ź' => 'z',
    ]);
}

function campaignTypeMeta(array $campaign): array
{
    $statusNorm = campaignStatusKey($campaign['status'] ?? '');
    $isProposal = !empty($campaign['propozycja']) || $statusNorm === 'propozycja';
    if ($isProposal) {
        return ['key' => 'propozycja', 'label' => 'Propozycja', 'class' => 'badge-warning'];
    }

    if (in_array($statusNorm, ['w produkcji', 'produkcja', 'production', 'in production'], true)) {
        return ['key' => 'w_produkcji', 'label' => 'W produkcji', 'class' => 'badge-campaign-production'];
    }

    if (
        ($campaign['__source'] ?? '') === 'legacy'
        || in_array($statusNorm, ['archiwalna', 'archiwum', 'zakonczona', 'zamknieta', 'closed', 'nieaktywna'], true)
    ) {
        return ['key' => 'archiwalna', 'label' => 'Archiwalna', 'class' => 'badge-campaign-archived'];
    }

    return ['key' => 'aktywna', 'label' => 'Aktywna', 'class' => 'badge-campaign-active'];
}

function campaignFilterDate(?string $rawDate): ?string
{
    $date = trim((string)$rawDate);
    if ($date === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $date);
    if (!$dt || $dt->format('Y-m-d') !== $date) {
        return null;
    }
    return $date;
}

function campaignMatchesDateRange(array $campaign, ?string $dateFrom, ?string $dateTo): bool
{
    if ($dateFrom === null && $dateTo === null) {
        return true;
    }

    $start = trim((string)($campaign['data_start'] ?? ''));
    $end = trim((string)($campaign['data_koniec'] ?? ''));

    if ($start === '' && $end === '') {
        return false;
    }
    if ($start === '') {
        $start = $end;
    }
    if ($end === '') {
        $end = $start;
    }

    if ($dateFrom !== null && $end < $dateFrom) {
        return false;
    }
    if ($dateTo !== null && $start > $dateTo) {
        return false;
    }

    return true;
}

function campaignSourcePriority(array $campaign): int
{
    $source = trim((string)($campaign['__source'] ?? ''));
    return $source === 'standard' ? 2 : 1;
}

function campaignCreatedAtTimestamp(array $campaign): int
{
    $value = trim((string)($campaign['created_at'] ?? ''));
    if ($value === '') {
        return 0;
    }
    $parsed = strtotime($value);
    return $parsed !== false ? (int)$parsed : 0;
}

$allowedTypeFilters = ['aktywna', 'archiwalna', 'propozycja', 'w_produkcji'];
$dateFrom = campaignFilterDate($_GET['date_from'] ?? null);
$dateTo = campaignFilterDate($_GET['date_to'] ?? null);
$typeFilter = trim((string)($_GET['campaign_type'] ?? ''));
if (!in_array($typeFilter, $allowedTypeFilters, true)) {
    $typeFilter = '';
}
if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

try {
    $stmt = $pdo->query('SELECT id, klient_nazwa, data_start, data_koniec, razem_brutto, created_at, propozycja, status
                          FROM kampanie
                          ORDER BY id DESC');
    foreach ($stmt as $row) {
        $row['__source'] = 'standard';
        $statusNorm = campaignStatusKey($row['status'] ?? '');
        $row['propozycja'] = !empty($row['propozycja']) || $statusNorm === 'propozycja';
        $rows[] = $row;
    }
} catch (Throwable $e) {
    $loadErrors[] = 'Nie udało się pobrać kampanii z tabeli kampanie.';
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
    $loadErrors[] = 'Nie udało się pobrać kampanii z tabeli kampanie_tygodniowe.';
    error_log('kampanie_lista.php: kampanie_tygodniowe query failed: ' . $e->getMessage());
}

$deduplicatedRows = [];
$fallbackRowCounter = 0;
foreach ($rows as $row) {
    $id = (int)($row['id'] ?? 0);
    $rowKey = $id > 0 ? 'id:' . $id : 'fallback:' . (++$fallbackRowCounter);

    if (!isset($deduplicatedRows[$rowKey])) {
        $deduplicatedRows[$rowKey] = $row;
        continue;
    }

    $existing = $deduplicatedRows[$rowKey];
    $existingPriority = campaignSourcePriority($existing);
    $candidatePriority = campaignSourcePriority($row);

    if ($candidatePriority > $existingPriority) {
        $deduplicatedRows[$rowKey] = $row;
        continue;
    }

    if ($candidatePriority === $existingPriority) {
        $existingTimestamp = campaignCreatedAtTimestamp($existing);
        $candidateTimestamp = campaignCreatedAtTimestamp($row);
        if ($candidateTimestamp >= $existingTimestamp) {
            $deduplicatedRows[$rowKey] = $row;
        }
    }
}

$rows = array_values($deduplicatedRows);

$rows = array_values(array_filter($rows, static function (array $row) use ($dateFrom, $dateTo, $typeFilter): bool {
    if (!campaignMatchesDateRange($row, $dateFrom, $dateTo)) {
        return false;
    }
    if ($typeFilter === '') {
        return true;
    }
    $typeMeta = campaignTypeMeta($row);
    return ($typeMeta['key'] ?? '') === $typeFilter;
}));
$hasActiveFilters = ($dateFrom !== null || $dateTo !== null || $typeFilter !== '');

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
<style>
  .campaign-list-page {
    width: 100%;
    max-width: none;
    margin: 0;
    padding-left: 10px;
    padding-right: 10px;
  }
  .campaign-list-table th,
  .campaign-list-table td {
    vertical-align: middle;
  }
  .campaign-list-table th:nth-child(2),
  .campaign-list-table td:nth-child(2) {
    min-width: 240px;
  }
  .campaign-list-table th:nth-child(3),
  .campaign-list-table td:nth-child(3) {
    min-width: 190px;
    white-space: nowrap;
  }
  .campaign-list-table th:nth-child(6),
  .campaign-list-table td:nth-child(6) {
    min-width: 170px;
    white-space: nowrap;
  }
  .campaign-list-table .table-actions {
    flex-wrap: nowrap;
    gap: 6px;
    white-space: nowrap;
  }
  .campaign-list-table .btn {
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }
  .campaign-list-table .btn-primary,
  .campaign-list-table .btn-primary:visited,
  .campaign-list-table .btn-primary:hover,
  .campaign-list-table .btn-primary:focus {
    color: #fff !important;
    text-decoration: none;
  }
  .campaign-list-filters {
    display: flex;
    align-items: center;
    gap: 6px;
    flex-wrap: nowrap;
    justify-content: flex-end;
    white-space: nowrap;
    overflow-x: auto;
    scrollbar-width: thin;
  }
  .campaign-list-filters .form-control,
  .campaign-list-filters .form-select,
  .campaign-list-filters .btn {
    flex: 0 0 auto;
    font-size: 0.75rem;
    line-height: 1.1;
    padding-top: 4px;
    padding-bottom: 4px;
  }
  .campaign-list-filters .filter-date {
    width: 124px;
    min-width: 124px;
  }
  .campaign-list-filters .type-filter {
    width: 142px;
    min-width: 142px;
  }
  .campaign-list-filters .filter-btn {
    padding-left: 8px;
    padding-right: 8px;
    white-space: nowrap;
  }
  .badge-campaign-active {
    background: rgba(var(--success-rgb), 0.16);
    color: var(--ui-success);
  }
  .badge-campaign-archived {
    background: rgba(100, 116, 139, 0.2);
    color: var(--ui-text-muted);
  }
  .badge-campaign-production {
    background: rgba(236, 72, 153, 0.2);
    color: #be185d;
  }
  @media (max-width: 992px) {
    .campaign-list-table th:nth-child(2),
    .campaign-list-table td:nth-child(2) {
      min-width: 180px;
      white-space: normal;
    }
    .campaign-list-table .table-actions {
      justify-content: flex-start;
      flex-wrap: wrap;
    }
    .campaign-list-filters { justify-content: flex-start; }
  }
</style>
<div class="campaign-list-page">
  <div class="app-header">
    <div>
      <h1 class="app-title">Lista kampanii</h1>
      <div class="app-subtitle">Zestawienie kampanii z systemu.</div>
    </div>
    <form method="get" action="kampanie_lista.php" class="campaign-list-filters">
      <input
        type="date"
        class="form-control form-control-sm filter-date"
        name="date_from"
        value="<?= htmlspecialchars($dateFrom ?? '') ?>"
        aria-label="Data od"
        title="Data od"
      >
      <input
        type="date"
        class="form-control form-control-sm filter-date"
        name="date_to"
        value="<?= htmlspecialchars($dateTo ?? '') ?>"
        aria-label="Data do"
        title="Data do"
      >
      <select name="campaign_type" class="form-select form-select-sm type-filter" aria-label="Typ kampanii">
        <option value="">Typ: wszystkie</option>
        <option value="aktywna" <?= $typeFilter === 'aktywna' ? 'selected' : '' ?>>Aktywna</option>
        <option value="archiwalna" <?= $typeFilter === 'archiwalna' ? 'selected' : '' ?>>Archiwalna</option>
        <option value="propozycja" <?= $typeFilter === 'propozycja' ? 'selected' : '' ?>>Propozycja</option>
        <option value="w_produkcji" <?= $typeFilter === 'w_produkcji' ? 'selected' : '' ?>>W produkcji</option>
      </select>
      <button type="submit" class="btn btn-sm btn-primary filter-btn">Filtruj</button>
      <?php if ($hasActiveFilters): ?>
        <a href="kampanie_lista.php" class="btn btn-sm btn-ghost filter-btn">Wyczyść</a>
      <?php endif; ?>
    </form>
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
      <table class="table table-zebra table-sm align-middle campaign-list-table">
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
          <?php $typeMeta = campaignTypeMeta($r); ?>
          <tr>
            <td><?= (int)($r['id'] ?? 0) ?></td>
            <td><?= htmlspecialchars($r['klient_nazwa'] ?? '') ?></td>
            <td><?= htmlspecialchars($r['data_start'] ?? '') ?> - <?= htmlspecialchars($r['data_koniec'] ?? '') ?></td>
            <td><?= number_format((float)($r['razem_brutto'] ?? 0), 2, ',', ' ') ?> zł</td>
            <td>
              <span class="badge <?= htmlspecialchars($typeMeta['class']) ?>"><?= htmlspecialchars($typeMeta['label']) ?></span>
            </td>
            <td><?= htmlspecialchars($r['created_at'] ?? '') ?></td>
            <td class="text-end table-actions">
              <a class="btn btn-sm btn-primary" href="kampania_podglad.php?id=<?= (int)($r['id'] ?? 0) ?>">
                Podgląd
              </a>
              <form method="post" action="<?= BASE_URL ?>/kampania_usun.php" onsubmit="return confirm('Usunąć kampanię #<?= (int)($r['id'] ?? 0) ?>?');" style="display:inline-block;">
                <input type="hidden" name="id" value="<?= (int)($r['id'] ?? 0) ?>">
                <button class="btn btn-sm btn-danger" type="submit">Usuń</button>
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
