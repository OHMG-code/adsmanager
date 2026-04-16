<?php
declare(strict_types=1);
require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/briefs.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/documents.php';
require_once __DIR__ . '/includes/process_timeline.php';
require_once __DIR__ . '/includes/mail_service.php';
require_once __DIR__ . '/includes/mailbox_service.php';
require_once __DIR__ . '/includes/communication_templates.php';
require_once __DIR__ . '/includes/communication_events.php';
requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
ensureKampanieOwnershipColumns($pdo);
ensureKampanieSalesValueColumn($pdo);
ensureLeadBriefsTable($pdo);
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Lista kampanii, gdy brak id
if ($id <= 0) {
    $rows = [];
    try {
        $stmt = $pdo->query("SELECT id, klient_nazwa, data_start, data_koniec, razem_brutto FROM kampanie ORDER BY id DESC LIMIT 200");
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: blad pobierania listy kampanii: ' . $e->getMessage());
    }

    $pageTitle = 'Lista kampanii';
    include 'includes/header.php';
    ?>
    <div class="container py-4">
      <h3>Lista kampanii</h3>
      <?php if (!empty($rows)): ?>
        <div class="table-responsive">
          <table class="table table-sm table-striped">
            <thead class="table-light">
              <tr><th>ID</th><th>Klient</th><th>Okres</th><th>Brutto</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars($r['klient_nazwa'] ?? '') ?></td>
                <td><?= htmlspecialchars(($r['data_start'] ?? '') . ' - ' . ($r['data_koniec'] ?? '')) ?></td>
                <td><?= number_format((float)($r['razem_brutto'] ?? 0), 2, ',', ' ') ?> zł</td>
                <td><a class="btn btn-sm btn-primary" href="kampania_podglad.php?id=<?= (int)$r['id'] ?>">Podgląd</a></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php else: ?>
        <p class="text-muted">Brak zapisanych kampanii.</p>
      <?php endif; ?>
      <div class="mt-3"><a class="btn btn-secondary" href="kampanie_lista.php">Lista kampanii</a></div>
    </div>
    <?php
    include 'includes/footer.php';
    exit;
}

// Pobierz kampanie z nowej tabeli "kampanie"
$source = 'kampanie';
$k = null;
$stmt = $pdo->prepare('SELECT id, klient_id, klient_nazwa, dlugosc_spotu, data_start, data_koniec, netto_spoty, netto_dodatki, rabat, razem_netto, razem_brutto, propozycja, status, realization_status, source_lead_id, wartosc_netto, created_at FROM kampanie WHERE id = :id');
if ($stmt && $stmt->execute([':id' => $id])) {
    $k = $stmt->fetch();
}

// Fallback do starej tabeli "kampanie_tygodniowe" (z polami sumy/produkty/siatka)
if (!$k) {
    $source = 'kampanie_tygodniowe';
    $stmt = $pdo->prepare('SELECT id, klient_nazwa, dlugosc, data_start, data_koniec, netto_spoty, netto_dodatki, rabat, razem_po_rabacie, razem_brutto, sumy, produkty, siatka, created_at
      FROM kampanie_tygodniowe WHERE id = :id');
    if ($stmt && $stmt->execute([':id' => $id])) {
        $k = $stmt->fetch();
    }
}

if (!$k) {
    http_response_code(404);
    exit('Nie znaleziono kampanii.');
}

$klientId = isset($k['klient_id']) ? (int)$k['klient_id'] : 0;
if ($klientId <= 0 && $source === 'kampanie') {
    $resolvedClientId = resolveClientIdForCampaign($pdo, $id);
    if ($resolvedClientId > 0) {
        $klientId = $resolvedClientId;
        $k['klient_id'] = $resolvedClientId;
    }
}

$campaignAccessAllowed = true;
if ($source === 'kampanie' && normalizeRole($currentUser) === 'Handlowiec') {
    $campaignAccessAllowed = canUserAccessKampania($pdo, $currentUser, $id);
    if (!$campaignAccessAllowed) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Brak dostępu do tej kampanii.'];
        header('Location: ' . BASE_URL . '/kampanie_lista.php');
        exit;
    }
}

$klientEmail = '';
$docAlerts = [];
$generatedDoc = null;
if ($klientId > 0) {
    try {
        $klientStmt = $pdo->prepare('SELECT email FROM klienci WHERE id = :id');
        if ($klientStmt && $klientStmt->execute([':id' => $klientId])) {
            $klientEmail = trim((string)($klientStmt->fetchColumn() ?: ''));
        }
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: nie udało się pobrać emaila klienta: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['doc_action'] ?? '') === 'generate_aneks') {
    if (!$currentUser) {
        $docAlerts[] = ['type' => 'danger', 'msg' => 'Brak aktywnej sesji użytkownika.'];
    } else {
        $startDate = trim((string)($_POST['aneks_start_date'] ?? ''));
        $result = generateDocument($pdo, $currentUser, 'aneks', $klientId, $id, ['start_date' => $startDate]);
        if (!empty($result['success'])) {
            $generatedDoc = $result;
            $docAlerts[] = ['type' => 'success', 'msg' => 'Aneks terminowy został wygenerowany.'];
        } else {
            $docAlerts[] = ['type' => 'danger', 'msg' => $result['error'] ?? 'Nie udało się wygenerować aneksu.'];
        }
    }
}

$brief = $source === 'kampanie' ? getBriefByCampaignId($pdo, $id) : null;
$briefPublicUrl = $brief ? (BASE_URL . '/brief/' . urlencode((string)($brief['token'] ?? ''))) : '';
$briefReadonly = $brief ? (briefIsSubmitted($brief) && !briefIsCustomerEditable($brief)) : false;
$productionUsers = [];
if ($source === 'kampanie' && tableExists($pdo, 'uzytkownicy')) {
    try {
        ensureUserColumns($pdo);
        $stmtUsers = $pdo->query('SELECT id, login, imie, nazwisko FROM uzytkownicy ORDER BY login ASC');
        $productionUsers = $stmtUsers ? ($stmtUsers->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: production users fetch failed: ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['brief_action'] ?? '') !== '' && $source === 'kampanie') {
    $briefAction = trim((string)($_POST['brief_action'] ?? ''));
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $docAlerts[] = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF dla briefu.'];
    } elseif (normalizeRole($currentUser) === 'Handlowiec' && !canUserAccessKampania($pdo, $currentUser, $id)) {
        $docAlerts[] = ['type' => 'danger', 'msg' => 'Brak dostępu do briefu tej kampanii.'];
    } else {
        try {
            $workingBrief = $brief;
            if ($briefAction === 'send_brief_link' && !$workingBrief) {
                $workingBrief = findOrCreateBriefForCampaign(
                    $pdo,
                    $id,
                    (int)($k['source_lead_id'] ?? 0) > 0 ? (int)$k['source_lead_id'] : null
                );
            }

            if (!$workingBrief && $briefAction !== 'send_brief_link') {
                throw new RuntimeException('Ta kampania nie ma jeszcze briefu.');
            }

            if ($briefAction === 'unlock_customer_edit') {
                setBriefCustomerEditable($pdo, (int)$workingBrief['id'], true);
                $docAlerts[] = ['type' => 'success', 'msg' => 'Brief został odblokowany do ponownej edycji przez klienta.'];
            } elseif ($briefAction === 'quick_start_production') {
                $productionCheck = canStartProductionFromBrief($k, $workingBrief);
                if (!$productionCheck['ok']) {
                    throw new RuntimeException((string)($productionCheck['reason'] ?? 'Nie można rozpocząć produkcji.'));
                }
                setCampaignRealizationStatus($pdo, $id, 'w_produkcji');
                $docAlerts[] = ['type' => 'success', 'msg' => 'Kampania przeszła do etapu: W produkcji.'];
            } elseif ($briefAction === 'quick_go_emission') {
                setCampaignRealizationStatus($pdo, $id, 'do_emisji');
                $docAlerts[] = ['type' => 'success', 'msg' => 'Kampania przeszła do etapu: Do emisji.'];
            } elseif ($briefAction === 'save_production') {
                $ownerUserId = isset($_POST['production_owner_user_id']) ? (int)$_POST['production_owner_user_id'] : 0;
                $externalStudio = trim((string)($_POST['production_external_studio'] ?? ''));
                updateBriefProductionData($pdo, (int)$workingBrief['id'], $ownerUserId > 0 ? $ownerUserId : null, $externalStudio);

                $targetRealizationStatus = trim((string)($_POST['realization_status'] ?? ''));
                if ($targetRealizationStatus !== '' && array_key_exists($targetRealizationStatus, campaignRealizationStatusDefinitions())) {
                    setCampaignRealizationStatus($pdo, $id, $targetRealizationStatus);
                }
                $docAlerts[] = ['type' => 'success', 'msg' => 'Dane realizacji zostały zapisane.'];
            } elseif ($briefAction === 'send_brief_link') {
                $sendResult = sendCampaignBriefLink($pdo, $currentUser, $k, $workingBrief, $klientEmail, !empty($_POST['brief_force_resend']));
                $alertType = ($sendResult['status'] ?? '') === 'duplicate' ? 'warning' : 'success';
                $docAlerts[] = ['type' => $alertType, 'msg' => (string)($sendResult['message'] ?? 'Link briefu został obsłużony.')];
            }

            $brief = getBriefByCampaignId($pdo, $id);
            $briefPublicUrl = $brief ? (BASE_URL . '/brief/' . urlencode((string)($brief['token'] ?? ''))) : '';
            $briefReadonly = $brief ? (briefIsSubmitted($brief) && !briefIsCustomerEditable($brief)) : false;
            $stmtRefreshCampaign = $pdo->prepare('SELECT realization_status FROM kampanie WHERE id = :id LIMIT 1');
            $stmtRefreshCampaign->execute([':id' => $id]);
            $k['realization_status'] = (string)($stmtRefreshCampaign->fetchColumn() ?: '');
            $realizationStatus = trim((string)($k['realization_status'] ?? ''));
        } catch (Throwable $e) {
            $docAlerts[] = ['type' => 'danger', 'msg' => $e->getMessage()];
        }
    }
}

$missingAudioCount = 0;
$campaignAudioSummary = [
    'spots_total' => 0,
    'spots_with_any_audio' => 0,
    'spots_with_final' => 0,
    'dispatches_total' => 0,
];
$campaignTimelineEvents = [];
try {
    require_once __DIR__ . '/includes/db_schema.php';
    require_once __DIR__ . '/includes/emisje_helpers.php';
    ensureSpotAudioFilesTable($pdo);
    ensureSpotAudioDispatchesTable($pdo);
    ensureSpotAudioDispatchItemsTable($pdo);
    if ($source === 'kampanie' && tableExists($pdo, 'spoty')) {
        $acceptedValues = acceptedAudioStatusSqlValues();
        $in = implode(',', array_fill(0, count($acceptedValues), '?'));
        $sqlMissing = "SELECT COUNT(*)
            FROM spoty s
            LEFT JOIN spot_audio_files saf
                ON saf.spot_id = s.id
               AND saf.is_active = 1
               AND LOWER(TRIM(COALESCE(saf.production_status, ''))) IN ($in)
            WHERE s.kampania_id = ?
              AND saf.id IS NULL";
        $stmtMissing = $pdo->prepare($sqlMissing);
        $stmtMissing->execute(array_merge($acceptedValues, [$id]));
        $missingAudioCount = (int)($stmtMissing->fetchColumn() ?? 0);

        $stmtSummary = $pdo->prepare("SELECT
                COUNT(DISTINCT s.id) AS spots_total,
                COUNT(DISTINCT CASE WHEN f_any.id IS NOT NULL THEN s.id END) AS spots_with_any_audio,
                COUNT(DISTINCT CASE WHEN f_final.id IS NOT NULL THEN s.id END) AS spots_with_final
            FROM spoty s
            LEFT JOIN spot_audio_files f_any ON f_any.spot_id = s.id
            LEFT JOIN spot_audio_files f_final
                ON f_final.spot_id = s.id
               AND f_final.is_final = 1
               AND LOWER(TRIM(COALESCE(f_final.production_status, ''))) IN ($in)
            WHERE s.kampania_id = ?");
        $stmtSummary->execute(array_merge($acceptedValues, [$id]));
        $summaryRow = $stmtSummary->fetch(PDO::FETCH_ASSOC) ?: [];
        $campaignAudioSummary['spots_total'] = (int)($summaryRow['spots_total'] ?? 0);
        $campaignAudioSummary['spots_with_any_audio'] = (int)($summaryRow['spots_with_any_audio'] ?? 0);
        $campaignAudioSummary['spots_with_final'] = (int)($summaryRow['spots_with_final'] ?? 0);

        if (tableExists($pdo, 'spot_audio_dispatches')) {
            $stmtDispatch = $pdo->prepare('SELECT COUNT(*) FROM spot_audio_dispatches WHERE campaign_id = :campaign_id');
            $stmtDispatch->execute([':campaign_id' => $id]);
            $campaignAudioSummary['dispatches_total'] = (int)($stmtDispatch->fetchColumn() ?? 0);
        }
    }
} catch (Throwable $e) {
    error_log('kampania_podglad.php: audio check failed: ' . $e->getMessage());
}

if ($source === 'kampanie') {
    try {
        $campaignTimelineEvents = buildCampaignTimelineEvents($pdo, $id, ['limit' => 80]);
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: timeline build failed: ' . $e->getMessage());
        $campaignTimelineEvents = [];
    }
}

$campaignWorkflow = [];
$briefOperationalState = [];
$quickActionSpotId = 0;
if ($source === 'kampanie') {
    try {
        $campaignWorkflow = buildCampaignWorkflowState($pdo, $id, $k, $brief, $campaignAudioSummary);
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: workflow state failed: ' . $e->getMessage());
        $campaignWorkflow = [];
    }
    try {
        $briefOperationalState = resolveBriefOperationalState($k, $brief, [
            'brief_dispatch_count' => (int)($campaignWorkflow['brief_dispatch_count'] ?? 0),
            'client_email' => $klientEmail,
        ]);
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: brief operational state failed: ' . $e->getMessage());
        $briefOperationalState = [];
    }
    if (tableExists($pdo, 'spoty')) {
        try {
            $stmtQuickSpot = $pdo->prepare('SELECT id FROM spoty WHERE kampania_id = :id ORDER BY id ASC LIMIT 1');
            $stmtQuickSpot->execute([':id' => $id]);
            $quickActionSpotId = (int)($stmtQuickSpot->fetchColumn() ?? 0);
        } catch (Throwable $e) {
            error_log('kampania_podglad.php: quick spot fetch failed: ' . $e->getMessage());
            $quickActionSpotId = 0;
        }
    }
}

// Mapowanie pol z obu tabel
$dlugosc = isset($k['dlugosc_spotu']) ? (int)$k['dlugosc_spotu'] : (int)($k['dlugosc'] ?? 0);
$razemPoRab = $k['razem_po_rabacie'] ?? $k['razem_netto'] ?? null;
$campaignStatusNorm = strtolower(trim((string)($k['status'] ?? '')));
$isProposalCampaign = !empty($k['propozycja']) || $campaignStatusNorm === 'propozycja';
$campaignVariantLabel = $isProposalCampaign ? 'Propozycja' : 'Aktywna';
$sourceLeadId = isset($k['source_lead_id']) ? (int)$k['source_lead_id'] : 0;
$hasClientBinding = $klientId > 0;
$campaignStatusNormalized = str_replace('_', ' ', $campaignStatusNorm);
$isAcceptedCampaign = !$isProposalCampaign
    && in_array($campaignStatusNormalized, ['zamowiona', 'zamówiona', 'w realizacji', 'zakończona', 'zakonczona'], true);
$needsLeadConversion = $sourceLeadId > 0 && !$hasClientBinding;
$canAcceptCampaign = $source === 'kampanie'
    && $campaignAccessAllowed
    && ($sourceLeadId > 0 || $hasClientBinding)
    && ($isProposalCampaign || !$isAcceptedCampaign || $needsLeadConversion);
$offerSendUrl = '';
$offerSendDisabledReason = 'Brak email klienta';
if ($klientEmail !== '') {
    $offerSendUrl = 'wyslij_oferte.php?id=' . (int)$k['id'];
} elseif ($sourceLeadId > 0) {
    $offerSendUrl = 'lead_szczegoly.php?id=' . $sourceLeadId . '&offer_campaign_id=' . (int)$k['id'] . '#lead-mail-pane';
    $offerSendDisabledReason = '';
}
$realizationStatus = trim((string)($k['realization_status'] ?? ''));
$sumy = [];
$produkty = [];
$siatka = null;
if (!empty($k['sumy'])) {
    $tmp = json_decode($k['sumy'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $sumy = $tmp;
}
if (!empty($k['produkty'])) {
    $tmp = json_decode($k['produkty'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $produkty = $tmp;
}
if (!empty($k['siatka'])) {
    $tmp = json_decode($k['siatka'], true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($tmp)) $siatka = $tmp;
}

$days = ['mon' => 'Pon', 'tue' => 'Wt', 'wed' => 'Sr', 'thu' => 'Czw', 'fri' => 'Pt', 'sat' => 'Sob', 'sun' => 'Nd'];
$dayAliases = [
    'mon' => 'mon', 'monday' => 'mon', 'pon' => 'mon', 'pn' => 'mon',
    'tue' => 'tue', 'tuesday' => 'tue', 'wt' => 'tue',
    'wed' => 'wed', 'wednesday' => 'wed', 'sr' => 'wed', 'śr' => 'wed',
    'thu' => 'thu', 'thursday' => 'thu', 'czw' => 'thu',
    'fri' => 'fri', 'friday' => 'fri', 'pt' => 'fri',
    'sat' => 'sat', 'saturday' => 'sat', 'sob' => 'sat',
    'sun' => 'sun', 'sunday' => 'sun', 'nd' => 'sun', 'niedz' => 'sun',
];

function normalizeCampaignDateValue(?string $raw): ?DateTimeImmutable
{
    $raw = trim((string)$raw);
    if ($raw === '') {
        return null;
    }
    $datePart = substr($raw, 0, 10);
    $dt = DateTimeImmutable::createFromFormat('Y-m-d', $datePart);
    if ($dt instanceof DateTimeImmutable) {
        return $dt;
    }
    try {
        return new DateTimeImmutable($raw);
    } catch (Throwable $e) {
        return null;
    }
}

function campaignDowOccurrences(?DateTimeImmutable $start, ?DateTimeImmutable $end): array
{
    $out = ['mon' => 1, 'tue' => 1, 'wed' => 1, 'thu' => 1, 'fri' => 1, 'sat' => 1, 'sun' => 1];
    if (!$start || !$end || $start > $end) {
        return $out;
    }

    $out = ['mon' => 0, 'tue' => 0, 'wed' => 0, 'thu' => 0, 'fri' => 0, 'sat' => 0, 'sun' => 0];
    $map = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
    for ($d = $start; $d <= $end; $d = $d->modify('+1 day')) {
        $key = $map[(int)$d->format('N')] ?? null;
        if ($key) {
            $out[$key]++;
        }
    }
    return $out;
}

function campaignHourToBand(string $hour): string
{
    $hour = trim($hour);
    if ($hour === '>23:00') {
        return 'night';
    }
    if (!preg_match('/^(\d{2}):(\d{2})$/', $hour, $m)) {
        return 'night';
    }
    $h = (int)$m[1];
    if (($h >= 6 && $h < 10) || ($h >= 15 && $h < 19)) {
        return 'prime';
    }
    if (($h >= 10 && $h < 15) || ($h >= 19 && $h < 23)) {
        return 'standard';
    }
    return 'night';
}

if (!is_array($siatka)) {
    $siatka = [];
}

// Dla nowych kampanii pobieramy siatkę emisji bezpośrednio z kampanie_emisje.
if ($source === 'kampanie') {
    try {
        $stmtEmisje = $pdo->prepare('SELECT dzien_tygodnia, godzina, ilosc FROM kampanie_emisje WHERE kampania_id = :id');
        $stmtEmisje->execute([':id' => $id]);
        $emisjeRows = $stmtEmisje->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($emisjeRows as $row) {
            $dayRaw = strtolower(trim((string)($row['dzien_tygodnia'] ?? '')));
            $dayKey = $dayAliases[$dayRaw] ?? $dayRaw;
            if (!isset($days[$dayKey])) {
                continue;
            }

            $hourRaw = trim((string)($row['godzina'] ?? ''));
            if ($hourRaw === '') {
                continue;
            }
            $hourLabel = $hourRaw;
            if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $hourRaw)) {
                $hourLabel = substr($hourRaw, 0, 5);
            } elseif ($hourRaw === '23:59' || $hourRaw === '23:59:59') {
                $hourLabel = '>23:00';
            }
            if ($hourLabel === '23:59') {
                $hourLabel = '>23:00';
            }

            $qty = (int)($row['ilosc'] ?? 0);
            if ($qty <= 0) {
                continue;
            }

            if (!isset($siatka[$dayKey]) || !is_array($siatka[$dayKey])) {
                $siatka[$dayKey] = [];
            }
            $siatka[$dayKey][$hourLabel] = (int)($siatka[$dayKey][$hourLabel] ?? 0) + $qty;
        }
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: nie udało się pobrać emisji kampanii: ' . $e->getMessage());
    }
}

// Normalizacja kluczy dni/godzin dla jednolitej tabeli.
$normalizedSiatka = [];
foreach ($siatka as $dayRaw => $hoursMap) {
    if (!is_array($hoursMap)) {
        continue;
    }
    $dayKeyRaw = strtolower(trim((string)$dayRaw));
    $dayKey = $dayAliases[$dayKeyRaw] ?? $dayKeyRaw;
    if (!isset($days[$dayKey])) {
        continue;
    }
    if (!isset($normalizedSiatka[$dayKey])) {
        $normalizedSiatka[$dayKey] = [];
    }
    foreach ($hoursMap as $hourRaw => $qtyRaw) {
        $qty = (int)$qtyRaw;
        if ($qty <= 0) {
            continue;
        }
        $hour = trim((string)$hourRaw);
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $hour)) {
            $hour = substr($hour, 0, 5);
        } elseif ($hour === '23:59' || $hour === '23:59:59') {
            $hour = '>23:00';
        }
        $normalizedSiatka[$dayKey][$hour] = (int)($normalizedSiatka[$dayKey][$hour] ?? 0) + $qty;
    }
}
$siatka = $normalizedSiatka;

$siatkaHours = [];
for ($h = 6; $h <= 23; $h++) {
    $siatkaHours[] = sprintf('%02d:00', $h);
}
$siatkaHours[] = '>23:00';
$dateStartObj = normalizeCampaignDateValue((string)($k['data_start'] ?? ''));
$dateEndObj = normalizeCampaignDateValue((string)($k['data_koniec'] ?? ''));
$dowOccurrences = campaignDowOccurrences($dateStartObj, $dateEndObj);

$computedSumy = ['prime' => 0, 'standard' => 0, 'night' => 0];
$siatkaTotalSpots = 0;
$hasSiatkaValues = false;
foreach ($siatka as $hoursMap) {
    foreach ($hoursMap as $hour => $qty) {
        $qtyInt = (int)$qty;
        if ($qtyInt <= 0) {
            continue;
        }
        $hasSiatkaValues = true;
        $siatkaTotalSpots += $qtyInt;
        if (!in_array($hour, $siatkaHours, true)) {
            $siatkaHours[] = $hour;
        }
    }
}
usort($siatkaHours, static function (string $a, string $b): int {
    if ($a === '>23:00') {
        return 1;
    }
    if ($b === '>23:00') {
        return -1;
    }
    return strcmp($a, $b);
});

if ($hasSiatkaValues) {
    $computedTotal = 0;
    foreach ($siatka as $dayKey => $hoursMap) {
        $multiplier = (int)($dowOccurrences[$dayKey] ?? 1);
        if ($multiplier <= 0) {
            $multiplier = 1;
        }
        foreach ($hoursMap as $hour => $qty) {
            $qtyInt = (int)$qty;
            if ($qtyInt <= 0) {
                continue;
            }
            $band = campaignHourToBand((string)$hour);
            $computedSumy[$band] += $qtyInt * $multiplier;
            $computedTotal += $qtyInt * $multiplier;
        }
    }
    $sumy = $computedSumy;
    $siatkaTotalSpots = $computedTotal;
}

$produktyTotal = 0.0;
if (is_array($produkty)) {
    foreach ($produkty as $product) {
        if (!is_array($product)) {
            continue;
        }
        $kwota = $product['kwota'] ?? $product['amount'] ?? 0;
        $produktyTotal += (float)$kwota;
    }
}
$nettoSpotyValue = (float)($k['netto_spoty'] ?? 0);
$nettoDodatkiValue = (float)($k['netto_dodatki'] ?? 0);
$dodatkiDoRozliczenia = max($nettoDodatkiValue, $produktyTotal);
$nettoPrzedRabatem = $nettoSpotyValue + $dodatkiDoRozliczenia;
$rabatValue = (float)($k['rabat'] ?? 0);
$razemPoRabacieValue = $razemPoRab !== null ? (float)$razemPoRab : ($nettoPrzedRabatem * (1 - ($rabatValue / 100)));
$razemBruttoValue = (float)($k['razem_brutto'] ?? 0);
if ($razemBruttoValue <= 0 && $razemPoRabacieValue > 0) {
    $razemBruttoValue = $razemPoRabacieValue * 1.23;
}

function resolveCampaignAudioFilePath(?string $storedPath): ?string
{
    $storedPath = trim((string)$storedPath);
    if ($storedPath === '') {
        return null;
    }
    if ($storedPath[0] === '/' && is_file($storedPath)) {
        return $storedPath;
    }

    $normalized = ltrim($storedPath, '/');
    if (strpos($normalized, 'storage/') === 0) {
        return dirname(__DIR__) . '/' . $normalized;
    }
    return __DIR__ . '/' . $normalized;
}

function resolveCampaignAudioStorageTarget(): ?array
{
    $targets = [
        ['abs' => dirname(__DIR__) . '/storage/uploads/audio/', 'rel' => 'storage/uploads/audio/'],
        ['abs' => __DIR__ . '/uploads/audio/', 'rel' => 'uploads/audio/'],
    ];

    foreach ($targets as $target) {
        $dir = $target['abs'];
        if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
            continue;
        }
        if (is_writable($dir)) {
            return $target;
        }
    }

    return null;
}

function resolveSpotAudioStorageDir(): ?string
{
    $dir = dirname(__DIR__) . '/storage/audio';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }
    return is_writable($dir) ? $dir : null;
}

function syncCampaignAudioToMissingSpots(PDO $pdo, array $currentUser, int $campaignId, string $campaignAudioPath, string $audioOriginalName): array
{
    ensureSpotAudioFilesTable($pdo);
    ensureSpotColumns($pdo);

    $storageDir = resolveSpotAudioStorageDir();
    if (!$storageDir) {
        return ['ok' => false, 'assigned' => 0, 'errors' => ['Brak dostępu do katalogu storage/audio.']];
    }

    if (!is_file($campaignAudioPath)) {
        return ['ok' => false, 'assigned' => 0, 'errors' => ['Plik audio kampanii nie istnieje na serwerze.']];
    }

    $ext = strtolower(pathinfo($campaignAudioPath, PATHINFO_EXTENSION));
    if (!in_array($ext, ['wav', 'mp3'], true)) {
        return ['ok' => false, 'assigned' => 0, 'errors' => ['Audio kampanii ma niedozwolone rozszerzenie (dozwolone: wav, mp3).']];
    }

    $stmtSpots = $pdo->prepare("
        SELECT s.id
        FROM spoty s
        LEFT JOIN spot_audio_files saf
            ON saf.spot_id = s.id AND saf.is_active = 1 AND saf.production_status = 'Zaakceptowany'
        WHERE s.kampania_id = :kampania_id
          AND saf.id IS NULL
        ORDER BY s.id ASC
    ");
    $stmtSpots->execute([':kampania_id' => $campaignId]);
    $spotIds = array_map('intval', array_column($stmtSpots->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
    if (!$spotIds) {
        return ['ok' => true, 'assigned' => 0, 'errors' => []];
    }

    $assigned = 0;
    $errors = [];
    $uploadedBy = (int)($currentUser['id'] ?? 0);

    $stmtMaxVersion = $pdo->prepare("SELECT COALESCE(MAX(version_no), 0) FROM spot_audio_files WHERE spot_id = ?");
    $stmtDeactivate = $pdo->prepare("UPDATE spot_audio_files SET is_active = 0, is_final = 0 WHERE spot_id = ?");
    $stmtInsert = $pdo->prepare("INSERT INTO spot_audio_files
        (spot_id, version_no, is_active, is_final, original_filename, stored_filename, mime_type, file_size, sha256, production_status, uploaded_by_user_id, upload_note, approved_by_user_id, approved_at)
        VALUES (?, ?, 1, 1, ?, ?, ?, ?, ?, 'Zaakceptowany', ?, ?, ?, NOW())");

    foreach ($spotIds as $spotId) {
        try {
            $stmtMaxVersion->execute([$spotId]);
            $versionNo = ((int)$stmtMaxVersion->fetchColumn()) + 1;
            $storedFilename = sprintf('spot_%d_v%d_%d_%s.%s', $spotId, $versionNo, time(), bin2hex(random_bytes(4)), $ext);
            $targetPath = $storageDir . '/' . $storedFilename;

            if (!@copy($campaignAudioPath, $targetPath)) {
                $errors[] = 'Spot #' . $spotId . ': nie udało się skopiować pliku audio.';
                continue;
            }

            $fileSize = (int)@filesize($targetPath);
            $sha256 = hash_file('sha256', $targetPath) ?: null;
            $mimeType = function_exists('mime_content_type') ? (mime_content_type($targetPath) ?: null) : null;

            $pdo->beginTransaction();
            $stmtDeactivate->execute([$spotId]);
            $stmtInsert->execute([
                $spotId,
                $versionNo,
                $audioOriginalName !== '' ? $audioOriginalName : basename($campaignAudioPath),
                $storedFilename,
                $mimeType,
                $fileSize > 0 ? $fileSize : null,
                $sha256,
                $uploadedBy > 0 ? $uploadedBy : null,
                'Auto-przypisanie z kampanii #' . $campaignId,
                $uploadedBy > 0 ? $uploadedBy : null,
            ]);
            $pdo->commit();
            $assigned++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            @unlink($targetPath ?? '');
            $errors[] = 'Spot #' . $spotId . ': ' . $e->getMessage();
        }
    }

    return ['ok' => $assigned > 0 && !$errors, 'assigned' => $assigned, 'errors' => $errors];
}

// Audio
$uploadMessage = '';
$audioWebPath = $k['audio_file'] ?? null;
$audioPlaybackUrl = $audioWebPath ? (BASE_URL . '/kampania_audio.php?id=' . (int)$id) : '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['audio'])) {
  if ($id > 0 && isset($_FILES['audio']) && $_FILES['audio']['error'] === UPLOAD_ERR_OK) {
    $u = $_FILES['audio'];
    $filename = $id . '_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($u['name']));
    $target = resolveCampaignAudioStorageTarget();
    if (!$target) {
      $uploadMessage = 'Brak dostępu zapisu do katalogu audio (public/uploads/audio lub storage/uploads/audio).';
    } else {
      $dest = $target['abs'] . $filename;
      if (move_uploaded_file($u['tmp_name'], $dest)) {
      $webPath = $target['rel'] . $filename;
      try {
        $uStmt = $pdo->prepare("UPDATE {$source} SET audio_file = :f WHERE id = :id");
        $uStmt->execute([':f' => $webPath, ':id' => $id]);
        $uploadMessage = 'Plik został zapisany i przypięty do kampanii.';
        $audioWebPath = $webPath;
        $audioPlaybackUrl = BASE_URL . '/kampania_audio.php?id=' . (int)$id;
      } catch (Throwable $e) {
        // Spróbuj dodać kolumnę audio_file i ponowić zapis
        error_log('kampania_podglad.php: blad przy zapisie audio do DB: ' . $e->getMessage());
        $altered = false;
        try {
          $pdo->exec("ALTER TABLE {$source} ADD COLUMN audio_file VARCHAR(255) NULL");
          $altered = true;
        } catch (Throwable $e2) {
          error_log('kampania_podglad.php: nie udało się dodać kolumny audio_file: ' . $e2->getMessage());
        }

        if ($altered) {
          try {
            $uStmt = $pdo->prepare("UPDATE {$source} SET audio_file = :f WHERE id = :id");
            $uStmt->execute([':f' => $webPath, ':id' => $id]);
            $uploadMessage = 'Plik został zapisany i przypięty do kampanii.';
            $audioWebPath = $webPath;
            $audioPlaybackUrl = BASE_URL . '/kampania_audio.php?id=' . (int)$id;
          } catch (Throwable $e3) {
            error_log('kampania_podglad.php: blad przy zapisie audio po dodaniu kolumny: ' . $e3->getMessage());
            $uploadMessage = 'Plik został zapisany na serwerze: ' . $webPath . '. Nie udało się zapisać ścieżki w DB.';
          }
        } else {
          $uploadMessage = 'Plik został zapisany na serwerze: ' . $webPath . '. Nie udało się zapisać ścieżki w DB.';
        }
      }
      } else {
        $uploadMessage = 'Blad przenoszenia pliku na serwer.';
      }
    }
  } else {
    $uploadMessage = 'Brak pliku lub blad uploadu.';
  }
}

// Usuwanie pliku audio
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_audio']) && $id > 0) {
    $currentPath = $audioWebPath;
    if ($currentPath) {
        $full = resolveCampaignAudioFilePath($currentPath);
        if ($full && is_file($full)) {
            @unlink($full);
        }
    }
    try {
        $pdo->prepare("UPDATE {$source} SET audio_file = NULL WHERE id = :id")->execute([':id' => $id]);
        $audioWebPath = null;
        $audioPlaybackUrl = '';
        $uploadMessage = 'Plik audio został usunięty.';
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: blad usuwania audio_file: ' . $e->getMessage());
        $uploadMessage = 'Nie udało się wyczyścić ścieżki w DB, sprawdź logi.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sync_audio_to_spots']) && $source === 'kampanie' && $id > 0) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!isCsrfTokenValid($csrfToken)) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
        header('Location: ' . BASE_URL . '/kampania_podglad.php?id=' . $id);
        exit;
    }

    if (normalizeRole($currentUser) === 'Handlowiec' && !canUserAccessKampania($pdo, $currentUser, $id)) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Brak dostępu do tej kampanii.'];
        header('Location: ' . BASE_URL . '/kampanie_lista.php');
        exit;
    }

    $sourcePath = resolveCampaignAudioFilePath($audioWebPath);
    $originalName = '';
    if (is_file((string)$sourcePath)) {
        $originalName = basename((string)$sourcePath);
    }

    $syncResult = syncCampaignAudioToMissingSpots($pdo, $currentUser, $id, (string)$sourcePath, $originalName);
    if (!empty($syncResult['assigned'])) {
        $_SESSION['flash'] = [
            'type' => 'success',
            'msg' => 'Przypisano i zaakceptowano audio kampanii dla spotów: ' . (int)$syncResult['assigned'] . '.',
        ];
    } else {
        $details = !empty($syncResult['errors']) ? (' ' . implode(' | ', $syncResult['errors'])) : '';
        $_SESSION['flash'] = [
            'type' => 'warning',
            'msg' => 'Nie przypisano audio do spotów.' . $details,
        ];
    }

    header('Location: ' . BASE_URL . '/kampania_podglad.php?id=' . $id);
    exit;
}

$pageTitle = 'Kampania #' . (int)$k['id'];

// Formatowanie dat
$fmtStartStr = '';
$fmtEndStr = '';
if (!empty($k['data_start'])) {
  $d = DateTime::createFromFormat('Y-m-d', $k['data_start']) ?: DateTime::createFromFormat('Y-m-d H:i:s', $k['data_start']);
  $fmtStartStr = $d instanceof DateTime ? $d->format('d.m.Y') : htmlspecialchars($k['data_start']);
}
if (!empty($k['data_koniec'])) {
  $d = DateTime::createFromFormat('Y-m-d', $k['data_koniec']) ?: DateTime::createFromFormat('Y-m-d H:i:s', $k['data_koniec']);
  $fmtEndStr = $d instanceof DateTime ? $d->format('d.m.Y') : htmlspecialchars($k['data_koniec']);
}

$documents = [];
$documentsAllowed = true;
if ($currentUser && normalizeRole($currentUser) === 'Handlowiec') {
    $documentsAllowed = canUserAccessKampania($pdo, $currentUser, $id);
    if (!$documentsAllowed) {
        $docAlerts[] = ['type' => 'warning', 'msg' => 'Brak dostępu do archiwum dokumentów tej kampanii.'];
    }
}
if ($documentsAllowed) {
    try {
        ensureDocumentsTables($pdo);
        $stmtDocs = $pdo->prepare("SELECT d.*, u.login, u.imie, u.nazwisko
            FROM dokumenty d
            LEFT JOIN uzytkownicy u ON u.id = d.created_by_user_id
            WHERE d.kampania_id = :id
            ORDER BY d.created_at DESC");
        $stmtDocs->execute([':id' => $id]);
        $documents = $stmtDocs->fetchAll();
    } catch (Throwable $e) {
        error_log('kampania_podglad.php: dokumenty fetch failed: ' . $e->getMessage());
    }
}

$defaultAneksDate = '';
if (!empty($k['data_start'])) {
    $defaultAneksDate = substr((string)$k['data_start'], 0, 10);
}
if ($defaultAneksDate === '') {
    $defaultAneksDate = date('Y-m-d');
}

include 'includes/header.php';
?>
<div class="container py-4">
  <h3>Kampania #<?= (int)$k['id'] ?></h3>
  <?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info') ?> mt-3">
      <?= htmlspecialchars($flash['msg'] ?? '') ?>
    </div>
  <?php endif; ?>
  <?php if ($missingAudioCount > 0): ?>
    <div class="alert alert-danger mt-3">
      W kampanii są spoty bez zaakceptowanego audio (<?= (int)$missingAudioCount ?>). Emisja będzie zablokowana.
    </div>
  <?php endif; ?>

  <?php foreach ($docAlerts as $alert): ?>
    <div class="alert alert-<?= htmlspecialchars($alert['type']) ?> mt-3">
      <?= htmlspecialchars($alert['msg']) ?>
    </div>
  <?php endforeach; ?>

  <?php if (!empty($generatedDoc['doc_id'])): ?>
    <div class="alert alert-success mt-3">
      Dokument <?= htmlspecialchars($generatedDoc['doc_number'] ?? '') ?> został zapisany.
      <a class="ms-2" href="docs/download.php?id=<?= (int)$generatedDoc['doc_id'] ?>">Pobierz</a>
      <a class="ms-2" href="docs/download.php?id=<?= (int)$generatedDoc['doc_id'] ?>&inline=1" target="_blank">Podgląd</a>
    </div>
  <?php endif; ?>

  <div class="card mt-3">
    <div class="card-body">
      <h5 class="card-title mb-3">Najważniejsze informacje</h5>
      <div class="row g-3">
        <div class="col-lg-6">
          <div class="border rounded p-3 h-100">
            <div class="fw-semibold mb-2">Dane klienta i spotów</div>
            <div class="row g-2">
              <div class="col-5 text-muted small">ID kampanii</div>
              <div class="col-7 fw-semibold">#<?= (int)$k['id'] ?></div>
              <div class="col-5 text-muted small">Wariant</div>
              <div class="col-7"><?= htmlspecialchars($campaignVariantLabel) ?></div>
              <div class="col-5 text-muted small">Klient</div>
              <div class="col-7"><?= htmlspecialchars($k['klient_nazwa'] ?? '-') ?></div>
              <div class="col-5 text-muted small">Długość spotu</div>
              <div class="col-7"><?= (int)$dlugosc ?> sek</div>
              <div class="col-5 text-muted small">Okres kampanii</div>
              <div class="col-7"><?= $fmtStartStr ?: '&ndash;' ?> - <?= $fmtEndStr ?: '&ndash;' ?></div>
              <div class="col-5 text-muted small">Liczba spotów</div>
              <div class="col-7 fw-semibold"><?= (int)$siatkaTotalSpots ?></div>
            </div>
          </div>
        </div>
        <div class="col-lg-6">
          <div class="border rounded p-3 h-100">
            <div class="fw-semibold mb-2">Rozliczenie finansowe</div>
            <div class="row g-2">
              <div class="col-6 text-muted small">Netto spoty</div>
              <div class="col-6 text-end"><?= number_format($nettoSpotyValue, 2, ',', ' ') ?> zł</div>
              <div class="col-6 text-muted small">Dodatki (rozliczenie)</div>
              <div class="col-6 text-end"><?= number_format($dodatkiDoRozliczenia, 2, ',', ' ') ?> zł</div>
              <?php if ($produktyTotal > 0): ?>
                <div class="col-6 text-muted small">Produkty dodatkowe (z listy)</div>
                <div class="col-6 text-end"><?= number_format($produktyTotal, 2, ',', ' ') ?> zł</div>
              <?php endif; ?>
              <div class="col-6 text-muted small">Netto przed rabatem</div>
              <div class="col-6 text-end"><?= number_format($nettoPrzedRabatem, 2, ',', ' ') ?> zł</div>
              <div class="col-6 text-muted small">Rabat</div>
              <div class="col-6 text-end"><?= number_format($rabatValue, 2, ',', ' ') ?> %</div>
              <div class="col-6 text-muted small">Razem po rabacie</div>
              <div class="col-6 text-end"><?= number_format($razemPoRabacieValue, 2, ',', ' ') ?> zł</div>
              <div class="col-6 text-muted small fw-semibold">Razem brutto</div>
              <div class="col-6 text-end fw-semibold"><?= number_format($razemBruttoValue, 2, ',', ' ') ?> zł</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <?php if ($source === 'kampanie'): ?>
    <?php
      $stateRealization = (string)($campaignWorkflow['realization_status'] ?? normalizeCampaignRealizationStatus($realizationStatus));
      $stateCampaignStatus = (string)($campaignWorkflow['campaign_status'] ?? normalizeCampaignStatus((string)($k['status'] ?? '')));
      $stateBriefDelivered = !empty($campaignWorkflow['brief_delivered']);
      $stateHasFinalAudio = !empty($campaignWorkflow['has_final_audio']);
      $stateMediaplanOk = !empty($campaignWorkflow['mediaplan_ok']);
      $stateCanStartProduction = !empty($campaignWorkflow['can_start_production']);
      $stateProductionError = trim((string)($campaignWorkflow['production_guard_error'] ?? ''));
      $stateCanGoEmission = !empty($campaignWorkflow['can_go_emission']);
      $stateEmissionError = trim((string)($campaignWorkflow['emission_guard_error'] ?? ''));
      $stateNextAction = $campaignWorkflow['next_action'] ?? ['label' => 'Monitoruj realizację kampanii', 'hint' => ''];
      $stateBriefDispatchCount = (int)($campaignWorkflow['brief_dispatch_count'] ?? 0);
      $stateDaysInProduction = $campaignWorkflow['days_in_production'] ?? null;
      $stateDaysAudioWaiting = $campaignWorkflow['days_audio_waiting'] ?? null;
      $stateBriefOperationalLabel = (string)($briefOperationalState['label'] ?? 'Brief wymaga sprawdzenia');
      $stateBriefOperationalBadge = (string)($briefOperationalState['badge_class'] ?? 'badge-neutral');
      $stateCanSendBrief = !empty($briefOperationalState['can_send_brief']);
      $stateSendBriefReason = trim((string)($briefOperationalState['send_block_reason'] ?? ''));
    ?>
    <div class="card mt-3">
      <div class="card-body">
        <div class="row g-3">
          <div class="col-lg-5">
            <h5 class="card-title mb-2">Stan kampanii</h5>
            <div class="small text-muted mb-2">Główny etap: <strong><?= htmlspecialchars(campaignRealizationStatusLabel($stateRealization)) ?></strong></div>
            <div class="small text-muted mb-2">Status biznesowy: <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $stateCampaignStatus))) ?></strong></div>
            <div class="small text-muted mb-1">Brief operacyjnie: <strong><?= htmlspecialchars($stateBriefOperationalLabel) ?></strong></div>
            <div class="small text-muted mb-1">Brief: <strong><?= $stateBriefDelivered ? 'dostarczony' : 'oczekuje' ?></strong></div>
            <div class="small text-muted mb-1">Audio finalne: <strong><?= $stateHasFinalAudio ? 'tak' : 'nie' ?></strong></div>
            <div class="small text-muted mb-1">Mediaplan: <strong><?= $stateMediaplanOk ? 'OK' : 'wymaga poprawy' ?></strong></div>
            <?php if ($stateDaysInProduction !== null): ?>
              <div class="small text-muted mb-1">Produkcja trwa: <strong><?= (int)$stateDaysInProduction ?> dni</strong></div>
            <?php endif; ?>
            <?php if ($stateDaysAudioWaiting !== null): ?>
              <div class="small text-muted">Audio bez odpowiedzi: <strong><?= (int)$stateDaysAudioWaiting ?> dni</strong></div>
            <?php endif; ?>
          </div>
          <div class="col-lg-4">
            <h5 class="card-title mb-2">Co dalej</h5>
            <div class="border rounded p-3 h-100">
              <div class="fw-semibold"><?= htmlspecialchars((string)($stateNextAction['label'] ?? 'Monitoruj realizację kampanii')) ?></div>
              <div class="small text-muted mt-1"><?= htmlspecialchars((string)($stateNextAction['hint'] ?? '')) ?></div>
              <?php if ($stateProductionError !== ''): ?>
                <div class="alert alert-warning py-2 px-2 small mt-2 mb-0"><?= htmlspecialchars($stateProductionError) ?></div>
              <?php endif; ?>
              <?php if ($stateEmissionError !== ''): ?>
                <div class="alert alert-warning py-2 px-2 small mt-2 mb-0"><?= htmlspecialchars($stateEmissionError) ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="col-lg-3">
            <h5 class="card-title mb-2">Quick actions</h5>
            <div class="d-grid gap-2">
              <?php if ($stateCanSendBrief): ?>
                <form method="post">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                  <input type="hidden" name="brief_action" value="send_brief_link">
                  <?php if ($stateBriefDispatchCount > 0): ?>
                    <input type="hidden" name="brief_force_resend" value="1">
                  <?php endif; ?>
                  <button class="btn btn-sm btn-outline-primary w-100" type="submit"><?= $stateBriefDispatchCount > 0 ? 'Wyślij brief ponownie' : 'Wyślij brief' ?></button>
                </form>
              <?php else: ?>
                <button class="btn btn-sm btn-outline-primary w-100" type="button" disabled>Wyślij brief</button>
                <?php if ($stateSendBriefReason !== ''): ?>
                  <div class="small text-muted px-1"><?= htmlspecialchars($stateSendBriefReason) ?></div>
                <?php endif; ?>
              <?php endif; ?>

              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="brief_action" value="quick_start_production">
                <button class="btn btn-sm btn-outline-secondary w-100" type="submit" <?= $stateCanStartProduction ? '' : 'disabled' ?>>Rozpocznij produkcję</button>
              </form>
              <?php if (!$stateCanStartProduction && $stateProductionError !== ''): ?>
                <div class="small text-muted px-1"><?= htmlspecialchars($stateProductionError) ?></div>
              <?php endif; ?>

              <?php if ($quickActionSpotId > 0): ?>
                <a class="btn btn-sm btn-outline-secondary w-100" href="edytuj_spot.php?id=<?= (int)$quickActionSpotId ?>">Wyślij audio</a>
              <?php else: ?>
                <button class="btn btn-sm btn-outline-secondary w-100" type="button" disabled>Wyślij audio</button>
              <?php endif; ?>

              <form method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="brief_action" value="quick_go_emission">
                <button class="btn btn-sm btn-primary w-100" type="submit" <?= $stateCanGoEmission ? '' : 'disabled' ?>>Przejdź do emisji</button>
              </form>
            </div>
          </div>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($source === 'kampanie'): ?>
    <div class="card mt-3">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <h5 class="card-title mb-1">Brief kampanii</h5>
            <div class="text-muted small">
              <span class="badge <?= htmlspecialchars($stateBriefOperationalBadge) ?>"><?= htmlspecialchars($stateBriefOperationalLabel) ?></span>
              <?php if ($brief): ?>
                | Status briefu: <?= htmlspecialchars(briefStatusLabel((string)($brief['status'] ?? 'draft'))) ?>
                <?php if (!empty($brief['submitted_at'])): ?>
                  | Odesłany: <?= htmlspecialchars((string)$brief['submitted_at']) ?>
                <?php endif; ?>
              <?php else: ?>
                | Brief nie został jeszcze utworzony
              <?php endif; ?>
              <?php if ($realizationStatus !== ''): ?>
                | Realizacja: <?= htmlspecialchars(campaignRealizationStatusLabel($realizationStatus)) ?>
              <?php endif; ?>
            </div>
          </div>
          <div class="d-flex gap-2 flex-wrap justify-content-end">
            <?php if ($brief): ?>
              <a class="btn btn-sm btn-outline-primary" href="<?= htmlspecialchars($briefPublicUrl) ?>" target="_blank">Publiczny brief</a>
              <a class="btn btn-sm btn-outline-secondary" href="brief_pdf.php?campaign_id=<?= (int)$id ?>" target="_blank">PDF briefu</a>
            <?php endif; ?>
            <?php if ($stateCanSendBrief): ?>
              <form method="post" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="brief_action" value="send_brief_link">
                <?php if ($stateBriefDispatchCount > 0): ?>
                  <input type="hidden" name="brief_force_resend" value="1">
                <?php endif; ?>
                <button class="btn btn-sm btn-primary" type="submit"><?= $stateBriefDispatchCount > 0 ? 'Wyślij brief ponownie' : 'Wyślij link briefu' ?></button>
              </form>
            <?php else: ?>
              <button class="btn btn-sm btn-primary" type="button" disabled>Wyślij link briefu</button>
              <?php if ($stateSendBriefReason !== ''): ?>
                <div class="w-100 small text-muted text-end"><?= htmlspecialchars($stateSendBriefReason) ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($brief): ?>
          <div class="row g-3 mt-1">
            <div class="col-lg-6">
              <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-2">Brief do produkcji</div>
                <div class="small"><span class="text-muted">Czas spotu:</span> <?= htmlspecialchars((string)($brief['spot_length_seconds'] ?? '-')) ?> s</div>
                <div class="small"><span class="text-muted">Lektorzy:</span> <?= htmlspecialchars((string)($brief['lector_count'] ?? '-')) ?></div>
                <div class="small mt-2"><span class="text-muted">Grupa docelowa:</span><br><?= nl2br(htmlspecialchars((string)($brief['target_group'] ?? '-'))) ?></div>
                <div class="small mt-2"><span class="text-muted">Najważniejsza informacja:</span><br><?= nl2br(htmlspecialchars((string)($brief['main_message'] ?? '-'))) ?></div>
                <div class="small mt-2"><span class="text-muted">Dane teleadresowe:</span><br><?= nl2br(htmlspecialchars((string)($brief['contact_details'] ?? '-'))) ?></div>
                <div class="small mt-2"><span class="text-muted">Charakter reklamy:</span><br><?= nl2br(htmlspecialchars((string)($brief['tone_style'] ?? '-'))) ?></div>
                <div class="small mt-2"><span class="text-muted">Efekty dźwiękowe:</span><br><?= nl2br(htmlspecialchars((string)($brief['sound_effects'] ?? '-'))) ?></div>
                <div class="small mt-2"><span class="text-muted">Uwagi klienta:</span><br><?= nl2br(htmlspecialchars((string)($brief['notes'] ?? '-'))) ?></div>
              </div>
            </div>
            <div class="col-lg-6">
              <div class="border rounded p-3 h-100">
                <div class="fw-semibold mb-2">Obsługa realizacji</div>
                <div class="alert <?= $stateCanStartProduction ? 'alert-success' : 'alert-warning' ?> py-2 px-3 small">
                  <?= htmlspecialchars($stateCanStartProduction ? 'Brief został odesłany i jest gotowy do rozpoczęcia produkcji.' : ($stateProductionError !== '' ? $stateProductionError : 'Brief nie jest jeszcze gotowy do produkcji.')) ?>
                </div>
                <form method="post" class="row g-3">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                  <input type="hidden" name="brief_action" value="save_production">
                  <div class="col-12">
                    <label class="form-label" for="production_owner_user_id">Osoba odpowiedzialna</label>
                    <select class="form-select" id="production_owner_user_id" name="production_owner_user_id">
                      <option value="">Nie przypisano</option>
                      <?php foreach ($productionUsers as $productionUser): ?>
                        <?php
                          $userLabel = trim(((string)($productionUser['imie'] ?? '')) . ' ' . ((string)($productionUser['nazwisko'] ?? '')));
                          if ($userLabel === '') {
                              $userLabel = (string)($productionUser['login'] ?? ('Użytkownik #' . (int)($productionUser['id'] ?? 0)));
                          }
                        ?>
                        <option value="<?= (int)($productionUser['id'] ?? 0) ?>" <?= (int)($brief['production_owner_user_id'] ?? 0) === (int)($productionUser['id'] ?? 0) ? 'selected' : '' ?>>
                          <?= htmlspecialchars($userLabel) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-12">
                    <label class="form-label" for="production_external_studio">Studio zewnętrzne</label>
                    <input class="form-control" id="production_external_studio" name="production_external_studio" type="text" value="<?= htmlspecialchars((string)($brief['production_external_studio'] ?? '')) ?>">
                  </div>
                  <div class="col-12">
                    <label class="form-label" for="realization_status">Status realizacji</label>
                    <select class="form-select" id="realization_status" name="realization_status">
                      <option value="">Bez zmiany</option>
                      <?php foreach (campaignRealizationStatusDefinitions() as $statusKey => $statusLabel): ?>
                        <option value="<?= htmlspecialchars($statusKey) ?>" <?= $realizationStatus === $statusKey ? 'selected' : '' ?>>
                          <?= htmlspecialchars($statusLabel) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-12 d-flex gap-2 flex-wrap">
                    <button class="btn btn-primary" type="submit">Zapisz realizację</button>
                  </div>
                </form>
                <div class="small text-muted mt-3">
                  Brief dostarczony:
                  <strong><?= campaignBriefDelivered($brief) ? 'TAK' : 'NIE' ?></strong>
                  <?php if (!empty($brief['production_owner_user_id'])): ?>
                    | Opiekun produkcji ID: <strong><?= (int)$brief['production_owner_user_id'] ?></strong>
                  <?php endif; ?>
                </div>
                <div class="small text-muted mt-1">
                  Spoty w kampanii: <strong><?= (int)($campaignAudioSummary['spots_total'] ?? 0) ?></strong>
                  | Spoty z wersjami audio: <strong><?= (int)($campaignAudioSummary['spots_with_any_audio'] ?? 0) ?></strong>
                  | Spoty z finalnym audio: <strong><?= (int)($campaignAudioSummary['spots_with_final'] ?? 0) ?></strong>
                  | Wysylki wersji: <strong><?= (int)($campaignAudioSummary['dispatches_total'] ?? 0) ?></strong>
                </div>
                <?php if ($briefReadonly): ?>
                  <form method="post" class="mt-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                    <input type="hidden" name="brief_action" value="unlock_customer_edit">
                    <button class="btn btn-outline-warning" type="submit">Odblokuj brief dla klienta</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php else: ?>
          <div class="alert alert-info mt-3 mb-0">
            <div class="fw-semibold mb-1"><?= htmlspecialchars((string)($stateNextAction['label'] ?? 'Wyślij brief do klienta')) ?></div>
            <div><?= htmlspecialchars((string)($stateNextAction['hint'] ?? 'Ta kampania nie ma jeszcze briefu. Zacznij od wysłania linku do briefu, aby wejść w etap zbierania danych do produkcji.')) ?></div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="card mt-3">
    <div class="card-body">
      <h5 class="card-title">Plan emisji</h5>
      <?php if ($siatkaTotalSpots === 0): ?>
        <div class="text-muted mb-2">Brak zapisanych emisji dla tej kampanii.</div>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table table-sm table-bordered text-center mb-0">
          <thead class="table-light">
            <tr>
              <th>Godzina</th>
              <?php foreach ($days as $short => $label): ?>
                <th><?= htmlspecialchars($label) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($siatkaHours as $hour): ?>
              <tr>
                <td><strong><?= htmlspecialchars($hour) ?></strong></td>
                <?php foreach (array_keys($days) as $day): ?>
                  <td><?= (int)($siatka[$day][$hour] ?? 0) ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="card mt-3">
    <div class="card-body">
      <h5 class="card-title">Generowanie dokumentów</h5>
      <form method="post" class="row g-3 align-items-end">
        <input type="hidden" name="doc_action" value="generate_aneks">
        <div class="col-md-4">
          <label for="aneks_start_date" class="form-label">Data start aneksu (12 mies.)</label>
          <input type="date" name="aneks_start_date" id="aneks_start_date" class="form-control"
                 value="<?= htmlspecialchars($defaultAneksDate) ?>" required>
        </div>
        <div class="col-md-4">
          <button class="btn btn-primary" type="submit">Generuj aneks terminowy</button>
        </div>
      </form>
    </div>
  </div>

  <?php if ($source === 'kampanie'): ?>
    <div class="card mt-3">
      <div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h5 class="card-title mb-0">Timeline procesu kampanii</h5>
          <a class="btn btn-sm btn-outline-secondary" href="api/process_timeline.php?campaign_id=<?= (int)$id ?>" target="_blank">JSON</a>
        </div>
        <?php if (empty($campaignTimelineEvents)): ?>
          <div class="text-muted">Brak zdarzeń procesu dla tej kampanii.</div>
        <?php else: ?>
          <div class="list-group">
            <?php foreach ($campaignTimelineEvents as $event): ?>
              <?php
                $eventTime = trim((string)($event['czas'] ?? ''));
                $eventType = trim((string)($event['typ'] ?? ''));
                $eventTitle = trim((string)($event['tytul'] ?? ''));
                $eventSource = trim((string)($event['zrodlo'] ?? ''));
                $eventLink = trim((string)($event['link'] ?? ''));
                $eventMeta = $event['meta'] ?? [];
                $eventTimeLabel = $eventTime !== '' ? date('d.m.Y H:i', strtotime($eventTime)) : '-';
                $eventGroup = 'Proces';
                if (str_starts_with($eventType, 'comm.')) {
                    $eventGroup = 'Komunikacja';
                } elseif (str_starts_with($eventType, 'audio.')) {
                    $eventGroup = 'Audio';
                } elseif (str_starts_with($eventType, 'document.')) {
                    $eventGroup = 'Dokumenty';
                } elseif (str_starts_with($eventType, 'transaction.')) {
                    $eventGroup = 'Sprzedaż';
                }
              ?>
              <div class="list-group-item">
                <div class="d-flex flex-wrap justify-content-between gap-2">
                  <div>
                    <div class="fw-semibold"><?= htmlspecialchars($eventTitle !== '' ? $eventTitle : 'Zdarzenie') ?></div>
                    <div class="small text-muted">
                      grupa: <?= htmlspecialchars($eventGroup) ?>
                      | typ: <?= htmlspecialchars($eventType !== '' ? $eventType : '-') ?>
                    </div>
                    <?php if (is_array($eventMeta) && !empty($eventMeta)): ?>
                      <?php
                        $metaSummary = [];
                        if (!empty($eventMeta['direction'])) {
                            $metaSummary[] = 'kierunek: ' . (string)$eventMeta['direction'];
                        }
                        if (!empty($eventMeta['status'])) {
                            $metaSummary[] = 'status: ' . (string)$eventMeta['status'];
                        }
                        if (!empty($eventMeta['recipient'])) {
                            $metaSummary[] = 'odbiorca: ' . (string)$eventMeta['recipient'];
                        }
                      ?>
                      <?php if (!empty($metaSummary)): ?>
                        <div class="small text-muted"><?= htmlspecialchars(implode(' | ', $metaSummary)) ?></div>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                  <div class="text-end">
                    <div class="small text-muted"><?= htmlspecialchars($eventTimeLabel) ?></div>
                    <?php if ($eventLink !== ''): ?>
                      <a class="small" href="<?= htmlspecialchars($eventLink) ?>" target="_blank">Otwórz</a>
                    <?php endif; ?>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="row g-3 mt-2">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title">Archiwum dokumentów</h5>
          <?php if (!$documentsAllowed): ?>
            <p class="text-muted mb-0">Brak dostępu do archiwum dokumentów tej kampanii.</p>
          <?php elseif (empty($documents)): ?>
            <p class="text-muted mb-0">Brak wygenerowanych dokumentów.</p>
          <?php else: ?>
            <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
              <table class="table table-sm table-striped align-middle mb-0">
                <thead class="table-light">
                  <tr>
                    <th>Nazwa dokumentu</th>
                    <th>Data wygenerowania</th>
                    <th class="text-end"></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($documents as $doc): ?>
                    <?php
                      $docName = trim((string)($doc['original_filename'] ?? ''));
                      if ($docName === '') {
                          $docName = trim((string)($doc['doc_number'] ?? 'Dokument'));
                      }
                    ?>
                    <tr>
                      <td><?= htmlspecialchars($docName) ?></td>
                      <td><?= htmlspecialchars($doc['created_at'] ?? '') ?></td>
                      <td class="text-end">
                        <a class="btn btn-sm btn-outline-primary" href="docs/download.php?id=<?= (int)$doc['id'] ?>&inline=1" target="_blank">Podgląd</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title">Realizacja kampanii</h5>
          <div class="mt-3 text-center">
            <canvas id="kampania-timeline" width="150" height="150" aria-label="Wykres czasu kampanii"></canvas>
          </div>
          <div class="mt-3">
            <?php if (!empty($audioWebPath)): ?>
              <p class="mb-1"><strong>Dolaczony plik audio:</strong></p>
              <audio controls style="width:100%;max-width:300px;" src="<?= htmlspecialchars($audioPlaybackUrl) ?>">Twoja przegladarka nie obsluguje audio</audio>
            <?php else: ?>
              <p class="text-muted mb-1">Brak pliku audio</p>
            <?php endif; ?>
            <?php if (!empty($uploadMessage)): ?><div class="mt-2"><small class="text-info"><?= htmlspecialchars($uploadMessage) ?></small></div><?php endif; ?>
            <form class="mt-2" method="post" enctype="multipart/form-data">
              <div class="mb-2">
                <input type="file" name="audio" accept="audio/*" />
              </div>
              <div class="d-flex gap-2">
                <button class="btn btn-sm btn-primary" type="submit">Przypnij plik audio</button>
                <?php if (!empty($audioWebPath)): ?>
                  <button class="btn btn-sm btn-outline-danger" type="submit" name="delete_audio" value="1">Usun audio</button>
                <?php endif; ?>
              </div>
            </form>
            <?php if ($source === 'kampanie' && !empty($audioWebPath) && $missingAudioCount > 0): ?>
              <form class="mt-2" method="post">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                <input type="hidden" name="sync_audio_to_spots" value="1">
                <button class="btn btn-sm btn-success" type="submit">
                  Przypnij to audio do brakujących spotów (<?= (int)$missingAudioCount ?>)
                </button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mt-1">
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title">Sumy pasm</h5>
          <ul class="mb-0">
            <li>Prime: <?= (int)($sumy['prime'] ?? 0) ?></li>
            <li>Standard: <?= (int)($sumy['standard'] ?? 0) ?></li>
            <li>Night: <?= (int)($sumy['night'] ?? 0) ?></li>
          </ul>
        </div>
      </div>
    </div>
    <div class="col-md-6">
      <div class="card h-100">
        <div class="card-body">
          <h5 class="card-title">Produkty dodatkowe</h5>
          <?php if (!empty($produkty) && is_array($produkty)): ?>
            <ul class="mb-0">
            <?php foreach ($produkty as $p): ?>
              <?php if (is_string($p)): ?>
                <li><?= htmlspecialchars($p) ?></li>
              <?php elseif (is_array($p)): ?>
                <?php
                  $nazwa = $p['nazwa'] ?? $p['name'] ?? null;
                  $kwota = $p['kwota'] ?? $p['amount'] ?? null;
                ?>
                <li>
                  <?= $nazwa ? htmlspecialchars($nazwa) : '<em>Produkt</em>' ?>
                  <?php if ($kwota !== null): ?> - <?= number_format((float)$kwota, 2, ',', ' ') ?> zł<?php endif; ?>
                </li>
              <?php else: ?>
                <li><?= htmlspecialchars((string)$p) ?></li>
              <?php endif; ?>
            <?php endforeach; ?>
            </ul>
          <?php else: ?>
            <p class="mb-0 text-muted">Brak</p>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="mt-3">
    <?php if ($source === 'kampanie'): ?>
      <?php if ($canAcceptCampaign): ?>
        <form method="post" action="kampania_akceptuj.php" class="d-inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
          <input type="hidden" name="campaign_id" value="<?= (int)$k['id'] ?>">
          <input type="hidden" name="return_to" value="<?= htmlspecialchars('kampania_podglad.php?id=' . (int)$k['id']) ?>">
          <button type="submit" class="btn btn-success">Akceptuj plan -> klient</button>
        </form>
      <?php elseif ($isAcceptedCampaign && !$needsLeadConversion): ?>
        <button type="button" class="btn btn-outline-success" disabled>Kampania zaakceptowana</button>
      <?php endif; ?>
    <?php endif; ?>
    <a class="btn btn-secondary" href="kampanie_lista.php">Lista kampanii</a>
    <a class="btn btn-outline-secondary" href="kampania_pdf.php?id=<?= (int)$k['id'] ?>" target="_blank">PDF: Mediaplan</a>
    <?php if ($offerSendUrl !== ''): ?>
      <a class="btn btn-primary" href="<?= htmlspecialchars($offerSendUrl) ?>">Wyślij ofertę</a>
    <?php else: ?>
      <button type="button" class="btn btn-primary" disabled title="<?= htmlspecialchars($offerSendDisabledReason) ?>">Wyślij ofertę</button>
    <?php endif; ?>
  </div>
</div>

<script>
(function(){
  var start = <?= json_encode($k['data_start'] ?? '') ?>;
  var end = <?= json_encode($k['data_koniec'] ?? '') ?>;
  var canvas = document.getElementById('kampania-timeline');
  if (!canvas) return;
  var ctx = canvas.getContext('2d');
  var css = getComputedStyle(document.documentElement);
  var textColor = (css.getPropertyValue('--text') || '').trim() || '#0F172A';
  var surfaceColor = (css.getPropertyValue('--surface') || '').trim() || '#FFFFFF';
  var dangerColor = (css.getPropertyValue('--danger') || '').trim() || '#DC2626';
  var successColor = (css.getPropertyValue('--success') || '').trim() || '#16A34A';
  function parseDate(s){
    if (!s) return null;
    var parts = s.split(' ')[0].split('-');
    if (parts.length<3) return null;
    return new Date(parts[0], parts[1]-1, parts[2]);
  }
  var sDate = parseDate(start);
  var eDate = parseDate(end);
  var now = new Date();
  if (!sDate || !eDate) {
    ctx.font='12px Arial'; ctx.textAlign='center'; ctx.textBaseline='middle'; ctx.fillText('Brak dat', 75,75); return;
  }
  var total = eDate - sDate;
  var elapsed = 0;
  if (total <= 0) elapsed = 1;
  else if (now < sDate) elapsed = 0;
  else if (now > eDate) elapsed = 1;
  else elapsed = (now - sDate) / total;
  var elapsedAngle = Math.max(0, Math.min(1, elapsed)) * Math.PI * 2;
  var cx = 75, cy = 75, r = 65;
  ctx.clearRect(0,0,150,150);
  ctx.beginPath(); ctx.moveTo(cx,cy);
  ctx.fillStyle = dangerColor;
  ctx.arc(cx,cy,r, -Math.PI/2, -Math.PI/2 + elapsedAngle, false);
  ctx.closePath(); ctx.fill();
  ctx.beginPath(); ctx.moveTo(cx,cy);
  ctx.fillStyle = successColor;
  ctx.arc(cx,cy,r, -Math.PI/2 + elapsedAngle, -Math.PI/2 + Math.PI*2, false);
  ctx.closePath(); ctx.fill();
  ctx.beginPath(); ctx.fillStyle = surfaceColor; ctx.arc(cx,cy,r*0.55,0,Math.PI*2); ctx.fill();
  ctx.fillStyle = textColor; ctx.font='14px Arial'; ctx.textAlign='center'; ctx.textBaseline='middle';
  var percent = Math.round((1 - elapsed) * 100);
  ctx.fillText(percent + '%', cx, cy);
})();
</script>
<?php include 'includes/footer.php'; ?>
