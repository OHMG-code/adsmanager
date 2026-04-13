<?php
declare(strict_types=1);

require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/briefs.php';
require_once __DIR__ . '/includes/documents.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
ensureLeadBriefsTable($pdo);
ensureKampanieOwnershipColumns($pdo);

$bucketDefinitions = briefOperationalStateDefinitions();
$openBuckets = ['requires_attention', 'do_wyslania', 'oczekuje_na_brief', 'gotowe_do_produkcji', 'w_produkcji'];
$closedBuckets = ['zamkniete'];
$allBuckets = array_keys($bucketDefinitions);

$viewFilter = trim((string)($_GET['view'] ?? 'open'));
if (!in_array($viewFilter, ['open', 'all', 'closed'], true)) {
    $viewFilter = 'open';
}
$bucketFilter = trim((string)($_GET['bucket'] ?? ''));
if ($bucketFilter !== '' && !isset($bucketDefinitions[$bucketFilter])) {
    $bucketFilter = '';
}

$buildReturnUrl = static function (string $view, string $bucket): string {
    $params = [];
    if ($view !== 'open') {
        $params['view'] = $view;
    }
    if ($bucket !== '') {
        $params['bucket'] = $bucket;
    }
    return 'briefy.php' . ($params ? ('?' . http_build_query($params)) : '');
};

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['brief_action'] ?? ''));
    $campaignId = isset($_POST['campaign_id']) ? (int)$_POST['campaign_id'] : 0;
    $redirectUrl = $buildReturnUrl(
        trim((string)($_POST['return_view'] ?? $viewFilter)),
        trim((string)($_POST['return_bucket'] ?? $bucketFilter))
    );

    $flashMessage = ['type' => 'warning', 'msg' => 'Nie rozpoznano akcji dla briefu.'];
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $flashMessage = ['type' => 'danger', 'msg' => 'Niepoprawny token formularza.'];
    } elseif ($campaignId <= 0) {
        $flashMessage = ['type' => 'warning', 'msg' => 'Nie znaleziono kampanii dla tej akcji.'];
    } else {
        try {
            $stmtCampaign = $pdo->prepare('SELECT id, klient_id, klient_nazwa, status, realization_status, source_lead_id, owner_user_id, data_start, data_koniec, created_at FROM kampanie WHERE id = :id LIMIT 1');
            $stmtCampaign->execute([':id' => $campaignId]);
            $campaign = $stmtCampaign->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$campaign) {
                throw new RuntimeException('Nie znaleziono kampanii powiązanej z tym briefem.');
            }
            if (normalizeRole($currentUser) === 'Handlowiec' && !canUserAccessKampania($pdo, $currentUser, $campaignId)) {
                throw new RuntimeException('Brak dostępu do briefów tej kampanii.');
            }

            $brief = getBriefByCampaignId($pdo, $campaignId);
            if ($action === 'send_brief_link') {
                if (!$brief) {
                    $brief = findOrCreateBriefForCampaign(
                        $pdo,
                        $campaignId,
                        (int)($campaign['source_lead_id'] ?? 0) > 0 ? (int)$campaign['source_lead_id'] : null
                    );
                }

                $clientEmail = '';
                if ((int)($campaign['klient_id'] ?? 0) > 0 && tableExists($pdo, 'klienci')) {
                    $stmtClient = $pdo->prepare('SELECT email FROM klienci WHERE id = :id LIMIT 1');
                    $stmtClient->execute([':id' => (int)$campaign['klient_id']]);
                    $clientEmail = trim((string)($stmtClient->fetchColumn() ?: ''));
                }

                $result = sendCampaignBriefLink($pdo, $currentUser, $campaign, $brief, $clientEmail, !empty($_POST['brief_force_resend']));
                $flashMessage = [
                    'type' => ($result['status'] ?? '') === 'duplicate' ? 'warning' : 'success',
                    'msg' => (string)($result['message'] ?? 'Link briefu został obsłużony.'),
                ];
            } elseif ($action === 'start_production') {
                $productionCheck = canStartProductionFromBrief($campaign, $brief);
                if (!$productionCheck['ok']) {
                    throw new RuntimeException((string)($productionCheck['reason'] ?? 'Nie można rozpocząć produkcji.'));
                }
                setCampaignRealizationStatus($pdo, $campaignId, 'w_produkcji');
                $flashMessage = ['type' => 'success', 'msg' => 'Kampania przeszła do etapu: W produkcji.'];
            }
        } catch (Throwable $e) {
            $flashMessage = ['type' => 'warning', 'msg' => $e->getMessage()];
        }
    }

    $_SESSION['flash'] = $flashMessage;
    header('Location: ' . BASE_URL . '/' . $redirectUrl);
    exit;
}

$rows = fetchOperationalBriefQueue($pdo);
if (normalizeRole($currentUser) === 'Handlowiec') {
    $rows = array_values(array_filter($rows, static function (array $row) use ($pdo, $currentUser): bool {
        return canUserAccessKampania($pdo, $currentUser, (int)($row['id'] ?? 0));
    }));
}

$counts = array_fill_keys($allBuckets, 0);
$grouped = array_fill_keys($allBuckets, []);
foreach ($rows as $row) {
    $stateKey = (string)($row['brief_state']['key'] ?? '');
    if (!isset($grouped[$stateKey])) {
        continue;
    }
    $counts[$stateKey]++;
    $grouped[$stateKey][] = $row;
}

$visibleBuckets = $viewFilter === 'closed'
    ? $closedBuckets
    : ($viewFilter === 'all' ? $allBuckets : $openBuckets);
if ($bucketFilter !== '') {
    $visibleBuckets = in_array($bucketFilter, $visibleBuckets, true) ? [$bucketFilter] : [];
}

$totalOpen = 0;
foreach ($openBuckets as $bucketKey) {
    $totalOpen += (int)($counts[$bucketKey] ?? 0);
}
$totalClosed = (int)($counts['zamkniete'] ?? 0);
$requiresActionNow = (int)($counts['requires_attention'] ?? 0) + (int)($counts['do_wyslania'] ?? 0) + (int)($counts['gotowe_do_produkcji'] ?? 0);

$formatDate = static function (?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($raw))->format('d.m.Y');
    } catch (Throwable $e) {
        return $raw;
    }
};

$previewText = static function (?string $text, int $limit = 140): string {
    $clean = trim(preg_replace('/\s+/', ' ', (string)$text) ?? '');
    if ($clean === '') {
        return 'Brak danych';
    }
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($clean, 'UTF-8') > $limit ? (mb_substr($clean, 0, $limit, 'UTF-8') . '…') : $clean;
    }
    return strlen($clean) > $limit ? (substr($clean, 0, $limit) . '...') : $clean;
};

$personLabel = static function (array $row, string $prefix): string {
    $full = trim((string)($row[$prefix . '_imie'] ?? '') . ' ' . (string)($row[$prefix . '_nazwisko'] ?? ''));
    if ($full !== '') {
        return $full;
    }
    $login = trim((string)($row[$prefix . '_login'] ?? ''));
    return $login !== '' ? $login : 'Nie przypisano';
};

$pageTitle = 'Briefy';
$pageStyles = ['briefs'];
include 'includes/header.php';
?>
<main class="page-shell brief-worklist-page">
  <div class="app-header">
    <div>
      <h1 class="app-title">Briefy</h1>
      <p class="app-subtitle">Operacyjna kolejka pracy: od wysyłki briefu do wejścia kampanii w produkcję.</p>
    </div>
    <div class="app-header-actions">
      <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($buildReturnUrl('open', '')) ?>">Otwarte</a>
      <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($buildReturnUrl('all', '')) ?>">Wszystkie</a>
      <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($buildReturnUrl('closed', '')) ?>">Archiwalne</a>
    </div>
  </div>

  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars((string)($flash['type'] ?? 'info')) ?>">
      <?= htmlspecialchars((string)($flash['msg'] ?? '')) ?>
    </div>
  <?php endif; ?>

  <div class="brief-overview-grid">
    <div class="card">
      <div class="card-body">
        <div class="brief-overview-label">Wymagają działania teraz</div>
        <div class="brief-overview-value"><?= $requiresActionNow ?></div>
        <div class="brief-overview-copy">Do wysłania, gotowe do produkcji lub niespójne z workflow.</div>
      </div>
    </div>
    <div class="card">
      <div class="card-body">
        <div class="brief-overview-label">Otwarte briefy</div>
        <div class="brief-overview-value"><?= $totalOpen ?></div>
        <div class="brief-overview-copy">Briefy, które nadal są aktywnym etapem pracy operacyjnej.</div>
      </div>
    </div>
    <div class="card">
      <div class="card-body">
        <div class="brief-overview-label">Gotowe do produkcji</div>
        <div class="brief-overview-value"><?= (int)($counts['gotowe_do_produkcji'] ?? 0) ?></div>
        <div class="brief-overview-copy">Brief został odesłany i można rozpocząć etap produkcji.</div>
      </div>
    </div>
    <div class="card">
      <div class="card-body">
        <div class="brief-overview-label">Archiwalne / dalej</div>
        <div class="brief-overview-value"><?= $totalClosed ?></div>
        <div class="brief-overview-copy">Briefy, które przeszły już dalej w workflow lub są zamknięte.</div>
      </div>
    </div>
  </div>

  <div class="tabs brief-filter-tabs">
    <a href="<?= htmlspecialchars($buildReturnUrl($viewFilter, '')) ?>" class="<?= $bucketFilter === '' ? 'active' : '' ?>">Wszystkie w tym widoku</a>
    <?php foreach ($visibleBuckets as $bucketKey): ?>
      <?php $bucketMeta = $bucketDefinitions[$bucketKey] ?? null; ?>
      <?php if (!$bucketMeta): ?>
        <?php continue; ?>
      <?php endif; ?>
      <a href="<?= htmlspecialchars($buildReturnUrl($viewFilter, $bucketKey)) ?>" class="<?= $bucketFilter === $bucketKey ? 'active' : '' ?>">
        <?= htmlspecialchars((string)$bucketMeta['label']) ?> <span class="brief-filter-count"><?= (int)($counts[$bucketKey] ?? 0) ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <?php
    $hasVisibleRows = false;
    foreach ($visibleBuckets as $bucketKey) {
        if (!empty($grouped[$bucketKey])) {
            $hasVisibleRows = true;
            break;
        }
    }
  ?>

  <?php if (!$hasVisibleRows): ?>
    <div class="card">
      <div class="card-body">
        <h2 class="section-header-title">Brak briefów w tym widoku</h2>
        <p class="section-header-copy mb-0">Nie znaleziono briefów spełniających wybrany filtr. Zmień widok albo wróć do otwartych briefów.</p>
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ($visibleBuckets as $bucketKey): ?>
    <?php
      $items = $grouped[$bucketKey] ?? [];
      $bucketMeta = $bucketDefinitions[$bucketKey] ?? null;
      if (!$bucketMeta) {
          continue;
      }
      if ($bucketFilter === '' && !$items) {
          continue;
      }
    ?>
    <section class="card brief-section-card" id="brief-bucket-<?= htmlspecialchars($bucketKey) ?>">
      <div class="card-header">
        <div class="section-header">
          <div>
            <h2 class="section-header-title"><?= htmlspecialchars((string)$bucketMeta['label']) ?></h2>
            <p class="section-header-copy"><?= htmlspecialchars((string)$bucketMeta['description']) ?></p>
          </div>
          <span class="badge <?= htmlspecialchars((string)($bucketMeta['badge_class'] ?? 'badge-neutral')) ?>"><?= count($items) ?></span>
        </div>
      </div>
      <div class="card-body">
        <?php if (!$items): ?>
          <div class="text-muted">Brak briefów w tej grupie.</div>
        <?php else: ?>
          <div class="brief-queue">
            <?php foreach ($items as $row): ?>
              <?php
                $brief = $row['brief'] ?? null;
                $state = $row['brief_state'] ?? [];
                $campaign = $row['campaign'] ?? [];
                $dateStart = $formatDate((string)($campaign['data_start'] ?? ''));
                $dateEnd = $formatDate((string)($campaign['data_koniec'] ?? ''));
                $dateLabel = trim($dateStart . ($dateStart !== '' || $dateEnd !== '' ? ' - ' : '') . $dateEnd, ' -');
                $ownerLabel = $personLabel($row, 'owner');
                $productionOwnerLabel = $personLabel($row, 'production_owner');
                $briefStatus = $brief ? briefStatusLabel((string)($brief['status'] ?? 'draft')) : 'Brak rekordu briefu';
                $realizationLabel = campaignRealizationStatusLabel((string)($campaign['realization_status'] ?? ''));
                $showStartAction = !in_array((string)($state['key'] ?? ''), ['w_produkcji', 'zamkniete'], true);
              ?>
              <article class="brief-queue-item">
                <div class="brief-queue-main">
                  <div class="brief-queue-head">
                    <div>
                      <div class="brief-campaign-title">
                        <?= htmlspecialchars((string)($campaign['klient_nazwa'] ?? 'Kampania bez nazwy klienta')) ?>
                      </div>
                      <div class="brief-campaign-meta">
                        Kampania #<?= (int)($campaign['id'] ?? 0) ?>
                        <?php if ($dateLabel !== ''): ?>
                          | <?= htmlspecialchars($dateLabel) ?>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="brief-badge-stack">
                      <span class="badge <?= htmlspecialchars((string)($state['badge_class'] ?? 'badge-neutral')) ?>"><?= htmlspecialchars((string)($state['label'] ?? '')) ?></span>
                      <span class="badge badge-neutral"><?= htmlspecialchars($briefStatus) ?></span>
                      <?php if ($realizationLabel !== ''): ?>
                        <span class="badge badge-neutral"><?= htmlspecialchars($realizationLabel) ?></span>
                      <?php endif; ?>
                    </div>
                  </div>

                  <div class="brief-meta-grid">
                    <div><span class="text-muted">Opiekun handlowy:</span> <?= htmlspecialchars($ownerLabel) ?></div>
                    <div><span class="text-muted">Opiekun produkcji:</span> <?= htmlspecialchars($productionOwnerLabel) ?></div>
                    <div><span class="text-muted">Wysyłki briefu:</span> <?= (int)($row['brief_dispatch_count'] ?? 0) ?></div>
                    <div><span class="text-muted">Klient odesłał:</span> <?= !empty($brief['submitted_at']) ? htmlspecialchars((string)$brief['submitted_at']) : 'Jeszcze nie' ?></div>
                  </div>

                  <div class="brief-next-step">
                    <div class="brief-next-step-label"><?= htmlspecialchars((string)(($state['next_action']['label'] ?? 'Następny krok'))) ?></div>
                    <div class="brief-next-step-copy"><?= htmlspecialchars((string)($state['next_action']['hint'] ?? '')) ?></div>
                  </div>

                  <?php if ($brief): ?>
                    <div class="brief-summary-grid">
                      <div class="brief-summary-item">
                        <span class="text-muted">Czas spotu</span>
                        <strong><?= htmlspecialchars((string)($brief['spot_length_seconds'] ?? '-')) ?> s</strong>
                      </div>
                      <div class="brief-summary-item">
                        <span class="text-muted">Lektorzy</span>
                        <strong><?= htmlspecialchars((string)($brief['lector_count'] ?? 'Bez preferencji')) ?></strong>
                      </div>
                      <div class="brief-summary-item brief-summary-wide">
                        <span class="text-muted">Grupa docelowa</span>
                        <strong><?= htmlspecialchars($previewText((string)($brief['target_group'] ?? ''), 120)) ?></strong>
                      </div>
                      <div class="brief-summary-item brief-summary-wide">
                        <span class="text-muted">Najważniejszy przekaz</span>
                        <strong><?= htmlspecialchars($previewText((string)($brief['main_message'] ?? ''), 160)) ?></strong>
                      </div>
                      <div class="brief-summary-item brief-summary-wide">
                        <span class="text-muted">Styl reklamy</span>
                        <strong><?= htmlspecialchars($previewText((string)($brief['tone_style'] ?? ''), 120)) ?></strong>
                      </div>
                      <div class="brief-summary-item brief-summary-wide">
                        <span class="text-muted">Uwagi klienta</span>
                        <strong><?= htmlspecialchars($previewText((string)($brief['notes'] ?? ''), 140)) ?></strong>
                      </div>
                    </div>
                  <?php else: ?>
                    <div class="alert alert-secondary brief-inline-alert mb-0">
                      Rekord briefu zostanie utworzony przy pierwszej wysyłce linku do klienta.
                    </div>
                  <?php endif; ?>
                </div>

                <div class="brief-queue-actions">
                  <a class="btn btn-outline-secondary w-100" href="kampania_podglad.php?id=<?= (int)($campaign['id'] ?? 0) ?>">Otwórz kampanię</a>

                  <?php if ($brief): ?>
                    <a class="btn btn-outline-primary w-100" href="<?= htmlspecialchars(BASE_URL . '/brief/' . urlencode((string)($brief['token'] ?? ''))) ?>" target="_blank">Otwórz brief</a>
                  <?php else: ?>
                    <button class="btn btn-outline-primary w-100" type="button" disabled>Otwórz brief</button>
                  <?php endif; ?>

                  <?php if (!empty($state['can_send_brief'])): ?>
                    <form method="post" class="w-100">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                      <input type="hidden" name="brief_action" value="send_brief_link">
                      <input type="hidden" name="campaign_id" value="<?= (int)($campaign['id'] ?? 0) ?>">
                      <input type="hidden" name="return_view" value="<?= htmlspecialchars($viewFilter) ?>">
                      <input type="hidden" name="return_bucket" value="<?= htmlspecialchars($bucketFilter !== '' ? $bucketFilter : $bucketKey) ?>">
                      <?php if ((int)($row['brief_dispatch_count'] ?? 0) > 0): ?>
                        <input type="hidden" name="brief_force_resend" value="1">
                      <?php endif; ?>
                      <button class="btn btn-primary w-100" type="submit"><?= (int)($row['brief_dispatch_count'] ?? 0) > 0 ? 'Wyślij brief ponownie' : 'Wyślij brief' ?></button>
                    </form>
                  <?php else: ?>
                    <button class="btn btn-primary w-100" type="button" disabled>Wyślij brief</button>
                    <?php if (!empty($state['send_block_reason'])): ?>
                      <div class="brief-action-note"><?= htmlspecialchars((string)$state['send_block_reason']) ?></div>
                    <?php endif; ?>
                  <?php endif; ?>

                  <?php if ($showStartAction): ?>
                    <form method="post" class="w-100">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                      <input type="hidden" name="brief_action" value="start_production">
                      <input type="hidden" name="campaign_id" value="<?= (int)($campaign['id'] ?? 0) ?>">
                      <input type="hidden" name="return_view" value="<?= htmlspecialchars($viewFilter) ?>">
                      <input type="hidden" name="return_bucket" value="<?= htmlspecialchars($bucketFilter !== '' ? $bucketFilter : $bucketKey) ?>">
                      <button class="btn btn-outline-secondary w-100" type="submit" <?= !empty($state['can_start_production']) ? '' : 'disabled' ?>>Rozpocznij produkcję</button>
                    </form>
                    <?php if (empty($state['can_start_production']) && !empty($state['production_block_reason'])): ?>
                      <div class="brief-action-note"><?= htmlspecialchars((string)$state['production_block_reason']) ?></div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  <?php endforeach; ?>
</main>
<?php include 'includes/footer.php'; ?>
