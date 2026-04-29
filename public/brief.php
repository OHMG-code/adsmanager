<?php
declare(strict_types=1);

require_once '../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/briefs.php';
require_once __DIR__ . '/includes/communication_events.php';
require_once __DIR__ . '/includes/communication_templates.php';
require_once __DIR__ . '/includes/task_automation.php';

ensureLeadBriefsTable($pdo);
ensureKampanieOwnershipColumns($pdo);

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '' && !empty($_SERVER['REQUEST_URI']) && preg_match('#/brief/([A-Fa-f0-9]{64})#', (string)$_SERVER['REQUEST_URI'], $m)) {
    $token = $m[1];
}

if ($token === '') {
    http_response_code(404);
    exit('Nie znaleziono briefu.');
}

$brief = getBriefByToken($pdo, $token);
if (!$brief) {
    http_response_code(404);
    exit('Nie znaleziono briefu.');
}

$stmtCampaign = $pdo->prepare('SELECT id, klient_id, klient_nazwa, data_start, data_koniec, realization_status, owner_user_id, source_lead_id FROM kampanie WHERE id = :id LIMIT 1');
$stmtCampaign->execute([':id' => (int)$brief['campaign_id']]);
$campaign = $stmtCampaign->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$campaign) {
    http_response_code(404);
    exit('Nie znaleziono kampanii briefu.');
}

function briefResolveClientEmail(PDO $pdo, array $campaign): string
{
    $campaignId = (int)($campaign['id'] ?? 0);
    if ($campaignId <= 0) {
        return '';
    }

    try {
        $stmt = $pdo->prepare("SELECT COALESCE(
                NULLIF(TRIM(cli.email), ''),
                NULLIF(TRIM(cli.kontakt_email), ''),
                NULLIF(TRIM(src_lead.kontakt_email), ''),
                NULLIF(TRIM(src_lead.email), '')
            ) AS client_email
            FROM kampanie k
            LEFT JOIN klienci cli ON cli.id = k.klient_id
            LEFT JOIN leady src_lead ON src_lead.id = k.source_lead_id
            WHERE k.id = :id
            LIMIT 1");
        $stmt->execute([':id' => $campaignId]);
        $email = trim((string)($stmt->fetchColumn() ?: ''));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    } catch (Throwable $e) {
        error_log('brief public: cannot resolve client email: ' . $e->getMessage());
        return '';
    }
}

function briefSendCustomerCopyEmail(PDO $pdo, array $campaign, array $brief, array $payload, array $sourcePost): bool
{
    $clientEmail = briefResolveClientEmail($pdo, $campaign);
    if ($clientEmail === '') {
        error_log('brief public: customer copy skipped, missing client email for campaign ' . (int)($campaign['id'] ?? 0));
        return false;
    }

    require_once __DIR__ . '/includes/mail_service.php';
    $systemCfg = fetchSystemConfigSafe($pdo);
    $smtpConfig = resolveSmtpConfig($systemCfg, []);
    if (trim((string)($smtpConfig['host'] ?? '')) === '' || trim((string)($smtpConfig['from_email'] ?? '')) === '') {
        error_log('brief public: customer copy skipped, incomplete SMTP config');
        return false;
    }
    if (trim((string)($smtpConfig['from_name'] ?? '')) === '') {
        $smtpConfig['from_name'] = trim((string)($systemCfg['company_name'] ?? 'Adds Manager CRM')) ?: 'Adds Manager CRM';
    }

    $row = static function (string $label, $value): string {
        $text = trim((string)($value ?? ''));
        if ($text === '') {
            $text = 'Nie podano';
        }
        return '<tr><td style="padding:10px 14px;border-bottom:1px solid #e5edf6;color:#64748b;font-weight:700;width:210px;">'
            . htmlspecialchars($label)
            . '</td><td style="padding:10px 14px;border-bottom:1px solid #e5edf6;color:#0f172a;">'
            . nl2br(htmlspecialchars($text))
            . '</td></tr>';
    };

    $subject = 'Potwierdzenie przeslania briefu kampanii';
    $clientName = trim((string)($campaign['klient_nazwa'] ?? ''));
    $html = '<!doctype html><html><body style="margin:0;background:#f4f8fc;font-family:Arial,sans-serif;color:#0f172a;">'
        . '<div style="max-width:760px;margin:0 auto;padding:28px;">'
        . '<div style="background:#0b1f3a;color:#fff;border-radius:18px 18px 0 0;padding:22px 26px;">'
        . '<div style="font-size:13px;color:#9ec8ee;font-weight:700;letter-spacing:.08em;text-transform:uppercase;">Adds Manager 1.0</div>'
        . '<h1 style="margin:8px 0 0;font-size:24px;">Brief zostal przeslany</h1>'
        . '</div>'
        . '<div style="background:#fff;border:1px solid #dbe7f3;border-top:0;border-radius:0 0 18px 18px;padding:26px;">'
        . '<p style="margin:0 0 16px;font-size:16px;line-height:1.6;">Dziekujemy. Brief zostal pozytywnie przeslany do bazy CRM. Na podstawie przekazanych informacji zespol bedzie teraz przygotowywal scenariusz spotu.</p>'
        . '<p style="margin:0 0 22px;color:#475569;line-height:1.6;">Ponizej znajduje sie kopia odpowiedzi zapisanych w formularzu.</p>'
        . '<table cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;border:1px solid #e5edf6;border-radius:12px;overflow:hidden;">'
        . $row('Klient / kampania', $clientName)
        . $row('Cel reklamy', $sourcePost['campaign_goal'] ?? '')
        . $row('Grupa docelowa', $payload['target_group'] ?? '')
        . $row('Tresc spotu', $payload['main_message'] ?? '')
        . $row('Call to action', $payload['contact_details'] ?? '')
        . $row('Dlugosc spotu', isset($payload['spot_length_seconds']) ? ((string)$payload['spot_length_seconds'] . ' sek.') : '')
        . $row('Styl i klimat', $payload['tone_style'] ?? '')
        . $row('Muzyka / efekty', $payload['sound_effects'] ?? '')
        . $row('Uwagi', $payload['notes'] ?? '')
        . '</table>'
        . '<p style="margin:22px 0 0;color:#64748b;font-size:13px;">To jest automatyczne potwierdzenie wyslane po zapisaniu briefu.</p>'
        . '</div></div></body></html>';

    $text = "Brief zostal pozytywnie przeslany do bazy CRM.\n"
        . "Na podstawie przekazanych informacji bedzie przygotowywany scenariusz spotu.\n\n"
        . "Klient / kampania: " . $clientName . "\n"
        . "Cel reklamy: " . trim((string)($sourcePost['campaign_goal'] ?? '')) . "\n"
        . "Grupa docelowa: " . trim((string)($payload['target_group'] ?? '')) . "\n"
        . "Tresc spotu: " . trim((string)($payload['main_message'] ?? '')) . "\n"
        . "Call to action: " . trim((string)($payload['contact_details'] ?? '')) . "\n";

    $result = sendCrmEmail(
        [
            'archive_enabled' => !empty($systemCfg['crm_archive_enabled']),
            'archive_bcc_email' => trim((string)($systemCfg['crm_archive_bcc_email'] ?? '')),
        ],
        $smtpConfig,
        [
            'to' => [$clientEmail],
            'subject' => $subject,
            'body_html' => $html,
            'body_text' => $text,
        ],
        []
    );

    if (empty($result['ok'])) {
        error_log('brief public: customer copy send failed: ' . (string)($result['error'] ?? 'unknown error'));
        return false;
    }

    return true;
}

$errors = [];
$successMessage = '';
$isLocked = briefIsSubmitted($brief) && !briefIsCustomerEditable($brief);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Niepoprawny token formularza.';
    } elseif ($isLocked) {
        $errors[] = 'Ten brief został już odesłany i jest tylko do odczytu.';
    } else {
        $payload = normalizeBriefPayload($_POST);
        if (($payload['spot_length_seconds'] ?? null) === null) {
            $errors[] = 'Podaj czas reklamy.';
        }
        if (($payload['main_message'] ?? null) === null) {
            $errors[] = 'Podaj najważniejszą informację.';
        }
        if (($payload['contact_details'] ?? null) === null) {
            $errors[] = 'Podaj dane teleadresowe.';
        }

        if (!$errors) {
            savePublicBriefSubmission($pdo, (int)$brief['id'], $payload);
            $currentStatus = trim((string)($campaign['realization_status'] ?? ''));
            if ($currentStatus === '' || $currentStatus === 'brief_oczekuje') {
                setCampaignRealizationStatus($pdo, (int)$brief['campaign_id'], 'brief_otrzymany');
            }
            $brief = getBriefByToken($pdo, $token) ?? $brief;

            $submittedAt = trim((string)($brief['submitted_at'] ?? ''));
            $briefReceivedKey = communicationBuildIdempotencyKey('brief_received', [
                (int)$brief['id'],
                $submittedAt !== '' ? $submittedAt : date('Y-m-d H:i:s'),
            ]);
            $briefComm = communicationLogEvent($pdo, [
                'event_type' => 'brief_received',
                'idempotency_key' => $briefReceivedKey,
                'direction' => 'system',
                'status' => 'logged',
                'subject' => 'Otrzymano brief kampanii #' . (int)$brief['campaign_id'],
                'body' => 'Klient przesłał brief.',
                'meta_json' => [
                    'brief_token' => (string)($brief['token'] ?? ''),
                    'submitted_at' => $submittedAt,
                ],
                'campaign_id' => (int)$brief['campaign_id'],
                'brief_id' => (int)$brief['id'],
                'created_by_user_id' => null,
            ]);
            createEventTask($pdo, 'brief_received', [
                'campaign_id' => (int)$brief['campaign_id'],
                'brief_id' => (int)$brief['id'],
                'communication_event_id' => (int)($briefComm['id'] ?? 0),
                'event_at' => $submittedAt !== '' ? $submittedAt : date('Y-m-d H:i:s'),
                'actor_user_id' => null,
            ]);

            $users = communicationResolveCampaignUsers($pdo, (int)$brief['campaign_id']);
            $internalTemplate = communicationTemplateInternal('brief_received', [
                'campaign_id' => (int)$brief['campaign_id'],
            ]);
            $notificationLink = 'kampania_podglad.php?id=' . (int)$brief['campaign_id'];
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
                    'brief_received',
                    (string)$internalTemplate['body'],
                    $notificationLink
                );
            }

            $isLocked = true;
            briefSendCustomerCopyEmail($pdo, $campaign, $brief, $payload, $_POST);
            $successMessage = 'Brief zostal pozytywnie przeslany do bazy CRM. Na podstawie przekazanych informacji bedzie teraz przygotowywany scenariusz spotu.';
        }
    }
}

$pageTitle = 'Brief kampanii';
$showSummary = $isLocked;
$statusKey = trim((string)($brief['status'] ?? 'draft'));
$statusLabel = briefStatusLabel($statusKey);
$statusClass = 'brief-status-pill';
if ($statusKey === 'submitted' || $statusKey === 'approved_internal') {
    $statusClass .= ' is-complete';
} elseif ($statusKey === 'sent') {
    $statusClass .= ' is-active';
} else {
    $statusClass .= ' is-draft';
}

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

$formatDateTime = static function (?string $raw): string {
    $raw = trim((string)$raw);
    if ($raw === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($raw))->format('d.m.Y, H:i');
    } catch (Throwable $e) {
        return $raw;
    }
};

$displayText = static function ($value, string $fallback = 'Nie uzupełniono'): string {
    $text = trim((string)($value ?? ''));
    return $text !== '' ? $text : $fallback;
};

$displayNumber = static function ($value, string $suffix, string $fallback = 'Nie podano'): string {
    if ($value === null || $value === '') {
        return $fallback;
    }
    return trim((string)$value) . $suffix;
};

$campaignDateStart = $formatDate((string)($campaign['data_start'] ?? ''));
$campaignDateEnd = $formatDate((string)($campaign['data_koniec'] ?? ''));
$campaignDateLabel = trim($campaignDateStart . ($campaignDateStart !== '' || $campaignDateEnd !== '' ? ' - ' : '') . $campaignDateEnd, ' -');
$submittedAtLabel = $formatDateTime((string)($brief['submitted_at'] ?? ''));

$spotLengthOptions = [15, 20, 30];
$currentSpotLength = (int)($brief['spot_length_seconds'] ?? 0);
$customSpotLength = ($currentSpotLength > 0 && !in_array($currentSpotLength, $spotLengthOptions, true)) ? $currentSpotLength : null;
$initialSpotLengthChoice = $customSpotLength !== null ? 'custom' : ($currentSpotLength > 0 ? (string)$currentSpotLength : '');
$initialSpotLengthValue = $currentSpotLength > 0 ? (string)$currentSpotLength : '';

$lectorOptions = [1, 2, 3, 4];
$currentLectorCount = ($brief['lector_count'] ?? null) !== null ? (int)$brief['lector_count'] : null;
$hasCustomLectorCount = $currentLectorCount !== null && !in_array($currentLectorCount, $lectorOptions, true);

$tonePresets = [
    'nowoczesna',
    'dynamiczna',
    'premium',
    'ciepła i przyjazna',
    'lokalna i swojska',
    'spokojna',
    'bezpośrednia',
    'promocyjna',
];

$soundPresets = [
    'muzyka w tle',
    'energiczne wejście',
    'subtelne efekty',
    'brzmienie premium',
    'lekki, ciepły klimat',
    'bez muzyki',
    'krótkie akcenty dźwiękowe',
    'naturalne tło',
];

$formSections = [
    [
        'id' => 'brief-core',
        'step' => '01',
        'title' => 'Fundament briefu',
        'description' => 'Ustalamy podstawy spotu i to, do kogo reklama ma trafić.',
    ],
    [
        'id' => 'brief-message',
        'step' => '02',
        'title' => 'Treść reklamy',
        'description' => 'Tu zbieramy najważniejszy komunikat, kontekst i dane do odczytania.',
    ],
    [
        'id' => 'brief-style',
        'step' => '03',
        'title' => 'Styl i brzmienie',
        'description' => 'Wybierz kierunek, a jeśli chcesz, doprecyzuj go własnymi słowami.',
    ],
    [
        'id' => 'brief-finish',
        'step' => '04',
        'title' => 'Finalizacja',
        'description' => 'Na końcu dodaj uwagi i wyślij brief do realizacji.',
    ],
];
$formSections = [
    ['id' => 'brief-core', 'step' => '1', 'title' => 'Podstawy', 'description' => 'Opisz firme, cel reklamy i odbiorce, do ktorego mowimy.'],
    ['id' => 'brief-message', 'step' => '2', 'title' => 'Tresc spotu', 'description' => 'Zbierz najwazniejszy przekaz, haslo i wezwanie do dzialania.'],
    ['id' => 'brief-style', 'step' => '3', 'title' => 'Styl i klimat', 'description' => 'Wybierz ton, glos i tempo spotu.'],
    ['id' => 'brief-addons', 'step' => '4', 'title' => 'Dodatki', 'description' => 'Doprecyzuj muzyke, efekty i referencje.'],
    ['id' => 'brief-technical', 'step' => '5', 'title' => 'Dane techniczne', 'description' => 'Podaj dlugosc spotu, termin i uwagi.'],
];

$summarySections = [
    [
        'title' => 'Fundament briefu',
        'items' => [
            ['label' => 'Długość spotu', 'value' => $displayNumber($brief['spot_length_seconds'] ?? null, ' s')],
            ['label' => 'Liczba głosów', 'value' => $displayNumber($brief['lector_count'] ?? null, '', 'Bez preferencji')],
            ['label' => 'Odbiorca', 'value' => $displayText($brief['target_group'] ?? null)],
        ],
    ],
    [
        'title' => 'Treść reklamy',
        'items' => [
            ['label' => 'Najważniejszy przekaz', 'value' => $displayText($brief['main_message'] ?? null)],
            ['label' => 'Dodatkowe informacje', 'value' => $displayText($brief['additional_info'] ?? null)],
            ['label' => 'Dane do użycia w spocie', 'value' => $displayText($brief['contact_details'] ?? null)],
        ],
    ],
    [
        'title' => 'Styl i brzmienie',
        'items' => [
            ['label' => 'Styl reklamy', 'value' => $displayText($brief['tone_style'] ?? null)],
            ['label' => 'Dźwięk i klimat', 'value' => $displayText($brief['sound_effects'] ?? null)],
        ],
    ],
    [
        'title' => 'Uwagi',
        'items' => [
            ['label' => 'Dodatkowe wskazówki', 'value' => $displayText($brief['notes'] ?? null)],
        ],
    ],
];

$nextSteps = [
    'Zespół produkcyjny sprawdzi brief i przygotuje kierunek dalszych prac.',
    'Jeśli będzie potrzebne doprecyzowanie, wrócimy z jednym, konkretnym pytaniem.',
    'Po stronie realizacji brief jest już widoczny i może przejść do kolejnego etapu.',
];

$briefAssetVersion = (int) (@filemtime(__DIR__ . '/assets/css/brief-public.css') ?: time());
$briefPublicBaseUrl = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? ''))), '/');
if ($briefPublicBaseUrl === '' || $briefPublicBaseUrl === '.') {
    $briefPublicBaseUrl = rtrim((string)BASE_URL, '/');
}
$briefModernVersion = '20260428_ui2';
$briefShortToken = substr((string)($brief['token'] ?? $token), 0, 8);
$briefReturnUrl = $briefPublicBaseUrl . '/briefy.php';
$briefCalculatorUrl = $briefPublicBaseUrl . '/kalkulator_tygodniowy.php';
$briefInboxUrl = $briefPublicBaseUrl . '/skrzynka.php';
$briefDashboardUrl = $briefPublicBaseUrl . '/dashboard.php';
$briefLeadsUrl = $briefPublicBaseUrl . '/lead.php';
$briefClientsUrl = $briefPublicBaseUrl . '/klienci.php';
$briefCampaignsUrl = $briefPublicBaseUrl . '/kampanie_lista.php';
$briefMediaPlansUrl = $briefPublicBaseUrl . '/kalkulator_tygodniowy.php';
$briefSettingsUrl = $briefPublicBaseUrl . '/ustawienia.php';
$briefUsersUrl = $briefPublicBaseUrl . '/uzytkownicy.php';
$briefPricesUrl = $briefPublicBaseUrl . '/cenniki.php';
$briefGoalsUrl = $briefPublicBaseUrl . '/cele.php';
$briefAdminUrl = $briefPublicBaseUrl . '/admin/';
$briefReportsUrl = $briefPublicBaseUrl . '/raport_cele.php';
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="<?= htmlspecialchars($briefPublicBaseUrl) ?>/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($briefPublicBaseUrl) ?>/assets/css/brief-modern.css?v=<?= htmlspecialchars($briefModernVersion) ?>">
</head>
<body class="brief-app">
  <!-- BRIEF_MODERN_LAYOUT_V2_ACTIVE -->
  <aside class="brief-crm-sidebar">
    <div class="brief-brand">
      <div class="brief-brand__mark">AM</div>
      <div>
        <strong>Adds Manager 1.0</strong>
        <span>Brief publiczny</span>
      </div>
    </div>
    <div class="brief-public-note">
      <strong>Dostep dla klienta</strong>
      <span>Ten link sluzy tylko do wypelnienia briefu.</span>
    </div>
    <div class="brief-nav-title">Etapy realizacji</div>
    <nav class="brief-nav brief-nav--public" aria-label="Etapy realizacji">
      <span class="brief-nav__item is-active">Brief kampanii</span>
      <span class="brief-nav__item">Produkcja spotu</span>
      <span class="brief-nav__item">Akceptacja</span>
      <span class="brief-nav__item">Emisja kampanii</span>
    </nav>
    <div class="brief-help">
      <strong>Pomoc techniczna</strong>
      <span>W razie problemow skontaktuj sie z opiekunem klienta.</span>
    </div>
  </aside>

  <main class="brief-page">
    <header class="brief-topbar">
      <div>
        <span class="brief-link-back">Publiczny formularz klienta</span>
        <h1>Brief kampanii / spotu</h1>
        <div class="brief-topbar__meta">
          ID briefu: <?= htmlspecialchars($briefShortToken) ?> ·
          <span class="brief-status"><?= $showSummary ? '✓ Zapisano' : 'Szkic' ?></span>
        </div>
      </div>
      <div class="brief-topbar__actions">
        <span class="brief-avatar">AM</span>
      </div>
    </header>

    <?php if ($errors): ?>
      <div class="brief-alert is-error" role="alert">
        <?php foreach ($errors as $error): ?>
          <div><?= htmlspecialchars($error) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($successMessage !== ''): ?>
      <div class="brief-alert is-success" role="status"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>

    <section class="brief-main-card">
      <aside class="brief-stepper" aria-label="Kroki briefu">
        <div class="brief-stepper__heading">
          <span>Kreator briefu</span>
          <strong><?= htmlspecialchars((string)($campaign['klient_nazwa'] ?? 'Kampania')) ?></strong>
        </div>
        <div class="brief-stepper__list">
          <?php
          $modernSteps = [
              ['Podstawy', 'Firma, cel i odbiorcy'],
              ['Tresc spotu', 'Co chcemy powiedziec'],
              ['Styl i klimat', 'Brzmienie, glos, tempo'],
              ['Dodatki', 'Muzyka, efekty, referencje'],
              ['Dane techniczne', 'Dlugosc, termin, uwagi'],
              ['Podsumowanie', 'Sprawdz i zapisz brief'],
          ];
          foreach ($modernSteps as $index => $step):
          ?>
            <button class="brief-stepper__item <?= $index === 0 ? 'is-active' : '' ?>" type="button" data-brief-step="<?= $index + 1 ?>">
              <span class="brief-stepper__number"><?= $index + 1 ?></span>
              <span>
                <span class="brief-stepper__title"><?= htmlspecialchars($step[0]) ?></span>
                <span class="brief-stepper__desc"><?= htmlspecialchars($step[1]) ?></span>
              </span>
            </button>
          <?php endforeach; ?>
        </div>
      </aside>

      <?php if ($showSummary): ?>
        <section class="brief-form">
          <div class="brief-form-header">
            <span>Brief zapisany</span>
            <h2>Brief zostal przyjety</h2>
            <p>
              <?= $submittedAtLabel !== '' ? 'Zapisano: ' . htmlspecialchars($submittedAtLabel) . '. ' : '' ?>
              Brief zostal pozytywnie przeslany do bazy CRM. Na podstawie przekazanych informacji bedzie teraz przygotowywany scenariusz spotu.
            </p>
          </div>
          <div class="brief-summary-grid">
            <?php foreach ($summarySections as $section): ?>
              <?php foreach ($section['items'] as $item): ?>
                <div class="brief-summary-item">
                  <span><?= htmlspecialchars($item['label']) ?></span>
                  <strong><?= nl2br(htmlspecialchars((string)$item['value'])) ?></strong>
                </div>
              <?php endforeach; ?>
            <?php endforeach; ?>
          </div>
        </section>
      <?php else: ?>
        <form method="post" id="creative-brief-form" class="brief-form" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
          <input type="hidden" name="spot_length_seconds" id="spot_length_seconds" value="<?= htmlspecialchars($initialSpotLengthValue) ?>">
          <input type="hidden" name="additional_info" id="additional_info" value="<?= htmlspecialchars((string)($brief['additional_info'] ?? '')) ?>">
          <input type="hidden" name="tone_style" id="tone_style" value="<?= htmlspecialchars((string)($brief['tone_style'] ?? '')) ?>">
          <input type="hidden" name="sound_effects" id="sound_effects" value="<?= htmlspecialchars((string)($brief['sound_effects'] ?? '')) ?>">
          <input type="hidden" name="notes" id="notes" value="<?= htmlspecialchars((string)($brief['notes'] ?? '')) ?>">
          <input type="hidden" name="lector_count" id="lector_count" value="<?= htmlspecialchars($currentLectorCount !== null ? (string)$currentLectorCount : '') ?>">
          <div id="brief-form-errors" class="brief-alert is-error d-none" role="alert" aria-live="polite"></div>

          <section class="brief-step-panel is-active" data-brief-panel="1">
            <div class="brief-form-header">
              <span>Krok 1 z 6</span>
              <h2>Podstawy</h2>
              <p>Opisz firme, cel reklamy i odbiorcow. To ustawia kierunek calego spotu.</p>
            </div>
            <div class="brief-form-grid">
              <div class="brief-field">
                <label for="ui_company_name">Nazwa firmy</label>
                <input type="text" id="ui_company_name" name="ui_company_name" value="<?= htmlspecialchars((string)($campaign['klient_nazwa'] ?? '')) ?>">
                <div class="brief-helper">Nazwa, ktora ma byc rozpoznawalna dla sluchacza.</div>
              </div>
              <div class="brief-field">
                <label for="ui_industry">Branza</label>
                <input type="text" id="ui_industry" name="ui_industry" placeholder="np. gastronomia, motoryzacja, edukacja">
                <div class="brief-helper">Jedno lub dwa slowa wystarcza.</div>
              </div>
              <div class="brief-field brief-field--full" data-required-group="campaign_goal" data-required-label="cel reklamy">
                <span class="brief-field-label">Cel reklamy <span class="brief-required">Wymagane</span></span>
                <div class="brief-radio-grid brief-radio-grid--4">
                  <label class="brief-radio-card"><input type="radio" name="campaign_goal" value="sprzedaz"><span class="brief-radio-icon">PLN</span><strong>Sprzedaz</strong><small>Chcemy zwiekszyc sprzedaz.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="campaign_goal" value="wizerunek"><span class="brief-radio-icon">★</span><strong>Wizerunek</strong><small>Budujemy rozpoznawalnosc marki.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="campaign_goal" value="promocja wydarzenia"><span class="brief-radio-icon">EV</span><strong>Promocja wydarzenia</strong><small>Zapraszamy na konkretny termin.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="campaign_goal" value="informacja"><span class="brief-radio-icon">i</span><strong>Informacja</strong><small>Przekazujemy wazny komunikat.</small></label>
                </div>
              </div>
              <div class="brief-field brief-field--full">
                <label for="target_group">Grupa docelowa</label>
                <textarea id="target_group" name="target_group" data-count placeholder="np. mieszkancy Zulaw, rodzice dzieci w wieku szkolnym, wlasciciele lokalnych firm"><?= htmlspecialchars((string)($brief['target_group'] ?? '')) ?></textarea>
                <div class="brief-helper">Opisz jednym zdaniem, do kogo ma trafic reklama.</div>
                <div class="brief-counter" data-counter-for="target_group"></div>
              </div>
            </div>
          </section>

          <section class="brief-step-panel" data-brief-panel="2">
            <div class="brief-form-header">
              <span>Krok 2 z 6</span>
              <h2>Tresc spotu</h2>
              <p>Zbierz najwazniejszy przekaz, haslo i konkretna akcje dla odbiorcy.</p>
            </div>
            <div class="brief-form-grid">
              <div class="brief-field brief-field--full">
                <label for="main_message">Co ma byc powiedziane? <span class="brief-required">Wymagane</span></label>
                <textarea id="main_message" name="main_message" data-count data-required-field data-required-label="tresc spotu" placeholder="np. Startuje promocja weekendowa, rabat -20% i zaproszenie do sklepu"><?= htmlspecialchars((string)($brief['main_message'] ?? '')) ?></textarea>
                <div class="brief-helper">Opisz jednym zdaniem co klient ma zapamietac.</div>
                <div class="brief-counter" data-counter-for="main_message"></div>
              </div>
              <div class="brief-field">
                <label for="ui_slogan">Kluczowe haslo reklamowe</label>
                <input type="text" id="ui_slogan" name="ui_slogan" placeholder="np. Blisko, szybko, lokalnie">
                <div class="brief-helper">Jesli nie ma gotowego hasla, zostaw puste.</div>
              </div>
              <div class="brief-field">
                <label for="contact_details">Call to action <span class="brief-required">Wymagane</span></label>
                <textarea id="contact_details" name="contact_details" data-count data-required-field data-required-label="call to action" placeholder="np. Zadzwon pod 123 456 789 albo odwiedz www.twojafirma.pl"><?= htmlspecialchars((string)($brief['contact_details'] ?? '')) ?></textarea>
                <div class="brief-helper">Napisz, co odbiorca ma zrobic po uslyszeniu reklamy.</div>
                <div class="brief-counter" data-counter-for="contact_details"></div>
              </div>
            </div>
          </section>

          <section class="brief-step-panel" data-brief-panel="3">
            <div class="brief-form-header">
              <span>Krok 3 z 6</span>
              <h2>Styl i klimat</h2>
              <p>Wybierz brzmienie, glos i tempo spotu. To sugestie dla produkcji.</p>
            </div>
            <div class="brief-form-grid">
              <div class="brief-field brief-field--full">
                <span class="brief-field-label">Styl spotu</span>
                <div class="brief-radio-grid brief-radio-grid--4">
                  <label class="brief-radio-card"><input type="radio" name="ui_style" value="dynamiczny"><span class="brief-radio-icon">>>></span><strong>Dynamiczny</strong><small>Szybko, energicznie, sprzedazowo.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="ui_style" value="spokojny"><span class="brief-radio-icon">~</span><strong>Spokojny</strong><small>Cieplo, jasno, bez presji.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="ui_style" value="humorystyczny"><span class="brief-radio-icon">:)</span><strong>Humorystyczny</strong><small>Lekko, z dystansem i zapamietywalnie.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="ui_style" value="premium"><span class="brief-radio-icon">PR</span><strong>Premium</strong><small>Elegancko, spokojnie, zaufanie.</small></label>
                </div>
              </div>
              <div class="brief-field">
                <span class="brief-field-label">Glos</span>
                <div class="brief-radio-grid brief-radio-grid--3">
                  <label class="brief-radio-card"><input type="radio" name="ui_voice" value="meski"><span class="brief-radio-icon">M</span><strong>Meski</strong><small>Nizszy, mocny glos.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="ui_voice" value="zenski"><span class="brief-radio-icon">K</span><strong>Zenski</strong><small>Jasny, lekki glos.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="ui_voice" value="bez znaczenia"><span class="brief-radio-icon">?</span><strong>Bez znaczenia</strong><small>Produkcja dobierze najlepiej.</small></label>
                </div>
              </div>
              <div class="brief-field">
                <span class="brief-field-label">Tempo</span>
                <div class="brief-radio-grid brief-radio-grid--3">
                  <label class="brief-radio-card"><input type="radio" name="ui_tempo" value="wolne"><span class="brief-radio-icon">1x</span><strong>Wolne</strong><small>Wiekszy nacisk na zrozumienie.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="ui_tempo" value="srednie"><span class="brief-radio-icon">2x</span><strong>Srednie</strong><small>Naturalny rytm reklamy.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="ui_tempo" value="szybkie"><span class="brief-radio-icon">3x</span><strong>Szybkie</strong><small>Duzo energii w krotkim czasie.</small></label>
                </div>
              </div>
            </div>
          </section>

          <section class="brief-step-panel" data-brief-panel="4">
            <div class="brief-form-header">
              <span>Krok 4 z 6</span>
              <h2>Dodatki</h2>
              <p>Doprecyzuj muzyke, efekty i referencje, jesli sa wazne.</p>
            </div>
            <div id="briefMusicHint" class="brief-alert is-success" hidden></div>
            <div class="brief-form-grid">
              <div class="brief-field brief-field--full">
                <span class="brief-field-label">Muzyka</span>
                <div class="brief-radio-grid brief-radio-grid--3">
                  <label class="brief-radio-card"><input type="radio" name="ui_music" value="brak"><span class="brief-radio-icon">OFF</span><strong>Brak</strong><small>Sam lektor i czysty przekaz.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="ui_music" value="spokojna"><span class="brief-radio-icon">♪</span><strong>Spokojna</strong><small>Tlo, ktore nie przykrywa tresci.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="ui_music" value="dynamiczna"><span class="brief-radio-icon">♫</span><strong>Dynamiczna</strong><small>Energia i rytm sprzedazowy.</small></label>
                </div>
              </div>
              <div class="brief-field">
                <label class="brief-check-card"><input type="checkbox" id="ui_sound_fx" name="ui_sound_fx" value="tak"><span>Dodac efekty dzwiekowe</span></label>
                <div class="brief-helper">Np. dzwonek, otwarcie drzwi, subtelny akcent miejsca.</div>
              </div>
              <div class="brief-field">
                <label for="ui_references">Referencje</label>
                <textarea id="ui_references" name="ui_references" placeholder="np. podobne do reklamy X, ale bardziej lokalnie i mniej krzykliwie"></textarea>
                <div class="brief-helper">Jesli masz przyklad klimatu, opisz go wlasnymi slowami.</div>
              </div>
            </div>
          </section>

          <section class="brief-step-panel" data-brief-panel="5">
            <div class="brief-form-header">
              <span>Krok 5 z 6</span>
              <h2>Dane techniczne</h2>
              <p>Ustal dlugosc spotu, termin i dodatkowe uwagi.</p>
            </div>
            <div class="brief-form-grid">
              <div class="brief-field brief-field--full" data-required-group="spot_length_choice" data-required-label="dlugosc spotu">
                <span class="brief-field-label">Dlugosc spotu <span class="brief-required">Wymagane</span></span>
                <div class="brief-radio-grid brief-radio-grid--3">
                  <?php foreach ($spotLengthOptions as $length): ?>
                    <label class="brief-radio-card">
                      <input type="radio" name="spot_length_choice" value="<?= $length ?>" <?= $initialSpotLengthChoice === (string)$length ? 'checked' : '' ?>>
                      <span class="brief-radio-icon"><?= $length ?></span>
                      <strong><?= $length ?> sek.</strong>
                      <small><?= $length === 15 ? 'Krotki komunikat.' : ($length === 20 ? 'Standardowy wariant.' : 'Wiecej miejsca na oferte.') ?></small>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
              <div class="brief-field">
                <span class="brief-field-label">Liczba glosow</span>
                <div class="brief-radio-grid brief-radio-grid--3">
                  <label class="brief-radio-card"><input type="radio" name="ui_voice_count" value="1" <?= $currentLectorCount === 1 ? 'checked' : '' ?>><span class="brief-radio-icon">1</span><strong>1 glos</strong><small>Najprostszy spot.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="ui_voice_count" value="2" <?= $currentLectorCount === 2 ? 'checked' : '' ?>><span class="brief-radio-icon">2</span><strong>2 glosy</strong><small>Dialog lub kontrast.</small></label>
                  <label class="brief-radio-card"><input type="radio" name="ui_voice_count" value="" <?= $currentLectorCount === null ? 'checked' : '' ?>><span class="brief-radio-icon">?</span><strong>Bez preferencji</strong><small>Produkcja dobierze wariant.</small></label>
                </div>
              </div>
              <div class="brief-field">
                <label for="ui_deadline">Termin realizacji</label>
                <input type="date" id="ui_deadline" name="ui_deadline">
                <div class="brief-helper">Jesli termin jest pilny, podaj najpozniejsza akceptowalna date.</div>
              </div>
              <div class="brief-field brief-field--full">
                <label for="ui_notes">Uwagi dodatkowe</label>
                <textarea id="ui_notes" name="ui_notes" placeholder="np. unikac porownan do konkurencji, nie uzywac slowa najtanszy"><?= htmlspecialchars((string)($brief['notes'] ?? '')) ?></textarea>
                <div class="brief-helper">Ograniczenia formalne, preferencje albo informacje dla produkcji.</div>
              </div>
            </div>
          </section>

          <section class="brief-step-panel" data-brief-panel="6">
            <div class="brief-form-header">
              <span>Krok 6 z 6</span>
              <h2>Podsumowanie</h2>
              <p>Sprawdz odpowiedzi przed zapisaniem briefu i przekazaniem go do realizacji.</p>
            </div>
            <div id="briefReview" class="brief-summary-grid"></div>
          </section>
        </form>
      <?php endif; ?>
    </section>

    <?php if (!$showSummary): ?>
      <footer class="brief-actionbar">
        <a class="brief-btn brief-btn-link" href="<?= htmlspecialchars($briefReturnUrl) ?>">Anuluj brief</a>
        <div class="brief-actionbar__right">
          <span class="brief-muted" data-brief-action-status>Szkic</span>
          <button class="brief-btn brief-btn-secondary" type="button" data-brief-prev>Wstecz</button>
          <button class="brief-btn brief-btn-secondary" type="button" data-brief-draft>Zapisz szkic</button>
          <button class="brief-btn brief-btn-primary" type="button" data-brief-next>Dalej -&gt;</button>
        </div>
      </footer>
    <?php endif; ?>
  </main>
  <script src="<?= htmlspecialchars($briefPublicBaseUrl) ?>/assets/js/brief-modern.js?v=<?= htmlspecialchars($briefModernVersion) ?>"></script>
</body>
</html>
<?php exit; ?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= htmlspecialchars($briefPublicBaseUrl) ?>/assets/css/brief-public.css?v=<?= $briefAssetVersion ?>">
</head>
<body class="brief-page">
  <!-- BRIEF_MODERN_UI_ACTIVE public/brief.php -->
  <main class="brief-shell brief-layout">
    <section class="brief-hero">
      <div class="brief-hero__eyebrow">Brief kreatywny kampanii</div>
      <div class="brief-hero__grid">
        <div>
          <h1><?= $showSummary ? 'Mamy Twój brief' : 'Opowiedz nam o swojej reklamie' ?></h1>
          <p class="brief-hero__lead">
            <?= $showSummary
                ? 'Dziękujemy. Brief jest już po naszej stronie i może przejść do kolejnego etapu realizacji.'
                : 'Formularz został ułożony tak, żeby łatwo opisać pomysł, przekaz i klimat reklamy bez technicznego języka.' ?>
          </p>
          <?php if (!$showSummary): ?>
            <div class="brief-hero__hint">Wypełnienie zajmuje zwykle 2-3 minuty. Najważniejsze są konkret, kontekst i dane, które mają wybrzmieć w spocie.</div>
          <?php endif; ?>
        </div>
        <div class="brief-hero__meta brief-sidebar">
          <div class="brief-meta-card">
            <span class="brief-meta-card__label">Kampania</span>
            <strong><?= htmlspecialchars((string)($campaign['klient_nazwa'] ?? '')) ?></strong>
          </div>
          <div class="brief-meta-card">
            <span class="brief-meta-card__label">Termin kampanii</span>
            <strong><?= htmlspecialchars($campaignDateLabel !== '' ? $campaignDateLabel : 'Do ustalenia') ?></strong>
          </div>
          <div class="brief-meta-card">
            <span class="brief-meta-card__label">Status briefu</span>
            <span class="<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span>
          </div>
        </div>
      </div>
      <?php if (!$showSummary): ?>
        <div class="brief-progress" aria-label="Sekcje briefu">
          <?php foreach ($formSections as $section): ?>
            <a href="#<?= htmlspecialchars($section['id']) ?>" class="brief-progress__item">
              <span class="brief-progress__step"><?= htmlspecialchars($section['step']) ?></span>
              <span><?= htmlspecialchars($section['title']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($errors): ?>
      <div class="alert alert-danger brief-alert" role="alert">
        <strong>Żeby wysłać brief, uzupełnij brakujące informacje.</strong>
        <ul class="brief-alert__list">
          <?php foreach ($errors as $error): ?>
            <li><?= htmlspecialchars($error) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
      <div class="alert alert-success brief-alert" role="status"><?= htmlspecialchars($successMessage) ?></div>
    <?php endif; ?>

    <?php if ($showSummary): ?>
      <?php if ($successMessage === ''): ?>
        <div class="alert alert-info brief-alert" role="status">
          Ten brief został już wcześniej wysłany. Poniżej widzisz skrót informacji, które trafiły do realizacji.
        </div>
      <?php endif; ?>

      <section class="brief-summary">
        <div class="brief-summary__header">
          <div>
            <span class="brief-summary__eyebrow">Potwierdzenie</span>
            <h2>Brief został przyjęty</h2>
            <p>
              <?= $submittedAtLabel !== ''
                  ? 'Zapisaliśmy go ' . htmlspecialchars($submittedAtLabel) . ' i przekazaliśmy dalej do realizacji.'
                  : 'Zapisaliśmy go i przekazaliśmy dalej do realizacji.' ?>
            </p>
          </div>
          <div class="brief-summary__note">
            To jest wersja robocza, na której pracuje zespół. Jeśli będzie trzeba coś doprecyzować, wrócimy z krótką informacją.
          </div>
        </div>

        <div class="brief-summary__grid">
          <?php foreach ($summarySections as $section): ?>
            <section class="brief-summary-card">
              <h3><?= htmlspecialchars($section['title']) ?></h3>
              <?php foreach ($section['items'] as $item): ?>
                <div class="brief-summary-card__item">
                  <div class="brief-summary-card__label"><?= htmlspecialchars($item['label']) ?></div>
                  <div class="brief-summary-card__value"><?= nl2br(htmlspecialchars((string)$item['value'])) ?></div>
                </div>
              <?php endforeach; ?>
            </section>
          <?php endforeach; ?>
        </div>

        <section class="brief-next-steps">
          <span class="brief-summary__eyebrow">Co dzieje się dalej</span>
          <div class="brief-next-steps__grid">
            <?php foreach ($nextSteps as $index => $stepText): ?>
              <div class="brief-next-step">
                <div class="brief-next-step__number"><?= $index + 1 ?></div>
                <div class="brief-next-step__text"><?= htmlspecialchars($stepText) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </section>
      </section>
    <?php else: ?>
      <form method="post" id="creative-brief-form" class="brief-form brief-step-form brief-main-card" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
        <input type="hidden" name="spot_length_seconds" id="spot_length_seconds" value="<?= htmlspecialchars($initialSpotLengthValue) ?>">
        <input type="hidden" name="additional_info" id="additional_info" value="<?= htmlspecialchars((string)($brief['additional_info'] ?? '')) ?>">
        <input type="hidden" name="tone_style" id="tone_style" value="<?= htmlspecialchars((string)($brief['tone_style'] ?? '')) ?>">
        <input type="hidden" name="sound_effects" id="sound_effects" value="<?= htmlspecialchars((string)($brief['sound_effects'] ?? '')) ?>">
        <input type="hidden" name="notes" id="notes" value="<?= htmlspecialchars((string)($brief['notes'] ?? '')) ?>">
        <input type="hidden" name="lector_count" id="lector_count" value="<?= htmlspecialchars($currentLectorCount !== null ? (string)$currentLectorCount : '') ?>">
        <div id="brief-form-errors" class="alert alert-danger brief-alert d-none" role="alert" aria-live="polite"></div>
        <div class="brief-stepbar brief-stepper" aria-label="Postep briefu"><div class="brief-stepbar__meta"><span id="briefStepCounter">1/5</span><strong id="briefStepTitle">Podstawy</strong></div><div class="brief-stepbar__track"><span id="briefStepFill"></span></div></div>

        <section class="brief-section brief-step-panel is-active" id="brief-core" data-step-panel data-step-title="Podstawy">
          <div class="brief-section__header"><div class="brief-step">1</div><div><h2>Podstawy</h2><p>Opisz firme, cel reklamy i odbiorce, do ktorego mowimy.</p></div></div>
          <div class="brief-subsection--split">
            <div class="brief-field"><label class="form-label" for="ui_company_name">Nazwa firmy</label><input class="form-control" id="ui_company_name" name="ui_company_name" value="<?= htmlspecialchars((string)($campaign['klient_nazwa'] ?? '')) ?>"><div class="brief-helper">Wpisz nazwe, ktora klient ma rozpoznac w komunikacie.</div></div>
            <div class="brief-field"><label class="form-label" for="ui_industry">Branza</label><input class="form-control" id="ui_industry" name="ui_industry" placeholder="np. gastronomia, motoryzacja, medycyna"><div class="brief-helper">Jedno lub dwa slowa wystarcza, produkcja dopasuje jezyk.</div></div>
            <div class="brief-field"><label class="form-label" for="ui_ad_goal">Cel reklamy <span class="brief-required">Wymagane</span></label><select class="form-select" id="ui_ad_goal" name="ui_ad_goal" required data-ux-validate="1" data-step-required="1" data-label="cel reklamy" data-required-message="Wybierz cel reklamy."><option value="">Wybierz cel</option><option value="sprzedaz">Sprzedaz</option><option value="wizerunek">Wizerunek</option><option value="promocja wydarzenia">Promocja wydarzenia</option><option value="informacja">Informacja</option></select><div class="brief-helper">Cel podpowie, czy spot ma sprzedawac, budowac zaufanie czy informowac.</div><div class="invalid-feedback"></div></div>
            <div class="brief-field"><label class="form-label" for="target_group">Grupa docelowa</label><textarea class="form-control" id="target_group" name="target_group" rows="4" placeholder="np. mieszkancy Zulaw, rodzice dzieci w wieku szkolnym, wlasciciele firm"><?= htmlspecialchars((string)($brief['target_group'] ?? '')) ?></textarea><div class="brief-helper">Opisz jednym zdaniem, do kogo ma trafic reklama.</div></div>
          </div>
        </section>
        <section class="brief-section brief-step-panel" id="brief-message" data-step-panel data-step-title="Tresc spotu">
          <div class="brief-section__header"><div class="brief-step">2</div><div><h2>Tresc spotu</h2><p>Zapisz najwazniejsze informacje, haslo i konkretna akcje dla odbiorcy.</p></div></div>
          <div class="brief-subsection--split">
            <div class="brief-field brief-field--wide"><label class="form-label" for="main_message">Co ma byc powiedziane? <span class="brief-required">Wymagane</span></label><textarea class="form-control" id="main_message" name="main_message" rows="5" required data-ux-validate="1" data-step-required="1" data-label="tresc spotu" data-required-message="Opisz, co ma byc powiedziane w spocie." placeholder="np. Startuje promocja weekendowa, rabat -20% i zaproszenie do sklepu"><?= htmlspecialchars((string)($brief['main_message'] ?? '')) ?></textarea><div class="brief-helper">Opisz jednym zdaniem co klient ma zapamietac.</div><div class="invalid-feedback"></div></div>
            <div class="brief-field"><label class="form-label" for="ui_slogan">Kluczowe haslo reklamowe</label><input class="form-control" id="ui_slogan" name="ui_slogan" placeholder="np. Blisko, szybko, lokalnie"><div class="brief-helper">Jesli masz gotowe haslo, wpisz je tutaj. Jesli nie, zostaw puste.</div></div>
            <div class="brief-field"><label class="form-label" for="contact_details">Call to action / kontakt <span class="brief-required">Wymagane</span></label><textarea class="form-control" id="contact_details" name="contact_details" rows="4" required data-ux-validate="1" data-step-required="1" data-label="call to action" data-required-message="Wpisz wezwanie do dzialania lub dane kontaktowe." placeholder="np. Zadzwon pod 123 456 789 albo odwiedz www.twojafirma.pl"><?= htmlspecialchars((string)($brief['contact_details'] ?? '')) ?></textarea><div class="brief-helper">Napisz, co odbiorca ma zrobic po uslyszeniu reklamy.</div><div class="invalid-feedback"></div></div>
          </div>
        </section>
        <section class="brief-section brief-step-panel" id="brief-style" data-step-panel data-step-title="Styl i klimat">
          <div class="brief-section__header"><div class="brief-step">3</div><div><h2>Styl i klimat</h2><p>Wybierz brzmienie, glos i tempo spotu.</p></div></div>
          <div class="brief-subsection--split"><div class="brief-field"><label class="form-label">Styl spotu</label><div class="option-cards option-cards--compact"><label class="option-card brief-radio-card"><input type="radio" name="ui_style" value="dynamiczny"><span class="option-card__content"><strong>Dynamiczny</strong></span></label><label class="option-card brief-radio-card"><input type="radio" name="ui_style" value="spokojny"><span class="option-card__content"><strong>Spokojny</strong></span></label><label class="option-card brief-radio-card"><input type="radio" name="ui_style" value="humorystyczny"><span class="option-card__content"><strong>Humorystyczny</strong></span></label><label class="option-card brief-radio-card"><input type="radio" name="ui_style" value="premium"><span class="option-card__content"><strong>Premium</strong></span></label></div><div class="brief-helper">To sugestia dla produkcji, nie sztywna instrukcja.</div></div><div class="brief-field"><label class="form-label">Glos</label><div class="chip-group"><label class="chip-toggle"><input type="radio" name="ui_voice" value="meski"><span>Meski</span></label><label class="chip-toggle"><input type="radio" name="ui_voice" value="zenski"><span>Zenski</span></label><label class="chip-toggle"><input type="radio" name="ui_voice" value="bez znaczenia"><span>Bez znaczenia</span></label></div><div class="brief-helper">Jesli nie masz preferencji, wybierz bez znaczenia.</div></div><div class="brief-field"><label class="form-label">Tempo</label><div class="chip-group"><label class="chip-toggle"><input type="radio" name="ui_tempo" value="wolne"><span>Wolne</span></label><label class="chip-toggle"><input type="radio" name="ui_tempo" value="srednie"><span>Srednie</span></label><label class="chip-toggle"><input type="radio" name="ui_tempo" value="szybkie"><span>Szybkie</span></label></div><div class="brief-helper">Tempo pomaga dobrac rytm lektora i montazu.</div></div></div>
        </section>
        <section class="brief-section brief-step-panel" id="brief-addons" data-step-panel data-step-title="Dodatki"><div class="brief-section__header"><div class="brief-step">4</div><div><h2>Dodatki</h2><p>Doprecyzuj muzyke, efekty i referencje.</p></div></div><div id="briefMusicHint" class="brief-suggestion d-none" role="status"></div><div class="brief-subsection--split"><div class="brief-field"><label class="form-label">Muzyka</label><div class="option-cards option-cards--compact"><label class="option-card brief-radio-card"><input type="radio" name="ui_music" value="brak"><span class="option-card__content"><strong>Brak</strong></span></label><label class="option-card brief-radio-card"><input type="radio" name="ui_music" value="spokojna"><span class="option-card__content"><strong>Spokojna</strong></span></label><label class="option-card brief-radio-card"><input type="radio" name="ui_music" value="dynamiczna"><span class="option-card__content"><strong>Dynamiczna</strong></span></label></div><div class="brief-helper">Muzyka ma wspierac przekaz, nie przykrywac tresci.</div></div><div class="brief-field"><label class="chip-toggle brief-checkbox"><input type="checkbox" id="ui_sound_fx" name="ui_sound_fx" value="tak"><span>Dodac efekty dzwiekowe</span></label><div class="brief-helper">Np. dzwonek, otwarcie drzwi, subtelny akcent lub efekt miejsca.</div></div><div class="brief-field brief-field--wide"><label class="form-label" for="ui_references">Referencje</label><textarea class="form-control" id="ui_references" name="ui_references" rows="4" placeholder="np. podobne do reklamy X, ale bardziej lokalnie i mniej krzykliwie"></textarea><div class="brief-helper">Jesli masz przyklad klimatu, opisz go wlasnymi slowami.</div></div></div></section>
        <section class="brief-section brief-step-panel" id="brief-technical" data-step-panel data-step-title="Dane techniczne"><div class="brief-section__header"><div class="brief-step">5</div><div><h2>Dane techniczne</h2><p>Ustal dlugosc spotu, termin i dodatkowe uwagi.</p></div></div><div class="brief-subsection--split"><div class="brief-field" data-spot-length-group><label class="form-label">Dlugosc spotu <span class="brief-required">Wymagane</span></label><div class="option-cards option-cards--compact" role="radiogroup" aria-label="Dlugosc spotu"><?php foreach ($spotLengthOptions as $length): ?><label class="option-card brief-radio-card"><input type="radio" name="spot_length_choice" value="<?= $length ?>" <?= $initialSpotLengthChoice === (string)$length ? 'checked' : '' ?> data-spot-length-radio><span class="option-card__content"><strong><?= $length ?> sek.</strong></span></label><?php endforeach; ?></div><div class="brief-helper">Najczesciej wybierane warianty to 15, 20 albo 30 sekund.</div><div class="invalid-feedback"></div></div><div class="brief-field"><label class="form-label" for="ui_deadline">Termin realizacji</label><input class="form-control" id="ui_deadline" name="ui_deadline" type="date"><div class="brief-helper">Jesli termin jest pilny, wpisz najpozniejsza akceptowalna date.</div></div><div class="brief-field brief-field--wide"><label class="form-label" for="ui_notes">Uwagi dodatkowe</label><textarea class="form-control" id="ui_notes" name="ui_notes" rows="4" placeholder="np. unikac porownan do konkurencji, nie uzywac slowa najtanszy"><?= htmlspecialchars((string)($brief['notes'] ?? '')) ?></textarea><div class="brief-helper">Zapisz ograniczenia formalne, preferencje albo informacje dla produkcji.</div></div></div></section>
        <section class="brief-section brief-step-panel" id="brief-summary-step" data-step-panel data-step-title="Podsumowanie"><div class="brief-section__header"><div class="brief-step">✓</div><div><h2>Podsumowanie</h2><p>Sprawdz odpowiedzi przed przekazaniem briefu do realizacji.</p></div></div><div id="briefReview" class="brief-review-grid"></div><section class="brief-submit-card"><div><span class="brief-submit-card__eyebrow">Gotowe</span><h2>Zapisz brief</h2><p>Po zapisaniu brief trafi do CRM i produkcji.</p></div><button class="btn btn-primary btn-lg brief-submit-button" type="submit">Zapisz brief</button></section></section>
        <div class="brief-step-actions"><button class="btn btn-outline-secondary" type="button" data-brief-prev>Wstecz</button><button class="btn btn-primary" type="button" data-brief-next>Dalej</button></div>
      </form>
    <?php endif; ?>
  </main>

  <script>
    (function () {
      const form = document.getElementById('creative-brief-form');
      if (!form) return;

      const panels = Array.from(form.querySelectorAll('[data-step-panel]'));
      const totalRealSteps = 5;
      const fields = Array.from(form.querySelectorAll('[data-ux-validate]'));
      const formErrors = document.getElementById('brief-form-errors');
      const prevButton = form.querySelector('[data-brief-prev]');
      const nextButton = form.querySelector('[data-brief-next]');
      const counter = document.getElementById('briefStepCounter');
      const title = document.getElementById('briefStepTitle');
      const fill = document.getElementById('briefStepFill');
      const review = document.getElementById('briefReview');
      const spotLengthHidden = document.getElementById('spot_length_seconds');
      const additionalInfo = document.getElementById('additional_info');
      const toneStyle = document.getElementById('tone_style');
      const soundEffects = document.getElementById('sound_effects');
      const notes = document.getElementById('notes');
      const musicHint = document.getElementById('briefMusicHint');
      let currentStep = 0;

      function valueOf(selector) {
        const el = form.querySelector(selector);
        return el ? (el.value || '').trim() : '';
      }
      function checkedValue(name) {
        const el = form.querySelector('[name="' + name + '"]:checked');
        return el ? el.value : '';
      }
      function isSummaryStep() {
        return currentStep === panels.length - 1;
      }
      function showError(message) {
        if (!formErrors) return;
        formErrors.textContent = message;
        formErrors.classList.remove('d-none');
      }
      function clearError() {
        if (!formErrors) return;
        formErrors.textContent = '';
        formErrors.classList.add('d-none');
      }
      function validateField(field) {
        const feedback = field.closest('.brief-field')?.querySelector('.invalid-feedback');
        const message = field.checkValidity() ? '' : (field.dataset.requiredMessage || 'To pole jest wymagane.');
        field.classList.toggle('is-invalid', message !== '');
        if (feedback) feedback.textContent = message;
        return message;
      }
      function syncSpotLength() {
        const selected = form.querySelector('[name="spot_length_choice"]:checked');
        const value = selected ? selected.value : '';
        if (spotLengthHidden) spotLengthHidden.value = value;
        return value;
      }
      function validateSpotLength() {
        const group = form.querySelector('[data-spot-length-group]');
        const feedback = group ? group.querySelector('.invalid-feedback') : null;
        const value = syncSpotLength();
        const message = value ? '' : 'Wybierz dlugosc spotu.';
        if (group) group.classList.toggle('is-invalid', message !== '');
        if (feedback) feedback.textContent = message;
        return message;
      }
      function validateCurrentStep() {
        clearError();
        const active = panels[currentStep];
        const messages = [];
        active.querySelectorAll('[data-step-required]').forEach((field) => {
          if (validateField(field) !== '') messages.push(field.dataset.label || field.name);
        });
        if (active.querySelector('[data-spot-length-group]') && validateSpotLength() !== '') messages.push('dlugosc spotu');
        if (messages.length) {
          showError('Uzupelnij: ' + messages.join(', ') + '.');
          return false;
        }
        return true;
      }
      function syncBackendFields() {
        const addParts = [];
        [['Firma', valueOf('#ui_company_name')], ['Branza', valueOf('#ui_industry')], ['Cel reklamy', valueOf('#ui_ad_goal')], ['Haslo', valueOf('#ui_slogan')]].forEach(([label, value]) => {
          if (value) addParts.push(label + ': ' + value);
        });
        if (additionalInfo) additionalInfo.value = addParts.join("\n");

        const toneParts = [];
        [['Styl', checkedValue('ui_style')], ['Glos', checkedValue('ui_voice')], ['Tempo', checkedValue('ui_tempo')]].forEach(([label, value]) => {
          if (value) toneParts.push(label + ': ' + value);
        });
        if (toneStyle) toneStyle.value = toneParts.join("\n");

        const soundParts = [];
        const music = checkedValue('ui_music');
        if (music) soundParts.push('Muzyka: ' + music);
        if (form.querySelector('#ui_sound_fx')?.checked) soundParts.push('Efekty dzwiekowe: tak');
        const refs = valueOf('#ui_references');
        if (refs) soundParts.push('Referencje: ' + refs);
        if (soundEffects) soundEffects.value = soundParts.join("\n");

        const noteParts = [];
        const deadline = valueOf('#ui_deadline');
        if (deadline) noteParts.push('Termin realizacji: ' + deadline);
        const extraNotes = valueOf('#ui_notes');
        if (extraNotes) noteParts.push(extraNotes);
        if (notes) notes.value = noteParts.join("\n");
        syncSpotLength();
      }
      function setMusicSuggestion() {
        const style = checkedValue('ui_style');
        if (!musicHint) return;
        let text = '';
        if (style === 'humorystyczny') text = 'Sugestia: przy humorystycznym stylu zwykle dobrze dziala dynamiczna muzyka.';
        if (style === 'premium') text = 'Sugestia: przy stylu premium zwykle lepiej sprawdza sie spokojna muzyka.';
        musicHint.textContent = text;
        musicHint.classList.toggle('d-none', text === '');
      }
      function buildReview() {
        syncBackendFields();
        if (!review) return;
        const rows = [
          ['Nazwa firmy', valueOf('#ui_company_name')],
          ['Branza', valueOf('#ui_industry')],
          ['Cel reklamy', valueOf('#ui_ad_goal')],
          ['Grupa docelowa', valueOf('#target_group')],
          ['Tresc spotu', valueOf('#main_message')],
          ['Haslo', valueOf('#ui_slogan')],
          ['Call to action', valueOf('#contact_details')],
          ['Styl', checkedValue('ui_style')],
          ['Glos', checkedValue('ui_voice')],
          ['Tempo', checkedValue('ui_tempo')],
          ['Muzyka', checkedValue('ui_music')],
          ['Efekty', form.querySelector('#ui_sound_fx')?.checked ? 'tak' : 'nie'],
          ['Referencje', valueOf('#ui_references')],
          ['Dlugosc', syncSpotLength() ? syncSpotLength() + ' sek.' : ''],
          ['Termin', valueOf('#ui_deadline')],
          ['Uwagi', valueOf('#ui_notes')]
        ];
        review.innerHTML = rows.map(([label, value]) => '<div class="brief-review-item"><span>' + label + '</span><strong>' + (value || 'Nie podano') + '</strong></div>').join('');
      }
      function showStep(index) {
        currentStep = Math.max(0, Math.min(index, panels.length - 1));
        panels.forEach((panel, idx) => panel.classList.toggle('is-active', idx === currentStep));
        const stepNo = Math.min(currentStep + 1, totalRealSteps);
        if (counter) counter.textContent = isSummaryStep() ? 'Podsumowanie' : stepNo + '/' + totalRealSteps;
        if (title) title.textContent = panels[currentStep].dataset.stepTitle || '';
        if (fill) fill.style.width = (isSummaryStep() ? 100 : (stepNo / totalRealSteps) * 100) + '%';
        if (prevButton) prevButton.disabled = currentStep === 0;
        if (nextButton) nextButton.textContent = isSummaryStep() ? 'Zapisz brief' : 'Dalej';
        if (isSummaryStep()) buildReview();
        panels[currentStep].scrollIntoView({ behavior: 'smooth', block: 'start' });
      }

      fields.forEach((field) => {
        field.addEventListener('input', () => { if (field.classList.contains('is-invalid')) validateField(field); });
        field.addEventListener('change', () => validateField(field));
      });
      form.querySelectorAll('[name="spot_length_choice"]').forEach((radio) => radio.addEventListener('change', validateSpotLength));
      form.querySelectorAll('[name="ui_style"]').forEach((radio) => radio.addEventListener('change', setMusicSuggestion));
      prevButton?.addEventListener('click', () => showStep(currentStep - 1));
      nextButton?.addEventListener('click', () => {
        if (isSummaryStep()) {
          form.requestSubmit();
          return;
        }
        if (validateCurrentStep()) showStep(currentStep + 1);
      });
      form.addEventListener('submit', function (event) {
        syncBackendFields();
        const allValid = fields.every((field) => validateField(field) === '') && validateSpotLength() === '';
        if (!allValid) {
          event.preventDefault();
          showError('Uzupelnij wymagane pola: cel reklamy, tresc spotu, call to action i dlugosc spotu.');
          const firstInvalidPanel = panels.findIndex((panel) => panel.querySelector('.is-invalid, [data-spot-length-group].is-invalid'));
          if (firstInvalidPanel >= 0) showStep(firstInvalidPanel);
        }
      });
      syncBackendFields();
      setMusicSuggestion();
      showStep(0);
    })();
  </script>
</body>
</html>
