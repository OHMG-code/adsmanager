<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';
require_once __DIR__ . '/communication_events.php';
require_once __DIR__ . '/communication_templates.php';

function briefStatusDefinitions(): array
{
    return [
        'draft' => 'Szkic',
        'sent' => 'Wysłany',
        'submitted' => 'Odesłany',
        'approved_internal' => 'Zatwierdzony wewnętrznie',
    ];
}

function campaignRealizationStatusDefinitions(): array
{
    return [
        'brief_oczekuje' => 'Brief oczekuje',
        'brief_otrzymany' => 'Brief otrzymany',
        'w_produkcji' => 'W produkcji',
        'wersje_wyslane' => 'Wersje wysłane',
        'zaakceptowany_spot' => 'Zaakceptowany spot',
        'do_emisji' => 'Do emisji',
        'zakonczony' => 'Zakończony',
    ];
}

function normalizeCampaignRealizationStatus(?string $status): string
{
    $raw = trim((string)$status);
    if ($raw === '') {
        return 'brief_oczekuje';
    }

    $norm = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    $norm = strtr($norm, [
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
    $norm = str_replace(['-', '_'], ' ', $norm);
    $norm = preg_replace('/\s+/', ' ', $norm) ?: $norm;

    $map = [
        'brief oczekuje' => 'brief_oczekuje',
        'brief_oczekuje' => 'brief_oczekuje',
        'brief otrzymany' => 'brief_otrzymany',
        'brief_otrzymany' => 'brief_otrzymany',
        'w produkcji' => 'w_produkcji',
        'w_produkcji' => 'w_produkcji',
        'wersje wyslane' => 'wersje_wyslane',
        'wersje_wyslane' => 'wersje_wyslane',
        'zaakceptowany spot' => 'zaakceptowany_spot',
        'zaakceptowany_spot' => 'zaakceptowany_spot',
        'do emisji' => 'do_emisji',
        'do_emisji' => 'do_emisji',
        'zakonczony' => 'zakonczony',
    ];

    return $map[$norm] ?? 'brief_oczekuje';
}

function normalizeCampaignStatus(?string $status): string
{
    $raw = trim((string)$status);
    if ($raw === '') {
        return 'w_realizacji';
    }
    $norm = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    $norm = strtr($norm, [
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
    $norm = str_replace(['-', '_'], ' ', $norm);
    $norm = preg_replace('/\s+/', ' ', $norm) ?: $norm;

    if (in_array($norm, ['propozycja', 'draft'], true)) {
        return 'propozycja';
    }
    if (in_array($norm, ['zamowiona', 'zamowienie', 'zamowiona kampania'], true)) {
        return 'zamowiona';
    }
    if (in_array($norm, ['zakonczona', 'zakonczony', 'zakonczona kampania'], true)) {
        return 'zakonczona';
    }
    return 'w_realizacji';
}

function normalizeAudioStatus(?string $status): string
{
    return normalizeAudioProductionStatus($status);
}

function audioProductionStatusDefinitions(): array
{
    return [
        'robocza' => 'Robocza',
        'wyslana_do_klienta' => 'Wyslana do klienta',
        'zaakceptowana' => 'Zaakceptowana',
        'odrzucona' => 'Odrzucona',
    ];
}

function normalizeAudioProductionStatus(?string $status): string
{
    $raw = trim((string)$status);
    if ($raw === '') {
        return 'robocza';
    }

    $norm = function_exists('mb_strtolower') ? mb_strtolower($raw, 'UTF-8') : strtolower($raw);
    $norm = strtr($norm, [
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
    $norm = str_replace(['-', '_'], ' ', $norm);
    $norm = preg_replace('/\s+/', ' ', $norm) ?: $norm;

    $map = [
        'do akceptacji' => 'robocza',
        'robocza' => 'robocza',
        'draft' => 'robocza',
        'wyslana do klienta' => 'wyslana_do_klienta',
        'wyslano do klienta' => 'wyslana_do_klienta',
        'wyslana_do_klienta' => 'wyslana_do_klienta',
        'dispatched' => 'wyslana_do_klienta',
        'zaakceptowany' => 'zaakceptowana',
        'zaakceptowana' => 'zaakceptowana',
        'accepted' => 'zaakceptowana',
        'odrzucony' => 'odrzucona',
        'odrzucona' => 'odrzucona',
        'rejected' => 'odrzucona',
    ];

    return $map[$norm] ?? 'robocza';
}

function audioProductionStatusDbValue(string $canonicalStatus): string
{
    $canonicalStatus = normalizeAudioProductionStatus($canonicalStatus);
    $map = [
        'robocza' => 'Do akceptacji',
        'wyslana_do_klienta' => 'Wyslana do klienta',
        'zaakceptowana' => 'Zaakceptowany',
        'odrzucona' => 'Odrzucony',
    ];
    return $map[$canonicalStatus] ?? 'Do akceptacji';
}

function audioProductionStatusLabel(?string $status): string
{
    $canonical = normalizeAudioProductionStatus($status);
    $defs = audioProductionStatusDefinitions();
    return $defs[$canonical] ?? $defs['robocza'];
}

function audioProductionStatusIsAccepted(?string $status): bool
{
    return normalizeAudioProductionStatus($status) === 'zaakceptowana';
}

function acceptedAudioStatusSqlValues(): array
{
    return [
        'zaakceptowany',
        'zaakceptowana',
        'accepted',
    ];
}

function campaignMediaplanValidationErrors(PDO $pdo, int $campaignId): array
{
    if (!function_exists('validateCampaignScheduleAvailability')) {
        require_once __DIR__ . '/campaign_schedule_validation.php';
    }

    if ($campaignId <= 0 || !tableExists($pdo, 'kampanie') || !tableExists($pdo, 'kampanie_emisje')) {
        return ['Brak danych kampanii lub mediaplanu do walidacji.'];
    }

    $kampanieCols = getTableColumns($pdo, 'kampanie');
    $lengthExpr = hasColumn($kampanieCols, 'dlugosc_spotu')
        ? 'COALESCE(NULLIF(dlugosc_spotu, 0), 30) AS spot_length_seconds'
        : '30 AS spot_length_seconds';
    $stmtCampaign = $pdo->prepare("SELECT data_start, data_koniec, {$lengthExpr} FROM kampanie WHERE id = :id LIMIT 1");
    $stmtCampaign->execute([':id' => $campaignId]);
    $campaign = $stmtCampaign->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$campaign) {
        return ['Nie znaleziono kampanii do walidacji mediaplanu.'];
    }

    $dataStart = trim((string)($campaign['data_start'] ?? ''));
    $dataEnd = trim((string)($campaign['data_koniec'] ?? ''));
    if ($dataStart === '' || $dataEnd === '') {
        return ['Brak zakresu dat kampanii dla walidacji mediaplanu.'];
    }

    $stmtRows = $pdo->prepare('SELECT dzien_tygodnia, godzina, ilosc FROM kampanie_emisje WHERE kampania_id = :id');
    $stmtRows->execute([':id' => $campaignId]);
    $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
        return ['Brak emisji kampanii do walidacji mediaplanu.'];
    }

    $grid = [];
    foreach ($rows as $row) {
        $day = strtolower(trim((string)($row['dzien_tygodnia'] ?? '')));
        $hourRaw = trim((string)($row['godzina'] ?? ''));
        $qty = (int)($row['ilosc'] ?? 0);
        if ($day === '' || $hourRaw === '' || $qty <= 0) {
            continue;
        }
        $hour = strlen($hourRaw) >= 5 ? substr($hourRaw, 0, 5) : $hourRaw;
        if (!preg_match('/^\d{2}:\d{2}$/', $hour)) {
            continue;
        }
        $grid[$day][$hour] = (int)($grid[$day][$hour] ?? 0) + $qty;
    }

    if (!$grid) {
        return ['Brak poprawnej siatki emisji do walidacji mediaplanu.'];
    }

    $spotLength = max(1, (int)($campaign['spot_length_seconds'] ?? 30));
    return validateCampaignScheduleAvailability($pdo, $grid, $spotLength, $dataStart, $dataEnd);
}

function briefStatusLabel(?string $status): string
{
    $defs = briefStatusDefinitions();
    $status = trim((string)$status);
    return $defs[$status] ?? ($defs['draft']);
}

function campaignRealizationStatusLabel(?string $status): string
{
    $defs = campaignRealizationStatusDefinitions();
    $status = normalizeCampaignRealizationStatus($status);
    return $defs[$status] ?? '';
}

function campaignBriefDelivered(?array $brief): bool
{
    if (!$brief) {
        return false;
    }
    return briefHasProductionReadyStatus((string)($brief['status'] ?? ''));
}

function campaignBriefWasDispatched(?array $brief, int $dispatchCount = 0): bool
{
    $status = trim((string)($brief['status'] ?? ''));
    if (in_array($status, ['sent', 'submitted', 'approved_internal'], true)) {
        return true;
    }

    return $dispatchCount > 0;
}

function briefHasProductionReadyStatus(?string $status): bool
{
    return in_array(trim((string)$status), ['submitted', 'approved_internal'], true);
}

function campaignProductionStartGuardError(?array $brief): ?string
{
    if (!campaignBriefDelivered($brief)) {
        return 'Nie można rozpocząć produkcji bez otrzymanego briefu.';
    }

    return null;
}

function canStartProductionFromBrief(array $campaign, ?array $brief): array
{
    $realizationStatus = normalizeCampaignRealizationStatus((string)($campaign['realization_status'] ?? ''));
    if (in_array($realizationStatus, ['w_produkcji', 'wersje_wyslane'], true)) {
        return [
            'ok' => false,
            'reason' => 'Produkcja została już rozpoczęta dla tej kampanii.',
        ];
    }
    if (in_array($realizationStatus, ['zaakceptowany_spot', 'do_emisji', 'zakonczony'], true)) {
        return [
            'ok' => false,
            'reason' => 'Ta kampania jest już po etapie rozpoczęcia produkcji.',
        ];
    }

    $guard = campaignProductionStartGuardError($brief);
    return [
        'ok' => $guard === null,
        'reason' => $guard,
    ];
}

function briefOperationalStateDefinitions(): array
{
    return [
        'requires_attention' => [
            'label' => 'Wymagają uwagi',
            'description' => 'Status kampanii i stan briefu nie są dziś spójne i wymagają decyzji operacyjnej.',
            'badge_class' => 'badge-danger',
        ],
        'do_wyslania' => [
            'label' => 'Do wysłania',
            'description' => 'Brief nie został jeszcze wysłany do klienta.',
            'badge_class' => 'badge-warning',
        ],
        'oczekuje_na_brief' => [
            'label' => 'Czekają na brief',
            'description' => 'Link był już wysłany, ale klient jeszcze nie odesłał briefu.',
            'badge_class' => 'badge-neutral',
        ],
        'gotowe_do_produkcji' => [
            'label' => 'Gotowe do produkcji',
            'description' => 'Brief wrócił i można rozpocząć etap produkcji.',
            'badge_class' => 'badge-success',
        ],
        'w_produkcji' => [
            'label' => 'W produkcji',
            'description' => 'Brief jest już w pracy produkcyjnej albo czeka na reakcję do audio.',
            'badge_class' => 'badge-neutral',
        ],
        'zamkniete' => [
            'label' => 'Archiwalne / dalej w workflow',
            'description' => 'Brief przeszedł już dalej do emisji albo kampania została zamknięta.',
            'badge_class' => 'badge-neutral',
        ],
    ];
}

function getBriefNextAction(array $campaign, ?array $brief, array $context = []): array
{
    $realizationStatus = normalizeCampaignRealizationStatus((string)($campaign['realization_status'] ?? ''));
    $briefDispatchCount = max(0, (int)($context['brief_dispatch_count'] ?? 0));
    $clientEmail = trim((string)($context['client_email'] ?? ''));
    $briefDelivered = campaignBriefDelivered($brief);
    $briefDispatched = campaignBriefWasDispatched($brief, $briefDispatchCount);
    $startCheck = canStartProductionFromBrief($campaign, $brief);

    if (!$briefDelivered && in_array($realizationStatus, ['w_produkcji', 'wersje_wyslane', 'zaakceptowany_spot', 'do_emisji', 'zakonczony'], true)) {
        return [
            'label' => 'Sprawdź spójność briefu',
            'hint' => 'Status kampanii jest dalej niż stan briefu. Zweryfikuj, czy brief został naprawdę odebrany i poprawnie zapisany.',
        ];
    }

    if (in_array($realizationStatus, ['zaakceptowany_spot', 'do_emisji', 'zakonczony'], true)) {
        return [
            'label' => 'Workflow briefu został zamknięty',
            'hint' => 'Ten brief jest już po etapie produkcji i nie wymaga bieżącej akcji w module Briefy.',
        ];
    }

    if (in_array($realizationStatus, ['w_produkcji', 'wersje_wyslane'], true)) {
        $hint = $realizationStatus === 'wersje_wyslane'
            ? 'Audio zostało wysłane. Monitoruj odpowiedź klienta i kolejne wersje.'
            : 'Produkcja została uruchomiona. Otwórz kampanię i prowadź dalsze prace nad spotem.';
        return [
            'label' => 'Kontynuuj produkcję',
            'hint' => $hint,
        ];
    }

    if ($startCheck['ok']) {
        return [
            'label' => 'Rozpocznij produkcję',
            'hint' => 'Brief został odesłany i jest gotowy do rozpoczęcia produkcji.',
        ];
    }

    if (!$briefDispatched) {
        $hint = $clientEmail === ''
            ? 'Najpierw uzupełnij poprawny adres e-mail klienta, a potem wyślij brief.'
            : 'Przygotuj i wyślij klientowi link do briefu, żeby rozpocząć ten etap workflow.';
        return [
            'label' => 'Wyślij brief do klienta',
            'hint' => $hint,
        ];
    }

    return [
        'label' => 'Poczekaj na odesłanie briefu',
        'hint' => 'Link został już wysłany. Jeśli klient nie odpowiada, możesz ponowić wysyłkę.',
    ];
}

function resolveBriefOperationalState(array $campaign, ?array $brief, array $context = []): array
{
    $definitions = briefOperationalStateDefinitions();
    $realizationStatus = normalizeCampaignRealizationStatus((string)($campaign['realization_status'] ?? ''));
    $campaignStatus = normalizeCampaignStatus((string)($campaign['status'] ?? ''));
    $briefDispatchCount = max(0, (int)($context['brief_dispatch_count'] ?? 0));
    $clientEmail = trim((string)($context['client_email'] ?? ''));
    $briefDelivered = campaignBriefDelivered($brief);
    $briefDispatched = campaignBriefWasDispatched($brief, $briefDispatchCount);
    $startCheck = canStartProductionFromBrief($campaign, $brief);
    $requiresAttention = !$briefDelivered && in_array($realizationStatus, ['w_produkcji', 'wersje_wyslane', 'zaakceptowany_spot', 'do_emisji', 'zakonczony'], true);

    $stateKey = 'oczekuje_na_brief';
    if ($requiresAttention) {
        $stateKey = 'requires_attention';
    } elseif (in_array($realizationStatus, ['zaakceptowany_spot', 'do_emisji', 'zakonczony'], true) || $campaignStatus === 'zakonczona') {
        $stateKey = 'zamkniete';
    } elseif (in_array($realizationStatus, ['w_produkcji', 'wersje_wyslane'], true)) {
        $stateKey = 'w_produkcji';
    } elseif ($startCheck['ok']) {
        $stateKey = 'gotowe_do_produkcji';
    } elseif (!$briefDispatched) {
        $stateKey = 'do_wyslania';
    }

    $definition = $definitions[$stateKey] ?? $definitions['requires_attention'];
    $sendBlockReason = '';
    if ($clientEmail === '') {
        $sendBlockReason = 'Brak poprawnego adresu e-mail klienta.';
    } elseif ($stateKey === 'zamkniete') {
        $sendBlockReason = 'Ta kampania jest już dalej niż etap briefu.';
    }

    return [
        'key' => $stateKey,
        'label' => (string)($definition['label'] ?? ''),
        'description' => (string)($definition['description'] ?? ''),
        'badge_class' => (string)($definition['badge_class'] ?? 'badge-neutral'),
        'brief_delivered' => $briefDelivered,
        'brief_dispatched' => $briefDispatched,
        'can_start_production' => (bool)$startCheck['ok'],
        'production_block_reason' => trim((string)($startCheck['reason'] ?? '')),
        'can_send_brief' => $sendBlockReason === '',
        'send_block_reason' => $sendBlockReason,
        'next_action' => getBriefNextAction($campaign, $brief, array_merge($context, [
            'brief_dispatch_count' => $briefDispatchCount,
        ])),
        'requires_attention' => $requiresAttention,
    ];
}

function campaignLatestEventAt(PDO $pdo, int $campaignId, string $eventType): ?string
{
    if ($campaignId <= 0 || $eventType === '' || !tableExists($pdo, 'communication_events')) {
        return null;
    }
    try {
        $stmt = $pdo->prepare("SELECT created_at
            FROM communication_events
            WHERE campaign_id = :campaign_id
              AND event_type = :event_type
              AND status IN ('logged','sent')
            ORDER BY created_at DESC
            LIMIT 1");
        $stmt->execute([
            ':campaign_id' => $campaignId,
            ':event_type' => $eventType,
        ]);
        $value = trim((string)($stmt->fetchColumn() ?: ''));
        return $value !== '' ? $value : null;
    } catch (Throwable $e) {
        error_log('briefs: campaignLatestEventAt failed: ' . $e->getMessage());
        return null;
    }
}

function campaignDaysSinceDate(?string $dateValue): ?int
{
    $dateValue = trim((string)$dateValue);
    if ($dateValue === '') {
        return null;
    }
    try {
        $from = new DateTimeImmutable($dateValue);
        $to = new DateTimeImmutable('now');
        return max(0, (int)$from->diff($to)->format('%a'));
    } catch (Throwable $e) {
        return null;
    }
}

function buildCampaignWorkflowState(PDO $pdo, int $campaignId, array $campaign, ?array $brief, array $audioSummary = []): array
{
    $realizationStatus = normalizeCampaignRealizationStatus((string)($campaign['realization_status'] ?? ''));
    $campaignStatus = normalizeCampaignStatus((string)($campaign['status'] ?? ''));
    $briefDelivered = campaignBriefDelivered($brief);
    $hasFinalAudio = campaignHasAcceptedFinalAudio($pdo, $campaignId);

    $productionCheck = canStartProductionFromBrief($campaign, $brief);
    $productionGuard = trim((string)($productionCheck['reason'] ?? ''));
    $canStartProduction = (bool)($productionCheck['ok'] ?? false);
    $emissionGuard = validateCampaignRealizationStatusChange($pdo, $campaignId, 'do_emisji');
    $canGoEmission = $emissionGuard === null;
    $mediaplanErrors = campaignMediaplanValidationErrors($pdo, $campaignId);
    $mediaplanOk = !$mediaplanErrors;

    $briefSentAt = campaignLatestEventAt($pdo, $campaignId, 'brief_link_sent');
    $productionStartedAt = campaignLatestEventAt($pdo, $campaignId, 'production_started');
    $audioDispatchedAt = campaignLatestEventAt($pdo, $campaignId, 'audio_dispatched');
    $daysInProduction = campaignDaysSinceDate($productionStartedAt);
    $daysAudioWaiting = campaignDaysSinceDate($audioDispatchedAt);
    $campaignCreatedAt = trim((string)($campaign['created_at'] ?? ''));
    if ($daysInProduction === null && $realizationStatus === 'w_produkcji') {
        $daysInProduction = campaignDaysSinceDate($campaignCreatedAt);
    }
    if ($daysAudioWaiting === null && $realizationStatus === 'wersje_wyslane') {
        $daysAudioWaiting = campaignDaysSinceDate($campaignCreatedAt);
    }
    $briefDispatchCount = 0;
    if (tableExists($pdo, 'communication_events')) {
        try {
            $stmtCount = $pdo->prepare("SELECT COUNT(*)
                FROM communication_events
                WHERE campaign_id = :campaign_id
                  AND event_type = 'brief_link_sent'");
            $stmtCount->execute([':campaign_id' => $campaignId]);
            $briefDispatchCount = (int)($stmtCount->fetchColumn() ?? 0);
        } catch (Throwable $e) {
            error_log('briefs: brief dispatch count failed: ' . $e->getMessage());
        }
    }

    $nextAction = [
        'key' => 'monitoruj_realizacje',
        'label' => 'Monitoruj realizację kampanii',
        'hint' => 'Kampania jest w toku. Sprawdź timeline i aktualny etap realizacji.',
    ];
    if ($briefDispatchCount === 0 && !$briefDelivered) {
        $nextAction = [
            'key' => 'wyslij_brief',
            'label' => 'Wyślij link do briefu',
            'hint' => 'Klient nie odesłał jeszcze briefu. Zacznij od pierwszej wysyłki.',
        ];
    } elseif (!$briefDelivered) {
        $nextAction = [
            'key' => 'ponow_brief',
            'label' => 'Ponów wysyłkę briefu',
            'hint' => 'Link był już wysyłany, ale brief nadal nie wrócił.',
        ];
    } elseif ($realizationStatus === 'brief_otrzymany' || $realizationStatus === 'brief_oczekuje') {
        $nextAction = [
            'key' => 'start_produkcji',
            'label' => 'Rozpocznij produkcję',
            'hint' => 'Brief jest gotowy, można przejść do etapu w_produkcji.',
        ];
    } elseif (!$hasFinalAudio) {
        $nextAction = [
            'key' => 'wyslij_audio',
            'label' => 'Wyślij wersje audio do klienta',
            'hint' => 'Brakuje finalnej zaakceptowanej wersji audio.',
        ];
    } elseif (!$canGoEmission) {
        $nextAction = [
            'key' => 'uzupelnij_guardy_emisji',
            'label' => 'Uzupełnij warunki wejścia do emisji',
            'hint' => $emissionGuard ?: 'Sprawdź status realizacji i mediaplan.',
        ];
    } elseif ($realizationStatus !== 'do_emisji') {
        $nextAction = [
            'key' => 'przejdz_do_emisji',
            'label' => 'Przejdź do emisji',
            'hint' => 'Finalne audio i walidacja mediaplanu są gotowe.',
        ];
    }

    return [
        'realization_status' => $realizationStatus,
        'campaign_status' => $campaignStatus,
        'brief_delivered' => $briefDelivered,
        'brief_dispatch_count' => $briefDispatchCount,
        'brief_sent_at' => $briefSentAt,
        'has_final_audio' => $hasFinalAudio,
        'spots_total' => (int)($audioSummary['spots_total'] ?? 0),
        'spots_with_any_audio' => (int)($audioSummary['spots_with_any_audio'] ?? 0),
        'spots_with_final' => (int)($audioSummary['spots_with_final'] ?? 0),
        'dispatches_total' => (int)($audioSummary['dispatches_total'] ?? 0),
        'can_start_production' => $canStartProduction,
        'production_guard_error' => $productionGuard,
        'can_go_emission' => $canGoEmission,
        'emission_guard_error' => $emissionGuard,
        'mediaplan_ok' => $mediaplanOk,
        'mediaplan_errors' => $mediaplanErrors,
        'production_started_at' => $productionStartedAt,
        'audio_dispatched_at' => $audioDispatchedAt,
        'days_in_production' => $daysInProduction,
        'days_audio_waiting' => $daysAudioWaiting,
        'next_action' => $nextAction,
    ];
}

function generateBriefToken(PDO $pdo): string
{
    ensureLeadBriefsTable($pdo);
    do {
        $token = bin2hex(random_bytes(32));
        $stmt = $pdo->prepare('SELECT id FROM lead_briefs WHERE token = :token LIMIT 1');
        $stmt->execute([':token' => $token]);
        $exists = (bool)$stmt->fetchColumn();
    } while ($exists);

    return $token;
}

function getBriefByCampaignId(PDO $pdo, int $campaignId, bool $forUpdate = false): ?array
{
    ensureLeadBriefsTable($pdo);
    if ($campaignId <= 0) {
        return null;
    }

    $sql = 'SELECT * FROM lead_briefs WHERE campaign_id = :campaign_id LIMIT 1';
    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':campaign_id' => $campaignId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function getBriefByToken(PDO $pdo, string $token): ?array
{
    ensureLeadBriefsTable($pdo);
    $token = trim($token);
    if ($token === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM lead_briefs WHERE token = :token LIMIT 1');
    $stmt->execute([':token' => $token]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function findOrCreateBriefForCampaign(PDO $pdo, int $campaignId, ?int $leadId = null): array
{
    ensureLeadBriefsTable($pdo);
    ensureKampanieOwnershipColumns($pdo);

    if ($campaignId <= 0) {
        throw new InvalidArgumentException('Nieprawidłowa kampania dla briefu.');
    }

    $brief = getBriefByCampaignId($pdo, $campaignId, true);
    if ($brief) {
        if ($leadId !== null && $leadId > 0 && (int)($brief['lead_id'] ?? 0) <= 0) {
            $stmt = $pdo->prepare('UPDATE lead_briefs SET lead_id = :lead_id WHERE id = :id');
            $stmt->execute([
                ':lead_id' => $leadId,
                ':id' => (int)$brief['id'],
            ]);
            $brief['lead_id'] = $leadId;
        }
        return $brief;
    }

    $token = generateBriefToken($pdo);
    $stmt = $pdo->prepare("INSERT INTO lead_briefs
        (campaign_id, lead_id, token, status, is_customer_editable)
        VALUES (:campaign_id, :lead_id, :token, 'draft', 1)");
    $stmt->execute([
        ':campaign_id' => $campaignId,
        ':lead_id' => ($leadId !== null && $leadId > 0) ? $leadId : null,
        ':token' => $token,
    ]);

    $briefId = (int)$pdo->lastInsertId();
    $created = getBriefByCampaignId($pdo, $campaignId, true);
    if (!$created || (int)($created['id'] ?? 0) !== $briefId) {
        throw new RuntimeException('Nie udało się utworzyć briefu kampanii.');
    }
    return $created;
}

function briefIsSubmitted(array $brief): bool
{
    return briefHasProductionReadyStatus((string)($brief['status'] ?? ''));
}

function briefIsCustomerEditable(array $brief): bool
{
    return (int)($brief['is_customer_editable'] ?? 0) === 1;
}

function setBriefCustomerEditable(PDO $pdo, int $briefId, bool $editable): void
{
    ensureLeadBriefsTable($pdo);
    $stmt = $pdo->prepare('UPDATE lead_briefs SET is_customer_editable = :editable WHERE id = :id');
    $stmt->execute([
        ':editable' => $editable ? 1 : 0,
        ':id' => $briefId,
    ]);
}

function savePublicBriefSubmission(PDO $pdo, int $briefId, array $payload): void
{
    ensureLeadBriefsTable($pdo);

    $stmt = $pdo->prepare("UPDATE lead_briefs SET
        spot_length_seconds = :spot_length_seconds,
        lector_count = :lector_count,
        target_group = :target_group,
        main_message = :main_message,
        additional_info = :additional_info,
        contact_details = :contact_details,
        tone_style = :tone_style,
        sound_effects = :sound_effects,
        notes = :notes,
        status = 'submitted',
        is_customer_editable = 0,
        submitted_at = NOW()
        WHERE id = :id");
    $stmt->execute([
        ':spot_length_seconds' => $payload['spot_length_seconds'],
        ':lector_count' => $payload['lector_count'],
        ':target_group' => $payload['target_group'],
        ':main_message' => $payload['main_message'],
        ':additional_info' => $payload['additional_info'],
        ':contact_details' => $payload['contact_details'],
        ':tone_style' => $payload['tone_style'],
        ':sound_effects' => $payload['sound_effects'],
        ':notes' => $payload['notes'],
        ':id' => $briefId,
    ]);
}

function updateBriefProductionData(PDO $pdo, int $briefId, ?int $ownerUserId, ?string $externalStudio): void
{
    ensureLeadBriefsTable($pdo);
    $stmt = $pdo->prepare("UPDATE lead_briefs
        SET production_owner_user_id = :owner_id,
            production_external_studio = :studio
        WHERE id = :id");
    $stmt->execute([
        ':owner_id' => $ownerUserId && $ownerUserId > 0 ? $ownerUserId : null,
        ':studio' => $externalStudio !== null && trim($externalStudio) !== '' ? trim($externalStudio) : null,
        ':id' => $briefId,
    ]);
}

function campaignHasAcceptedFinalAudio(PDO $pdo, int $campaignId): bool
{
    ensureSpotAudioFilesTable($pdo);
    ensureSpotColumns($pdo);
    if ($campaignId <= 0 || !tableExists($pdo, 'spoty')) {
        return false;
    }

    $acceptedValues = acceptedAudioStatusSqlValues();
    $in = implode(',', array_fill(0, count($acceptedValues), '?'));
    $sql = "SELECT 1
        FROM spoty s
        INNER JOIN spot_audio_files saf ON saf.spot_id = s.id
        WHERE s.kampania_id = ?
          AND saf.is_final = 1
          AND LOWER(TRIM(COALESCE(saf.production_status, ''))) IN ($in)
        LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $params = array_merge([$campaignId], $acceptedValues);
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

function validateCampaignRealizationStatusChange(PDO $pdo, int $campaignId, string $targetStatus): ?string
{
    $targetStatus = trim($targetStatus);
    if ($targetStatus === '') {
        return 'Brak docelowego statusu realizacji.';
    }

    $stmtCurrent = $pdo->prepare('SELECT realization_status FROM kampanie WHERE id = :id LIMIT 1');
    $stmtCurrent->execute([':id' => $campaignId]);
    $currentStatus = trim((string)($stmtCurrent->fetchColumn() ?: ''));
    if ($currentStatus === $targetStatus) {
        return null;
    }

    $brief = getBriefByCampaignId($pdo, $campaignId);
    if ($targetStatus === 'w_produkcji') {
        $productionGuard = canStartProductionFromBrief([
            'realization_status' => $currentStatus,
        ], $brief);
        $productionGuardReason = trim((string)($productionGuard['reason'] ?? ''));
        if (!$productionGuard['ok'] && $productionGuardReason !== '') {
            return $productionGuardReason;
        }
    }

    if ($targetStatus === 'do_emisji') {
        if ($currentStatus !== 'zaakceptowany_spot') {
            return 'Nie mozna przejsc do emisji przed etapem: Zaakceptowany spot.';
        }

        if (!campaignHasAcceptedFinalAudio($pdo, $campaignId)) {
            return 'Nie można przejść do emisji bez zaakceptowanego finalnego audio.';
        }

        $scheduleErrors = campaignMediaplanValidationErrors($pdo, $campaignId);
        if ($scheduleErrors) {
            return 'Nie mozna przejsc do emisji. Konflikty mediaplanu: ' . implode('; ', $scheduleErrors);
        }
    }

    return null;
}

function markBriefAsSent(PDO $pdo, int $briefId): void
{
    ensureLeadBriefsTable($pdo);
    if ($briefId <= 0) {
        return;
    }

    $stmt = $pdo->prepare("UPDATE lead_briefs
        SET status = 'sent'
        WHERE id = :id
          AND status = 'draft'");
    $stmt->execute([':id' => $briefId]);
}

function sendCampaignBriefLink(PDO $pdo, array $currentUser, array $campaign, array $brief, string $clientEmail, bool $forceResend = false): array
{
    require_once __DIR__ . '/documents.php';
    require_once __DIR__ . '/mail_service.php';
    require_once __DIR__ . '/mailbox_service.php';
    require_once __DIR__ . '/crm_activity.php';

    $campaignId = (int)($campaign['id'] ?? $brief['campaign_id'] ?? 0);
    $briefId = (int)($brief['id'] ?? 0);
    $briefToken = trim((string)($brief['token'] ?? ''));
    $clientEmail = trim($clientEmail);
    if ($campaignId <= 0 || $briefId <= 0 || $briefToken === '') {
        throw new RuntimeException('Brak aktywnego briefu dla tej kampanii.');
    }
    if ($clientEmail === '' || !filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Brak poprawnego adresu e-mail klienta.');
    }

    ensureCrmMailTables($pdo);
    $briefPublicUrl = BASE_URL . '/brief/' . urlencode($briefToken);
    $systemCfg = fetchSystemConfigSafe($pdo);
    $smtpConfig = resolveSmtpConfig($systemCfg, $currentUser);
    if (trim((string)($smtpConfig['from_email'] ?? '')) === '') {
        $smtpConfig['from_email'] = trim((string)($currentUser['email'] ?? ''));
    }
    if (trim((string)($smtpConfig['from_name'] ?? '')) === '') {
        $senderLabel = trim((string)($currentUser['imie'] ?? '') . ' ' . (string)($currentUser['nazwisko'] ?? ''));
        $smtpConfig['from_name'] = $senderLabel !== '' ? $senderLabel : (string)($currentUser['login'] ?? 'CRM');
    }
    if (trim((string)($smtpConfig['host'] ?? '')) === '' || trim((string)($smtpConfig['from_email'] ?? '')) === '') {
        throw new RuntimeException('Brak kompletnej konfiguracji SMTP do wysyłki linku briefu.');
    }

    $template = communicationTemplateBriefLink([
        'campaign_id' => $campaignId,
        'client_name' => (string)($campaign['klient_nazwa'] ?? ''),
        'brief_url' => $briefPublicUrl,
        'sender_label' => (string)($smtpConfig['from_name'] ?? ''),
    ]);

    ensureCommunicationEventsTable($pdo);
    $recipientHash = substr(hash('sha256', strtolower($clientEmail)), 0, 16);
    $baseKey = communicationBuildIdempotencyKey('brief_link_sent', [
        $campaignId,
        $briefToken,
        $recipientHash,
    ]);
    $idempotencyKey = $baseKey;
    if ($forceResend) {
        $stmtCountResend = $pdo->prepare("SELECT COUNT(*)
            FROM communication_events
            WHERE event_type = 'brief_link_sent'
              AND campaign_id = :campaign_id");
        $stmtCountResend->execute([':campaign_id' => $campaignId]);
        $resendNo = (int)($stmtCountResend->fetchColumn() ?? 0) + 1;
        $idempotencyKey = communicationBuildIdempotencyKey('brief_link_sent', [
            $campaignId,
            $briefToken,
            $recipientHash,
            'resend_' . $resendNo,
        ]);
    }

    $commResult = communicationLogEvent($pdo, [
        'event_type' => 'brief_link_sent',
        'idempotency_key' => $idempotencyKey,
        'direction' => 'outbound_client',
        'status' => 'logged',
        'recipient' => $clientEmail,
        'subject' => (string)($template['subject'] ?? ''),
        'body' => (string)($template['body'] ?? ''),
        'meta_json' => [
            'brief_token' => $briefToken,
            'brief_url' => $briefPublicUrl,
            'force_resend' => $forceResend,
        ],
        'lead_id' => (int)($campaign['source_lead_id'] ?? 0) > 0 ? (int)$campaign['source_lead_id'] : null,
        'client_id' => (int)($campaign['klient_id'] ?? 0) > 0 ? (int)$campaign['klient_id'] : null,
        'campaign_id' => $campaignId,
        'brief_id' => $briefId,
        'created_by_user_id' => (int)($currentUser['id'] ?? 0) > 0 ? (int)$currentUser['id'] : null,
    ]);

    if (!empty($commResult['duplicate']) && !$forceResend) {
        markBriefAsSent($pdo, $briefId);
        return [
            'status' => 'duplicate',
            'message' => 'Link briefu był już wysłany. Użyj opcji "Wyślij ponownie", jeśli to celowe.',
            'brief_url' => $briefPublicUrl,
        ];
    }

    $entityType = (int)($campaign['klient_id'] ?? 0) > 0 ? 'client' : ((int)($campaign['source_lead_id'] ?? 0) > 0 ? 'lead' : null);
    $entityId = (int)($campaign['klient_id'] ?? 0) > 0 ? (int)$campaign['klient_id'] : ((int)($campaign['source_lead_id'] ?? 0) > 0 ? (int)$campaign['source_lead_id'] : null);
    $mailAccount = mailboxEnsureMailAccount($pdo, (int)($currentUser['id'] ?? 0));
    $mailAccountId = $mailAccount ? (int)($mailAccount['id'] ?? 0) : 0;
    $sentAt = date('Y-m-d H:i:s');
    $messageId = mailboxGenerateMessageId('brief-link:' . $campaignId . ':' . microtime(true), (string)$smtpConfig['from_email']);
    $threadId = mailboxFindOrCreateThread($pdo, $entityType, $entityId, (string)($template['subject'] ?? ''), $sentAt);

    $mailMessageId = mailboxInsertMessage($pdo, [
        'client_id' => (int)($campaign['klient_id'] ?? 0) > 0 ? (int)$campaign['klient_id'] : null,
        'lead_id' => (int)($campaign['source_lead_id'] ?? 0) > 0 ? (int)$campaign['source_lead_id'] : null,
        'campaign_id' => $campaignId,
        'owner_user_id' => (int)($currentUser['id'] ?? 0),
        'mail_account_id' => $mailAccountId,
        'thread_id' => $threadId,
        'direction' => 'out',
        'from_email' => (string)$smtpConfig['from_email'],
        'from_name' => (string)($smtpConfig['from_name'] ?? ''),
        'to_email' => $clientEmail,
        'to_emails' => $clientEmail,
        'subject' => (string)($template['subject'] ?? ''),
        'body_html' => nl2br(htmlspecialchars((string)($template['body'] ?? ''), ENT_QUOTES, 'UTF-8')),
        'body_text' => (string)($template['body'] ?? ''),
        'status' => 'ERROR',
        'error_message' => null,
        'message_id' => $messageId,
        'sent_at' => $sentAt,
        'has_attachments' => 0,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'created_by_user_id' => (int)($currentUser['id'] ?? 0),
    ]);

    $sendResult = sendCrmEmail(
        [
            'archive_enabled' => !empty($systemCfg['crm_archive_enabled']),
            'archive_bcc_email' => trim((string)($systemCfg['crm_archive_bcc_email'] ?? '')),
        ],
        $smtpConfig,
        [
            'to' => [$clientEmail],
            'subject' => (string)($template['subject'] ?? ''),
            'body_html' => nl2br(htmlspecialchars((string)($template['body'] ?? ''), ENT_QUOTES, 'UTF-8')),
            'body_text' => (string)($template['body'] ?? ''),
            'message_id' => $messageId,
        ],
        []
    );

    $mailStatus = $sendResult['ok'] ? 'SENT' : 'ERROR';
    $mailError = $sendResult['ok'] ? null : mailboxSanitizeMailError((string)($sendResult['error'] ?? ''));
    $stmtMailStatus = $pdo->prepare('UPDATE mail_messages SET status = :status, error_message = :error WHERE id = :id');
    $stmtMailStatus->execute([
        ':status' => $mailStatus,
        ':error' => $mailError,
        ':id' => $mailMessageId,
    ]);

    if (!empty($commResult['id'])) {
        communicationUpdateEventStatus(
            $pdo,
            (int)$commResult['id'],
            $sendResult['ok'] ? 'sent' : 'error',
            [
                'mail_message_id' => $mailMessageId,
                'smtp_error' => $mailError,
            ]
        );
    }

    if (!$sendResult['ok']) {
        throw new RuntimeException('Wysyłka linku briefu nieudana: ' . (string)$mailError);
    }

    markBriefAsSent($pdo, $briefId);
    if ((int)($campaign['klient_id'] ?? 0) > 0) {
        addActivity('klient', (int)$campaign['klient_id'], 'mail', (int)($currentUser['id'] ?? 0), 'Wyslano link briefu: ' . $briefPublicUrl, null, (string)($template['subject'] ?? 'Link briefu'));
    }
    if ((int)($campaign['source_lead_id'] ?? 0) > 0) {
        addActivity('lead', (int)$campaign['source_lead_id'], 'mail', (int)($currentUser['id'] ?? 0), 'Wyslano link briefu: ' . $briefPublicUrl, null, (string)($template['subject'] ?? 'Link briefu'));
    }

    return [
        'status' => 'sent',
        'message' => 'Link do briefu został wysłany do klienta.',
        'brief_url' => $briefPublicUrl,
    ];
}

function fetchOperationalBriefQueue(PDO $pdo): array
{
    if (!tableExists($pdo, 'kampanie')) {
        return [];
    }

    ensureLeadBriefsTable($pdo);
    ensureKampanieOwnershipColumns($pdo);

    $kampCols = getTableColumns($pdo, 'kampanie');
    $hasUsers = tableExists($pdo, 'uzytkownicy');
    $hasClients = tableExists($pdo, 'klienci');
    $hasCommEvents = tableExists($pdo, 'communication_events');
    $userCols = $hasUsers ? getTableColumns($pdo, 'uzytkownicy') : [];

    $select = [
        'k.id',
        selectOrEmpty('k', 'klient_id', $kampCols),
        'k.klient_nazwa',
        selectOrEmpty('k', 'status', $kampCols),
        selectOrEmpty('k', 'realization_status', $kampCols),
        selectOrEmpty('k', 'source_lead_id', $kampCols),
        selectOrEmpty('k', 'owner_user_id', $kampCols),
        selectOrEmpty('k', 'data_start', $kampCols),
        selectOrEmpty('k', 'data_koniec', $kampCols),
        selectOrEmpty('k', 'created_at', $kampCols),
        'lb.id AS brief_id',
        'lb.token AS brief_token',
        'lb.status AS brief_status',
        'lb.is_customer_editable',
        'lb.spot_length_seconds',
        'lb.lector_count',
        'lb.target_group',
        'lb.main_message',
        'lb.additional_info',
        'lb.contact_details',
        'lb.tone_style',
        'lb.sound_effects',
        'lb.notes',
        'lb.production_owner_user_id',
        'lb.production_external_studio',
        'lb.submitted_at',
    ];
    $select[] = hasColumn($kampCols, 'propozycja') ? 'COALESCE(k.propozycja, 0) AS is_proposal' : '0 AS is_proposal';

    $joins = [
        'LEFT JOIN lead_briefs lb ON lb.campaign_id = k.id',
    ];

    if ($hasUsers) {
        $joins[] = 'LEFT JOIN uzytkownicy owner_u ON owner_u.id = k.owner_user_id';
        $joins[] = 'LEFT JOIN uzytkownicy prod_u ON prod_u.id = lb.production_owner_user_id';
        $select[] = hasColumn($userCols, 'login') ? 'owner_u.login AS owner_login' : "'' AS owner_login";
        $select[] = hasColumn($userCols, 'imie') ? 'owner_u.imie AS owner_imie' : "'' AS owner_imie";
        $select[] = hasColumn($userCols, 'nazwisko') ? 'owner_u.nazwisko AS owner_nazwisko' : "'' AS owner_nazwisko";
        $select[] = hasColumn($userCols, 'login') ? 'prod_u.login AS production_owner_login' : "'' AS production_owner_login";
        $select[] = hasColumn($userCols, 'imie') ? 'prod_u.imie AS production_owner_imie' : "'' AS production_owner_imie";
        $select[] = hasColumn($userCols, 'nazwisko') ? 'prod_u.nazwisko AS production_owner_nazwisko' : "'' AS production_owner_nazwisko";
    } else {
        $select[] = "'' AS owner_login";
        $select[] = "'' AS owner_imie";
        $select[] = "'' AS owner_nazwisko";
        $select[] = "'' AS production_owner_login";
        $select[] = "'' AS production_owner_imie";
        $select[] = "'' AS production_owner_nazwisko";
    }

    if ($hasClients) {
        $clientCols = getTableColumns($pdo, 'klienci');
        if (hasColumn($kampCols, 'klient_id')) {
            $joins[] = 'LEFT JOIN klienci cli ON cli.id = k.klient_id';
            $select[] = hasColumn($clientCols, 'email') ? 'cli.email AS client_email' : "'' AS client_email";
        } else {
            $select[] = "'' AS client_email";
        }
    } else {
        $select[] = "'' AS client_email";
    }

    if ($hasCommEvents) {
        $joins[] = "LEFT JOIN (
            SELECT campaign_id,
                   COUNT(CASE WHEN event_type = 'brief_link_sent' THEN 1 END) AS brief_dispatch_count,
                   MAX(CASE WHEN event_type = 'brief_link_sent' AND status IN ('logged','sent') THEN created_at END) AS brief_sent_at
            FROM communication_events
            GROUP BY campaign_id
        ) ce ON ce.campaign_id = k.id";
        $select[] = 'COALESCE(ce.brief_dispatch_count, 0) AS brief_dispatch_count';
        $select[] = 'ce.brief_sent_at';
    } else {
        $select[] = '0 AS brief_dispatch_count';
        $select[] = 'NULL AS brief_sent_at';
    }

    $sql = 'SELECT ' . implode(",\n        ", $select) . "\nFROM kampanie k\n" . implode("\n", $joins) . "\nORDER BY COALESCE(k.data_start, k.created_at) DESC, k.id DESC";
    $stmt = $pdo->query($sql);
    $rows = $stmt ? ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];

    $out = [];
    foreach ($rows as $row) {
        $campaignStatus = normalizeCampaignStatus((string)($row['status'] ?? ''));
        $isProposal = !empty($row['is_proposal']) || $campaignStatus === 'propozycja';
        if ($isProposal) {
            continue;
        }

        $brief = null;
        if ((int)($row['brief_id'] ?? 0) > 0) {
            $brief = [
                'id' => (int)$row['brief_id'],
                'campaign_id' => (int)$row['id'],
                'token' => (string)($row['brief_token'] ?? ''),
                'status' => (string)($row['brief_status'] ?? 'draft'),
                'is_customer_editable' => (int)($row['is_customer_editable'] ?? 0),
                'spot_length_seconds' => $row['spot_length_seconds'] ?? null,
                'lector_count' => $row['lector_count'] ?? null,
                'target_group' => $row['target_group'] ?? null,
                'main_message' => $row['main_message'] ?? null,
                'additional_info' => $row['additional_info'] ?? null,
                'contact_details' => $row['contact_details'] ?? null,
                'tone_style' => $row['tone_style'] ?? null,
                'sound_effects' => $row['sound_effects'] ?? null,
                'notes' => $row['notes'] ?? null,
                'production_owner_user_id' => $row['production_owner_user_id'] ?? null,
                'production_external_studio' => $row['production_external_studio'] ?? null,
                'submitted_at' => $row['submitted_at'] ?? null,
            ];
        }

        $campaign = [
            'id' => (int)$row['id'],
            'klient_id' => (int)($row['klient_id'] ?? 0),
            'klient_nazwa' => (string)($row['klient_nazwa'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
            'realization_status' => (string)($row['realization_status'] ?? ''),
            'source_lead_id' => (int)($row['source_lead_id'] ?? 0),
            'owner_user_id' => (int)($row['owner_user_id'] ?? 0),
            'data_start' => (string)($row['data_start'] ?? ''),
            'data_koniec' => (string)($row['data_koniec'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];

        $row['brief'] = $brief;
        $row['campaign'] = $campaign;
        $row['brief_state'] = resolveBriefOperationalState($campaign, $brief, [
            'brief_dispatch_count' => (int)($row['brief_dispatch_count'] ?? 0),
            'client_email' => (string)($row['client_email'] ?? ''),
        ]);
        $out[] = $row;
    }

    return $out;
}

function syncCampaignStatusFromAudio(PDO $pdo, int $campaignId): void
{
    if ($campaignId <= 0 || !tableExists($pdo, 'kampanie')) {
        return;
    }

    if (campaignHasAcceptedFinalAudio($pdo, $campaignId)) {
        $stmt = $pdo->prepare("UPDATE kampanie
            SET realization_status = 'zaakceptowany_spot'
            WHERE id = :id AND COALESCE(realization_status, '') <> 'do_emisji'");
        $stmt->execute([':id' => $campaignId]);
    }
}

function setCampaignRealizationStatus(PDO $pdo, int $campaignId, string $targetStatus): void
{
    ensureKampanieOwnershipColumns($pdo);
    $stmtCurrent = $pdo->prepare('SELECT realization_status FROM kampanie WHERE id = :id LIMIT 1');
    $stmtCurrent->execute([':id' => $campaignId]);
    $currentStatus = trim((string)($stmtCurrent->fetchColumn() ?: ''));

    $guardError = validateCampaignRealizationStatusChange($pdo, $campaignId, $targetStatus);
    if ($guardError !== null) {
        throw new RuntimeException($guardError);
    }

    $stmt = $pdo->prepare('UPDATE kampanie SET realization_status = :status WHERE id = :id');
    $stmt->execute([
        ':status' => $targetStatus,
        ':id' => $campaignId,
    ]);

    $actorUserId = !empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
    $transitionKey = communicationBuildIdempotencyKey('campaign_status_transition', [
        $campaignId,
        $currentStatus !== '' ? $currentStatus : 'none',
        $targetStatus,
    ]);

    if ($targetStatus === 'w_produkcji') {
        communicationLogEvent($pdo, [
            'event_type' => 'production_started',
            'idempotency_key' => $transitionKey,
            'direction' => 'system',
            'status' => 'logged',
            'subject' => 'Rozpoczęto produkcję kampanii #' . $campaignId,
            'body' => 'Kampania przeszła do etapu w_produkcji.',
            'campaign_id' => $campaignId,
            'created_by_user_id' => $actorUserId,
            'meta_json' => [
                'from_status' => $currentStatus,
                'to_status' => $targetStatus,
            ],
        ]);

        $users = communicationResolveCampaignUsers($pdo, $campaignId);
        $tmpl = communicationTemplateInternal('production_started', ['campaign_id' => $campaignId]);
        foreach ([
            (int)($users['campaign_owner_user_id'] ?? 0),
            (int)($users['production_owner_user_id'] ?? 0),
        ] as $recipientUserId) {
            if ($recipientUserId <= 0) {
                continue;
            }
            communicationCreateInternalNotification(
                $pdo,
                $recipientUserId,
                'production_started',
                (string)$tmpl['body'],
                'kampania_podglad.php?id=' . $campaignId
            );
        }
    }

    if ($targetStatus === 'do_emisji') {
        communicationLogEvent($pdo, [
            'event_type' => 'campaign_ready_for_emission',
            'idempotency_key' => $transitionKey,
            'direction' => 'system',
            'status' => 'logged',
            'subject' => 'Kampania #' . $campaignId . ' gotowa do emisji',
            'body' => 'Ustawiono status do_emisji.',
            'campaign_id' => $campaignId,
            'created_by_user_id' => $actorUserId,
            'meta_json' => [
                'from_status' => $currentStatus,
                'to_status' => $targetStatus,
            ],
        ]);

        $users = communicationResolveCampaignUsers($pdo, $campaignId);
        $tmpl = communicationTemplateInternal('campaign_ready_for_emission', ['campaign_id' => $campaignId]);
        foreach ([
            (int)($users['campaign_owner_user_id'] ?? 0),
            (int)($users['production_owner_user_id'] ?? 0),
            (int)($users['lead_owner_user_id'] ?? 0),
        ] as $recipientUserId) {
            if ($recipientUserId <= 0) {
                continue;
            }
            communicationCreateInternalNotification(
                $pdo,
                $recipientUserId,
                'campaign_ready_for_emission',
                (string)$tmpl['body'],
                'kampania_podglad.php?id=' . $campaignId
            );
        }
    }
}

function normalizeBriefPayload(array $source): array
{
    $spotLength = isset($source['spot_length_seconds']) ? (int)$source['spot_length_seconds'] : null;
    $lectorCount = isset($source['lector_count']) ? (int)$source['lector_count'] : null;

    return [
        'spot_length_seconds' => $spotLength !== null && $spotLength > 0 ? $spotLength : null,
        'lector_count' => $lectorCount !== null && $lectorCount >= 0 ? $lectorCount : null,
        'target_group' => trim((string)($source['target_group'] ?? '')) ?: null,
        'main_message' => trim((string)($source['main_message'] ?? '')) ?: null,
        'additional_info' => trim((string)($source['additional_info'] ?? '')) ?: null,
        'contact_details' => trim((string)($source['contact_details'] ?? '')) ?: null,
        'tone_style' => trim((string)($source['tone_style'] ?? '')) ?: null,
        'sound_effects' => trim((string)($source['sound_effects'] ?? '')) ?: null,
        'notes' => trim((string)($source['notes'] ?? '')) ?: null,
    ];
}
