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

$stmtCampaign = $pdo->prepare('SELECT id, klient_nazwa, data_start, data_koniec, realization_status, owner_user_id FROM kampanie WHERE id = :id LIMIT 1');
$stmtCampaign->execute([':id' => (int)$brief['campaign_id']]);
$campaign = $stmtCampaign->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$campaign) {
    http_response_code(404);
    exit('Nie znaleziono kampanii briefu.');
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
            $successMessage = 'Brief został zapisany i przekazany do realizacji.';
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
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle) ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/bootstrap.min.css">
  <link rel="stylesheet" href="<?= htmlspecialchars(BASE_URL) ?>/assets/css/brief-public.css">
</head>
<body class="brief-page">
  <main class="brief-shell">
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
        <div class="brief-hero__meta">
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
      <form method="post" id="creative-brief-form" class="brief-form" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
        <input type="hidden" name="spot_length_seconds" id="spot_length_seconds" value="<?= htmlspecialchars($initialSpotLengthValue) ?>">
        <input type="hidden" name="tone_style" id="tone_style" value="<?= htmlspecialchars((string)($brief['tone_style'] ?? '')) ?>">
        <input type="hidden" name="sound_effects" id="sound_effects" value="<?= htmlspecialchars((string)($brief['sound_effects'] ?? '')) ?>">

        <div id="brief-form-errors" class="alert alert-danger brief-alert d-none" role="alert" aria-live="polite"></div>

        <section class="brief-section" id="brief-core">
          <div class="brief-section__header">
            <div class="brief-step"><?= htmlspecialchars($formSections[0]['step']) ?></div>
            <div>
              <h2><?= htmlspecialchars($formSections[0]['title']) ?></h2>
              <p><?= htmlspecialchars($formSections[0]['description']) ?></p>
            </div>
          </div>

          <div class="brief-subsection">
            <div class="brief-subsection__header">
              <h3>Podstawy spotu</h3>
              <p>Trzymamy się modelu używanego w CRM: 15, 20 albo 30 sekund. Jeśli masz inną, wcześniej ustaloną długość, możesz ją wpisać ręcznie.</p>
            </div>
            <div class="row g-4 align-items-start">
              <div class="col-lg-8">
                <div class="brief-field" data-spot-length-group>
                  <label class="form-label">Jak długi ma być spot? <span class="brief-required">Wymagane</span></label>
                  <div class="option-cards" role="radiogroup" aria-label="Długość spotu">
                    <?php foreach ($spotLengthOptions as $length): ?>
                      <label class="option-card">
                        <input
                          type="radio"
                          name="spot_length_choice"
                          value="<?= $length ?>"
                          <?= $initialSpotLengthChoice === (string)$length ? 'checked' : '' ?>
                          data-spot-length-radio>
                        <span class="option-card__content">
                          <strong><?= $length ?> sek.</strong>
                          <small><?= $length === 15 ? 'krótszy, szybki komunikat' : ($length === 20 ? 'standard w aktualnym modelu' : 'więcej miejsca na ofertę') ?></small>
                        </span>
                      </label>
                    <?php endforeach; ?>
                    <label class="option-card option-card--custom">
                      <input
                        type="radio"
                        name="spot_length_choice"
                        value="custom"
                        <?= $initialSpotLengthChoice === 'custom' ? 'checked' : '' ?>
                        data-spot-length-radio>
                      <span class="option-card__content">
                        <strong>Inna długość</strong>
                        <small>tylko jeśli była już wcześniej ustalona</small>
                      </span>
                    </label>
                  </div>
                  <div class="brief-custom-inline" id="spot-length-custom-wrap">
                    <label class="form-label" for="spot_length_custom">Wpisz ustaloną długość</label>
                    <div class="input-group">
                      <input
                        class="form-control"
                        id="spot_length_custom"
                        type="number"
                        min="1"
                        inputmode="numeric"
                        placeholder="np. 25"
                        value="<?= htmlspecialchars($customSpotLength !== null ? (string)$customSpotLength : '') ?>">
                      <span class="input-group-text">sek.</span>
                    </div>
                  </div>
                  <div class="brief-helper">Jeśli nie zaznaczysz nic więcej, system zapisze wybraną długość dokładnie tak, jak w istniejącym briefie.</div>
                  <div class="invalid-feedback"></div>
                </div>
              </div>
              <div class="col-lg-4">
                <div class="brief-field">
                  <label class="form-label" for="lector_count">Ilu głosów potrzebujesz?</label>
                  <select class="form-select brief-select" id="lector_count" name="lector_count">
                    <option value="">Bez preferencji</option>
                    <?php foreach ($lectorOptions as $lectorOption): ?>
                      <option value="<?= $lectorOption ?>" <?= $currentLectorCount === $lectorOption ? 'selected' : '' ?>>
                        <?= $lectorOption ?> <?= $lectorOption === 1 ? 'głos' : ($lectorOption < 5 ? 'głosy' : 'głosów') ?>
                      </option>
                    <?php endforeach; ?>
                    <?php if ($hasCustomLectorCount): ?>
                      <option value="<?= htmlspecialchars((string)$currentLectorCount) ?>" selected>
                        <?= htmlspecialchars((string)$currentLectorCount) ?> głosów
                      </option>
                    <?php endif; ?>
                  </select>
                  <div class="brief-helper">Jeśli nie masz preferencji, zostaw tę decyzję po naszej stronie.</div>
                </div>
              </div>
            </div>
          </div>

          <div class="brief-subsection">
            <div class="brief-subsection__header">
              <h3>Odbiorca</h3>
              <p>Im lepiej opiszemy odbiorcę, tym łatwiej będzie dopasować język i rytm komunikatu.</p>
            </div>
            <div class="brief-field">
              <label class="form-label" for="target_group">Do kogo ma trafić ta reklama?</label>
              <textarea class="form-control" id="target_group" name="target_group" rows="4" placeholder="np. właściciele małych firm z Trójmiasta, którzy szukają prostego i szybkiego wsparcia księgowego"><?= htmlspecialchars((string)($brief['target_group'] ?? '')) ?></textarea>
              <div class="brief-helper">Możesz opisać wiek, lokalizację, sytuację klienta albo po prostu napisać, dla kogo jest ta oferta.</div>
            </div>
          </div>
        </section>

        <section class="brief-section" id="brief-message">
          <div class="brief-section__header">
            <div class="brief-step"><?= htmlspecialchars($formSections[1]['step']) ?></div>
            <div>
              <h2><?= htmlspecialchars($formSections[1]['title']) ?></h2>
              <p><?= htmlspecialchars($formSections[1]['description']) ?></p>
            </div>
          </div>

          <div class="brief-subsection">
            <div class="brief-subsection__header">
              <h3>Najważniejszy przekaz</h3>
              <p>To najważniejsza część briefu. Jedna rzecz, która ma zostać z odbiorcą po usłyszeniu reklamy.</p>
            </div>
            <div class="brief-field">
              <label class="form-label" for="main_message">Co odbiorca ma zapamiętać? <span class="brief-required">Wymagane</span></label>
              <textarea
                class="form-control"
                id="main_message"
                name="main_message"
                rows="4"
                placeholder="np. Otwarcie nowego salonu 18 maja i rabat -20% tylko do końca miesiąca"
                required
                data-ux-validate="1"
                data-label="najważniejszy przekaz"
                data-required-message="Opisz najważniejszy przekaz reklamy."><?= htmlspecialchars((string)($brief['main_message'] ?? '')) ?></textarea>
              <div class="brief-helper">Jedna mocna myśl działa lepiej niż lista kilku równorzędnych komunikatów.</div>
              <div class="invalid-feedback"></div>
            </div>
          </div>

          <div class="brief-subsection brief-subsection--split">
            <div class="brief-field">
              <label class="form-label" for="additional_info">Dodatkowe informacje</label>
              <textarea class="form-control" id="additional_info" name="additional_info" rows="4" placeholder="np. zależy nam na podkreśleniu lokalizacji i krótkiego czasu realizacji usługi"><?= htmlspecialchars((string)($brief['additional_info'] ?? '')) ?></textarea>
              <div class="brief-helper">Dodaj tu kontekst, promocję, termin lub cel kampanii.</div>
            </div>
            <div class="brief-field">
              <label class="form-label" for="contact_details">Jakie dane mają paść w spocie? <span class="brief-required">Wymagane</span></label>
              <textarea
                class="form-control"
                id="contact_details"
                name="contact_details"
                rows="4"
                placeholder="np. www.twojafirma.pl, ul. Morska 12 w Gdańsku, tel. 123 456 789"
                required
                data-ux-validate="1"
                data-label="dane do użycia w spocie"
                data-required-message="Wpisz dane do podania w spocie."><?= htmlspecialchars((string)($brief['contact_details'] ?? '')) ?></textarea>
              <div class="brief-helper">Wpisz tylko to, co naprawdę ma zostać odczytane albo wybrzmieć w reklamie.</div>
              <div class="invalid-feedback"></div>
            </div>
          </div>
        </section>

        <section class="brief-section" id="brief-style">
          <div class="brief-section__header">
            <div class="brief-step"><?= htmlspecialchars($formSections[2]['step']) ?></div>
            <div>
              <h2><?= htmlspecialchars($formSections[2]['title']) ?></h2>
              <p><?= htmlspecialchars($formSections[2]['description']) ?></p>
            </div>
          </div>

          <div class="brief-subsection brief-subsection--split">
            <div class="brief-field brief-composer" data-compose-field="tone_style">
              <label class="form-label" for="tone_style_custom">Styl reklamy</label>
              <div class="chip-group" aria-label="Styl reklamy">
                <?php foreach ($tonePresets as $preset): ?>
                  <label class="chip-toggle">
                    <input type="checkbox" value="<?= htmlspecialchars($preset) ?>" data-compose-option="tone_style">
                    <span><?= htmlspecialchars($preset) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <textarea
                class="form-control"
                id="tone_style_custom"
                rows="4"
                placeholder="Doprecyzuj własnymi słowami, np. ma być energetycznie, ale bez krzykliwego tonu i z bardziej premium niż promocyjnym charakterem."
                data-compose-text="tone_style"><?= htmlspecialchars((string)($brief['tone_style'] ?? '')) ?></textarea>
              <div class="brief-helper">Kliknij gotowe kierunki albo dopisz własne wskazówki. Zapiszemy to jako jedno pole briefu.</div>
            </div>

            <div class="brief-field brief-composer" data-compose-field="sound_effects">
              <label class="form-label" for="sound_effects_custom">Dźwięk i klimat</label>
              <div class="chip-group" aria-label="Dźwięk i klimat">
                <?php foreach ($soundPresets as $preset): ?>
                  <label class="chip-toggle">
                    <input type="checkbox" value="<?= htmlspecialchars($preset) ?>" data-compose-option="sound_effects">
                    <span><?= htmlspecialchars($preset) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <textarea
                class="form-control"
                id="sound_effects_custom"
                rows="4"
                placeholder="Doprecyzuj, np. subtelne wejście z muzyką w tle, bez mocnych efektów i bez lektorskiego przerysowania."
                data-compose-text="sound_effects"><?= htmlspecialchars((string)($brief['sound_effects'] ?? '')) ?></textarea>
              <div class="brief-helper">Możesz wybrać gotowe sugestie i dodać własny opis klimatu lub efektów.</div>
            </div>
          </div>
        </section>

        <section class="brief-section" id="brief-finish">
          <div class="brief-section__header">
            <div class="brief-step"><?= htmlspecialchars($formSections[3]['step']) ?></div>
            <div>
              <h2><?= htmlspecialchars($formSections[3]['title']) ?></h2>
              <p><?= htmlspecialchars($formSections[3]['description']) ?></p>
            </div>
          </div>

          <div class="brief-subsection brief-subsection--split">
            <div class="brief-field">
              <label class="form-label" for="notes">Uwagi</label>
              <textarea class="form-control" id="notes" name="notes" rows="4" placeholder="np. prosimy nie używać porównań do konkurencji i unikać sformułowania 'najtańszy'"><?= htmlspecialchars((string)($brief['notes'] ?? '')) ?></textarea>
              <div class="brief-helper">To dobre miejsce na ograniczenia formalne, ważne zastrzeżenia albo dodatkowe prośby.</div>
            </div>

            <div class="brief-review-card">
              <span class="brief-submit-card__eyebrow">Przed wysłaniem</span>
              <h3>Krótki check</h3>
              <ul class="brief-checklist">
                <li>Czy najważniejszy przekaz jest jeden i konkretny?</li>
                <li>Czy wpisane są dane, które mają paść w spocie?</li>
                <li>Czy klimat reklamy jest opisany na tyle, żeby ruszyć z realizacją?</li>
              </ul>
              <p>Po wysłaniu pokażemy skrót briefu i informację, co dzieje się dalej.</p>
            </div>
          </div>

          <section class="brief-submit-card">
            <div>
              <span class="brief-submit-card__eyebrow">Gotowe do wysłania</span>
              <h2>Wyślij brief do realizacji</h2>
              <p>Nie zmieniamy procesu po stronie CRM. Zmieniamy tylko to, jak wygodnie możesz przekazać nam komplet informacji.</p>
            </div>
            <button class="btn btn-primary btn-lg brief-submit-button" type="submit">Przekaż brief</button>
          </section>
        </section>
      </form>
    <?php endif; ?>
  </main>

  <script>
    (function () {
      const form = document.getElementById('creative-brief-form');
      if (!form) {
        return;
      }

      const fields = Array.from(form.querySelectorAll('[data-ux-validate]'));
      const formErrors = document.getElementById('brief-form-errors');
      const spotLengthHidden = document.getElementById('spot_length_seconds');
      const spotLengthCustom = document.getElementById('spot_length_custom');
      const spotLengthCustomWrap = document.getElementById('spot-length-custom-wrap');
      const spotLengthRadios = Array.from(form.querySelectorAll('[data-spot-length-radio]'));

      function getSelectedSpotLengthChoice() {
        const selected = spotLengthRadios.find((radio) => radio.checked);
        return selected ? selected.value : '';
      }

      function syncSpotLength() {
        const choice = getSelectedSpotLengthChoice();
        const customActive = choice === 'custom';
        if (spotLengthCustomWrap) {
          spotLengthCustomWrap.classList.toggle('is-visible', customActive);
        }
        if (spotLengthCustom) {
          spotLengthCustom.disabled = !customActive;
        }
        if (!spotLengthHidden) {
          return '';
        }

        if (customActive) {
          spotLengthHidden.value = spotLengthCustom ? spotLengthCustom.value.trim() : '';
          return spotLengthHidden.value;
        }

        spotLengthHidden.value = choice;
        return choice;
      }

      function validateSpotLength() {
        const group = form.querySelector('[data-spot-length-group]');
        const feedback = group ? group.querySelector('.invalid-feedback') : null;
        const value = syncSpotLength();
        let message = '';

        if (value === '') {
          message = 'Wybierz długość spotu.';
        } else if (!/^\d+$/.test(value) || Number(value) <= 0) {
          message = 'Wpisz poprawną długość spotu.';
        }

        if (group) {
          group.classList.toggle('is-invalid', message !== '');
        }
        if (feedback) {
          feedback.textContent = message;
        }

        return message;
      }

      function getFieldMessage(field) {
        if (field.validity.valueMissing) {
          return field.dataset.requiredMessage || 'To pole jest wymagane.';
        }
        return '';
      }

      function validateField(field) {
        const feedback = field.closest('.brief-field')?.querySelector('.invalid-feedback');
        const message = field.checkValidity() ? '' : getFieldMessage(field);

        field.classList.toggle('is-invalid', message !== '');
        if (feedback) {
          feedback.textContent = message;
        }

        return message;
      }

      function syncCompositeField(key) {
        const hiddenField = document.getElementById(key);
        const textField = form.querySelector('[data-compose-text="' + key + '"]');
        const selected = Array.from(form.querySelectorAll('[data-compose-option="' + key + '"]:checked'))
          .map((input) => input.value.trim())
          .filter(Boolean);
        const customText = textField ? textField.value.trim() : '';

        if (!hiddenField) {
          return;
        }

        const parts = [];
        if (selected.length) {
          parts.push(selected.join(', '));
        }
        if (customText) {
          parts.push(customText);
        }
        hiddenField.value = parts.join("\n\n");
      }

      ['tone_style', 'sound_effects'].forEach((key) => {
        const textField = form.querySelector('[data-compose-text="' + key + '"]');
        const options = form.querySelectorAll('[data-compose-option="' + key + '"]');

        if (textField) {
          ['input', 'blur', 'change'].forEach((eventName) => {
            textField.addEventListener(eventName, function () {
              syncCompositeField(key);
            });
          });
        }

        options.forEach((option) => {
          option.addEventListener('change', function () {
            syncCompositeField(key);
          });
        });

        syncCompositeField(key);
      });

      spotLengthRadios.forEach((radio) => {
        radio.addEventListener('change', function () {
          validateSpotLength();
          if (formErrors && !formErrors.classList.contains('d-none')) {
            const stillInvalid = validateSpotLength() !== '' || fields.some((field) => validateField(field) !== '');
            if (!stillInvalid) {
              formErrors.classList.add('d-none');
              formErrors.textContent = '';
            }
          }
        });
      });

      if (spotLengthCustom) {
        ['input', 'blur', 'change'].forEach((eventName) => {
          spotLengthCustom.addEventListener(eventName, function () {
            validateSpotLength();
          });
        });
      }

      fields.forEach((field) => {
        ['blur', 'change'].forEach((eventName) => {
          field.addEventListener(eventName, function () {
            validateField(field);
          });
        });

        field.addEventListener('input', function () {
          if (field.classList.contains('is-invalid')) {
            validateField(field);
          }
        });
      });

      syncSpotLength();

      form.addEventListener('submit', function (event) {
        ['tone_style', 'sound_effects'].forEach(syncCompositeField);

        const invalidMessages = [];
        const spotLengthMessage = validateSpotLength();
        if (spotLengthMessage !== '') {
          invalidMessages.push('długość spotu');
        }

        fields.forEach((field) => {
          if (validateField(field) !== '') {
            invalidMessages.push(field.dataset.label || field.name);
          }
        });

        if (!invalidMessages.length) {
          if (formErrors) {
            formErrors.classList.add('d-none');
            formErrors.textContent = '';
          }
          return;
        }

        event.preventDefault();
        if (formErrors) {
          formErrors.textContent = 'Uzupełnij proszę: ' + invalidMessages.join(', ') + '.';
          formErrors.classList.remove('d-none');
        }

        const firstInvalidField = form.querySelector('.is-invalid, [data-spot-length-group].is-invalid');
        if (firstInvalidField) {
          const focusTarget = firstInvalidField.querySelector('input, textarea, select') || firstInvalidField;
          if (focusTarget && typeof focusTarget.focus === 'function') {
            focusTarget.focus();
          }
        }
      });
    })();
  </script>
</body>
</html>
