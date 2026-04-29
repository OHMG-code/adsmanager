<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/config.php';
$pageTitle = 'Wyślij ofertę';
require_once '../config/config.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/mediaplan_pdf.php';
require_once __DIR__ . '/includes/mail_service.php';
require_once __DIR__ . '/includes/crm_activity.php';
require_once __DIR__ . '/includes/mailbox_service.php';
require_once __DIR__ . '/includes/communication_events.php';
require_once __DIR__ . '/includes/task_automation.php';

requireLogin();

ensureSystemConfigColumns($pdo);
ensureUserColumns($pdo);
ensureCrmMailTables($pdo);
ensureMailHistoryTable($pdo);
ensureLeadColumns($pdo);
ensureLeadActivityTable($pdo);

$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}
$mailAccount = mailboxEnsureMailAccount($pdo, (int)$currentUser['id']);

$kampaniaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$leadId = isset($_GET['lead_id']) ? (int)$_GET['lead_id'] : 0;
if ($kampaniaId <= 0) {
    http_response_code(400);
    echo 'Brak identyfikatora kampanii.';
    exit;
}

$campaignData = null;
$campaignError = null;
try {
    $campaignData = prepareMediaplanData($pdo, $kampaniaId);
} catch (RuntimeException $e) {
    $campaignError = $e->getMessage();
} catch (Throwable $e) {
    $campaignError = 'Nie udalo sie pobrac danych kampanii.';
}

if (!$campaignData) {
    http_response_code(404);
    echo htmlspecialchars($campaignError ?? 'Nie znaleziono kampanii');
    exit;
}

$systemCfg = $campaignData['system_config'] ?? [];
$clientEmail = '';
$clientPhone = '';
$clientId = $campaignData['klient_id'] ?? null;
if (!empty($clientId)) {
    $clientStmt = $pdo->prepare('SELECT email, telefon FROM klienci WHERE id = :id');
    if ($clientStmt->execute([':id' => $clientId])) {
        $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
        if ($client) {
            $clientEmail = trim($client['email'] ?? '');
            $clientPhone = trim($client['telefon'] ?? '');
        }
    }
}

if (normalizeRole($currentUser) === 'Handlowiec') {
    $kampanieCols = getTableColumns($pdo, 'kampanie');
    if (hasColumn($kampanieCols, 'owner_user_id')) {
        $ownStmt = $pdo->prepare('SELECT owner_user_id FROM kampanie WHERE id = :id');
        $ownStmt->execute([':id' => $kampaniaId]);
        $ownerId = (int)$ownStmt->fetchColumn();
        if ($ownerId && $ownerId !== (int)$currentUser['id']) {
            http_response_code(403);
            echo 'Brak uprawnien do tej kampanii.';
            exit;
        }
    }
    if (!empty($clientId)) {
        $clientCols = getTableColumns($pdo, 'klienci');
        if (hasColumn($clientCols, 'owner_user_id')) {
            $ownStmt = $pdo->prepare('SELECT owner_user_id FROM klienci WHERE id = :id');
            $ownStmt->execute([':id' => $clientId]);
            $ownerId = (int)$ownStmt->fetchColumn();
            if ($ownerId && $ownerId !== (int)$currentUser['id']) {
                http_response_code(403);
                echo 'Brak uprawnien do klienta.';
                exit;
            }
        }
    }
    if ($leadId > 0) {
        $leadCols = getTableColumns($pdo, 'leady');
        if (hasColumn($leadCols, 'owner_user_id')) {
            $ownStmt = $pdo->prepare('SELECT owner_user_id FROM leady WHERE id = :id');
            $ownStmt->execute([':id' => $leadId]);
            $ownerId = (int)$ownStmt->fetchColumn();
            if ($ownerId && $ownerId !== (int)$currentUser['id']) {
                http_response_code(403);
                echo 'Brak uprawnien do leada.';
                exit;
            }
        }
    }
}

$userFullName = trim(($currentUser['imie'] ?? '') . ' ' . ($currentUser['nazwisko'] ?? ''));
if ($userFullName === '') {
    $userFullName = $currentUser['login'] ?? 'Handlowiec';
}

$defaultSubject = sprintf('Oferta / Mediaplan - %s - kampania #%d', $campaignData['klient'] ?? 'Klient', $kampaniaId);
$defaultBody = "Dzien dobry,\n\nW zalaczeniu przesylam plan emisyjny dotyczacy kampanii #" . $kampaniaId . " dla firmy " . ($campaignData['klient'] ?? '') . ".\nW razie pytan pozostaje do dyspozycji.";
if (!empty($currentUser['email_signature'])) {
    $defaultBody .= "\n\n" . trim($currentUser['email_signature']);
} else {
    $defaultBody .= "\n\nPozdrawiam,\n" . $userFullName;
}

$toEmail = $clientEmail;
$ccEmail = '';
$bccEmail = '';
$subject = $defaultSubject;
$messageBody = $defaultBody;
$includePdf = true;
$successMessage = null;
$errors = [];

$mailerAvailable = ensureMailerLoaded();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $toEmail = trim($_POST['to_email'] ?? '');
    $ccEmail = trim($_POST['cc_email'] ?? '');
    $bccEmail = trim($_POST['bcc_email'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $messageBody = trim($_POST['message'] ?? '');
    $includePdf = !empty($_POST['include_pdf']);

    $toList = parseEmailList($toEmail);
    $ccList = parseEmailList($ccEmail);
    $bccList = parseEmailList($bccEmail);

    if (!$toList) {
        $errors[] = 'Podaj poprawny adres e-mail odbiorcy.';
    }
    if ($ccEmail !== '' && !$ccList) {
        $errors[] = 'Adres CC jest nieprawidlowy.';
    }
    if ($bccEmail !== '' && !$bccList) {
        $errors[] = 'Adres BCC jest nieprawidlowy.';
    }
    if ($subject === '') {
        $errors[] = 'Temat wiadomosci jest wymagany.';
    }
    if ($messageBody === '') {
        $errors[] = 'Tresc wiadomosci nie moze byc pusta.';
    }
    if (!$mailerAvailable) {
        $errors[] = 'Biblioteka PHPMailer nie jest dostepna.';
    }

    $maxAttachmentBytes = 10 * 1024 * 1024;
    $uploadedFiles = normalizeUploadedFiles($_FILES['attachments'] ?? null);
    $attachmentsMeta = [];

    if (!$errors) {
        $smtpConfig = resolveSmtpConfig($systemCfg, $currentUser);
        if (($smtpConfig['auth'] ?? false) && ((string)($smtpConfig['username'] ?? '') === '' || (string)($smtpConfig['password'] ?? '') === '')) {
            $errors[] = 'Brak danych logowania SMTP.';
        }
        if (trim((string)($smtpConfig['host'] ?? '')) === '') {
            $errors[] = 'Brak hosta SMTP w konfiguracji.';
        }

        if (trim((string)($smtpConfig['from_email'] ?? '')) === '') {
            $smtpConfig['from_email'] = trim((string)($currentUser['smtp_from_email'] ?? ''));
        }
        if (trim((string)($smtpConfig['from_email'] ?? '')) === '') {
            $smtpConfig['from_email'] = trim((string)($currentUser['email'] ?? ''));
        }
        if (trim((string)($smtpConfig['from_name'] ?? '')) === '') {
            $smtpConfig['from_name'] = trim((string)($currentUser['smtp_from_name'] ?? ''));
        }
        if (trim((string)($smtpConfig['from_name'] ?? '')) === '') {
            $smtpConfig['from_name'] = $userFullName;
        }
        if (trim((string)($smtpConfig['from_email'] ?? '')) === '') {
            $errors[] = 'Brak adresu nadawcy.';
        }

        foreach ($uploadedFiles as $file) {
            if ($file['error'] === UPLOAD_ERR_NO_FILE && $file['name'] === '') {
                continue;
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $uploadError = uploadErrorMessage($file['error']);
                $technicalName = $file['name'] !== '' ? $file['name'] : '(brak nazwy)';
                error_log('wyslij_oferte: attachment upload failed: ' . $uploadError . '; file=' . $technicalName);
                $errors[] = 'Nie udalo sie wgrac zalacznika: ' . ($file['name'] !== '' ? $file['name'] : $uploadError);
                continue;
            }
            if ($file['size'] > $maxAttachmentBytes) {
                $errors[] = 'Zalacznik jest za duzy: ' . $file['name'];
                continue;
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext === 'php') {
                $errors[] = 'Nieprawidlowe rozszerzenie pliku: ' . $file['name'];
                continue;
            }
        }
    }

    $mailStatus = 'ERROR';
    $mailErrorMsg = null;
    $mailMessageId = null;
    $mailAttempted = false;

    if (!$errors) {
        try {
        $mailAttempted = true;
        $archiveEnabled = !empty($systemCfg['crm_archive_enabled']);
        $archiveBcc = trim((string)($systemCfg['crm_archive_bcc_email'] ?? ''));
        if ($archiveEnabled && $archiveBcc !== '' && filter_var($archiveBcc, FILTER_VALIDATE_EMAIL)) {
            $lower = strtolower($archiveBcc);
            $has = false;
            foreach ($bccList as $existing) {
                if (strtolower($existing) === $lower) {
                    $has = true;
                    break;
                }
            }
            if (!$has) {
                $bccList[] = $archiveBcc;
            }
        }

        $bodyText = $messageBody;
        $bodyHtml = nl2br(htmlspecialchars($messageBody, ENT_QUOTES, 'UTF-8'));
        $entityType = $clientId ? 'client' : ($leadId > 0 ? 'lead' : null);
        $entityId = $clientId ? (int)$clientId : ($leadId > 0 ? $leadId : null);
        $sentAt = date('Y-m-d H:i:s');
        $messageId = mailboxGenerateMessageId('offer:' . $kampaniaId . ':' . microtime(true), (string)$smtpConfig['from_email']);
        $threadId = mailboxFindOrCreateThread($pdo, $entityType, $entityId, $subject, $sentAt);
        $mailAccountId = $mailAccount ? (int)$mailAccount['id'] : 0;

        $mailMessageId = insertMailMessage($pdo, [
            'client_id' => $clientId,
            'lead_id' => $leadId > 0 ? $leadId : null,
            'campaign_id' => $kampaniaId,
            'owner_user_id' => (int)$currentUser['id'],
            'mail_account_id' => $mailAccountId,
            'thread_id' => $threadId,
            'direction' => 'out',
            'from_email' => $smtpConfig['from_email'],
            'from_name' => $smtpConfig['from_name'],
            'to_email' => implode(', ', $toList),
            'to_emails' => implode(', ', $toList),
            'cc_email' => $ccList ? implode(', ', $ccList) : null,
            'cc_emails' => $ccList ? implode(', ', $ccList) : null,
            'bcc_email' => $bccList ? implode(', ', $bccList) : null,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'status' => 'ERROR',
            'message_id' => $messageId,
            'sent_at' => $sentAt,
            'has_attachments' => 0,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'created_by_user_id' => (int)$currentUser['id'],
        ]);

        $storageDir = ensureAttachmentDir($clientId, $mailMessageId);
        $relativeBase = 'storage/mail_attachments/' . ($clientId ? (string)$clientId : '0') . '/' . $mailMessageId;

        $attachments = [];
        if ($includePdf) {
            $pdfBinary = generateMediaplanPdfBinary($pdo, $kampaniaId);
            if ($pdfBinary === '' || strncmp($pdfBinary, '%PDF', 4) !== 0) {
                $size = strlen($pdfBinary);
                error_log('wyslij_oferte: generated mediaplan PDF is invalid; campaign_id=' . $kampaniaId . '; bytes=' . $size);
                throw new RuntimeException('Wygenerowany PDF mediaplanu jest pusty albo nieprawidlowy.');
            }
            $pdfName = 'mediaplan_kampania_' . $kampaniaId . '.pdf';
            $safePdfName = sanitizeAttachmentName($pdfName);
            $storedName = uniqueFilename($storageDir, $safePdfName);
            $storedPath = $storageDir . DIRECTORY_SEPARATOR . $storedName;
            $written = @file_put_contents($storedPath, $pdfBinary);
            if ($written === false || $written !== strlen($pdfBinary)) {
                error_log('wyslij_oferte: cannot write mediaplan PDF attachment; campaign_id=' . $kampaniaId . '; path=' . $storedPath . '; bytes=' . strlen($pdfBinary) . '; written=' . var_export($written, true));
                throw new RuntimeException('Nie udalo sie zapisac PDF mediaplanu w katalogu zalacznikow.');
            }
            validateLocalAttachment($storedPath, 'application/pdf');
            $relativePath = $relativeBase . '/' . $storedName;
            $attachments[] = [
                'path' => $storedPath,
                'filename' => $storedName,
                'mime_type' => 'application/pdf',
            ];
            storeMailAttachment($pdo, $mailMessageId, [
                'filename' => $storedName,
                'stored_path' => $relativePath,
                'mime_type' => 'application/pdf',
                'size_bytes' => strlen($pdfBinary),
            ]);
        }

        foreach ($uploadedFiles as $file) {
            if ($file['error'] === UPLOAD_ERR_NO_FILE && $file['name'] === '') {
                continue;
            }
            if ($file['error'] !== UPLOAD_ERR_OK) {
                continue;
            }
            if ($file['size'] > $maxAttachmentBytes) {
                continue;
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext === 'php') {
                continue;
            }
            $safeName = sanitizeAttachmentName($file['name']);
            $storedName = uniqueFilename($storageDir, $safeName);
            $storedPath = $storageDir . DIRECTORY_SEPARATOR . $storedName;
            if (!move_uploaded_file($file['tmp_name'], $storedPath)) {
                error_log('wyslij_oferte: move_uploaded_file failed; source=' . $file['tmp_name'] . '; target=' . $storedPath . '; file=' . $file['name']);
                $errors[] = 'Nie udalo sie zapisac zalacznika: ' . $file['name'];
                continue;
            }
            try {
                validateLocalAttachment($storedPath);
            } catch (RuntimeException $e) {
                $errors[] = 'Nie udalo sie zapisac zalacznika: ' . $file['name'];
                continue;
            }
            $mimeType = $file['type'] ?? '';
            $relativePath = $relativeBase . '/' . $storedName;
            $attachments[] = [
                'path' => $storedPath,
                'filename' => $storedName,
                'mime_type' => $mimeType,
            ];
            storeMailAttachment($pdo, $mailMessageId, [
                'filename' => $storedName,
                'stored_path' => $relativePath,
                'mime_type' => $mimeType,
                'size_bytes' => $file['size'],
            ]);
        }

        if ($mailMessageId && $attachments) {
            $stmtHas = $pdo->prepare('UPDATE mail_messages SET has_attachments = 1 WHERE id = :id');
            $stmtHas->execute([':id' => $mailMessageId]);
        }

        if ($errors) {
            $mailErrorMsg = $errors[0] ?? 'Nie udalo sie zapisac zalacznikow.';
            updateMailMessageStatus($pdo, $mailMessageId, $mailStatus, $mailErrorMsg);
        }

        if (!$errors) {
            $sendResult = sendCrmEmail(
                [
                    'archive_enabled' => $archiveEnabled,
                    'archive_bcc_email' => $archiveBcc,
                ],
                $smtpConfig,
                [
                    'to' => $toList,
                    'cc' => $ccList,
                    'bcc' => $bccList,
                    'subject' => $subject,
                    'body_html' => $bodyHtml,
                    'body_text' => $bodyText,
                    'message_id' => $messageId,
                ],
                $attachments
            );

            if ($sendResult['ok']) {
                $mailStatus = 'SENT';
                $successMessage = 'Oferta zostala wyslana.';
                if ($leadId > 0) {
                    applyOfferFollowUpForLead($pdo, $leadId, (int)$currentUser['id']);
                }
            } else {
                $mailErrorMsg = $sendResult['error'] ?: 'Nie udalo sie wyslac wiadomosci.';
                $errors[] = $mailErrorMsg;
            }

            updateMailMessageStatus($pdo, $mailMessageId, $mailStatus, $mailErrorMsg);
        }
        } catch (Throwable $e) {
            $mailErrorMsg = $e->getMessage();
            error_log('wyslij_oferte: offer email failed; campaign_id=' . $kampaniaId . '; error=' . get_class($e) . ': ' . ($mailErrorMsg !== '' ? $mailErrorMsg : '(empty message)'));
            $errors[] = 'Wysylanie wiadomosci nie powiodlo sie: ' . ($mailErrorMsg !== '' ? $mailErrorMsg : 'szczegoly zapisano w logu.');
            if (!empty($mailMessageId)) {
                updateMailMessageStatus($pdo, (int)$mailMessageId, 'ERROR', $mailErrorMsg);
            }
        }
    }

    if ($errors && !$mailErrorMsg) {
        $mailErrorMsg = $errors[0] ?? null;
    }

    $logStmt = $pdo->prepare('INSERT INTO historia_maili_ofert (kampania_id, klient_id, user_id, to_email, subject, status, error_msg) VALUES (:kampania_id, :klient_id, :user_id, :to_email, :subject, :status, :error_msg)');
    $logStmt->execute([
        ':kampania_id' => $kampaniaId,
        ':klient_id'   => $clientId,
        ':user_id'     => $currentUser['id'],
        ':to_email'    => implode(', ', $toList),
        ':subject'     => $subject,
        ':status'      => $mailStatus === 'SENT' ? 'sent' : 'error',
        ':error_msg'   => $mailErrorMsg,
    ]);
    $offerHistoryId = (int)$pdo->lastInsertId();

    $fallbackHash = hash('sha256', implode('|', [
        (string)$kampaniaId,
        implode(',', $toList ?? []),
        (string)$subject,
        (string)$messageBody,
    ]));
    $offerIdempotencyKey = communicationBuildIdempotencyKey('offer_sent', [
        $kampaniaId,
        !empty($mailMessageId) ? 'mail_' . (int)$mailMessageId : 'hash_' . $fallbackHash,
    ]);
    $offerComm = communicationLogEvent($pdo, [
        'event_type' => 'offer_sent',
        'idempotency_key' => $offerIdempotencyKey,
        'direction' => 'outbound_client',
        'status' => $mailStatus === 'SENT' ? 'sent' : 'error',
        'recipient' => implode(', ', $toList ?? []),
        'subject' => $subject,
        'body' => $messageBody,
        'meta_json' => [
            'mail_message_id' => !empty($mailMessageId) ? (int)$mailMessageId : null,
            'historia_maili_ofert_id' => $offerHistoryId > 0 ? $offerHistoryId : null,
            'cc' => implode(', ', $ccList ?? []),
            'bcc' => implode(', ', $bccList ?? []),
            'error' => $mailErrorMsg,
        ],
        'lead_id' => $leadId > 0 ? $leadId : null,
        'client_id' => !empty($clientId) ? (int)$clientId : null,
        'campaign_id' => $kampaniaId,
        'created_by_user_id' => (int)$currentUser['id'],
    ]);
    if ($mailStatus === 'SENT') {
        createEventTask($pdo, 'offer_sent', [
            'campaign_id' => $kampaniaId,
            'lead_id' => $leadId > 0 ? $leadId : 0,
            'communication_event_id' => (int)($offerComm['id'] ?? 0),
            'event_at' => date('Y-m-d H:i:s'),
            'actor_user_id' => (int)$currentUser['id'],
        ]);
    }

    if ($mailAttempted) {
        $activityType = $mailStatus === 'SENT' ? 'mail' : 'system';
        $activityTitle = $mailStatus === 'SENT' ? $subject : 'Blad wysylki e-maila';
        $activityBody = $mailStatus === 'SENT'
            ? 'Wyslano e-mail do: ' . implode(', ', $toList)
            : 'Wysylka e-maila nieudana: ' . sanitizeMailError($mailErrorMsg);
        $meta = ['to' => implode(', ', $toList)];
        if (!empty($mailMessageId)) {
            $meta['mail_message_id'] = (int)$mailMessageId;
        }

        if (!empty($clientId)) {
            addActivity('klient', (int)$clientId, $activityType, (int)$currentUser['id'], $activityBody, null, $activityTitle, $meta);
        }
        if ($leadId > 0) {
            addActivity('lead', $leadId, $activityType, (int)$currentUser['id'], $activityBody, null, $activityTitle, $meta);
        }
    }
}

include 'includes/header.php';
?>
<div class="container py-4">
  <h3>Wyślij ofertę - kampania #<?= (int)$kampaniaId ?></h3>
  <p class="text-muted mb-4">Klient: <?= htmlspecialchars($campaignData['klient'] ?? '') ?> | Okres: <?= htmlspecialchars($campaignData['data_start']->format('Y-m-d')) ?> - <?= htmlspecialchars($campaignData['data_koniec']->format('Y-m-d')) ?></p>

  <?php if (!$mailerAvailable): ?>
    <div class="alert alert-warning">
      Nie znaleziono biblioteki PHPMailer. Aby wysylac oferty, uruchom <code>composer require phpmailer/phpmailer</code> w katalogu projektu.
    </div>
  <?php endif; ?>

  <?php if ($successMessage): ?>
    <div class="alert alert-success"><?= htmlspecialchars($successMessage) ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert alert-danger">
      <ul class="mb-0">
        <?php foreach ($errors as $err): ?>
          <li><?= htmlspecialchars($err) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data">
    <div class="mb-3">
      <label class="form-label">Adres e-mail klienta</label>
      <input type="email" name="to_email" class="form-control" value="<?= htmlspecialchars($toEmail) ?>" required>
      <?php if ($clientPhone): ?>
        <div class="form-text">Telefon klienta: <?= htmlspecialchars($clientPhone) ?></div>
      <?php endif; ?>
    </div>
    <div class="mb-3">
      <label class="form-label">CC (opcjonalnie)</label>
      <input type="text" name="cc_email" class="form-control" value="<?= htmlspecialchars($ccEmail) ?>" placeholder="np. osoba@firma.pl">
    </div>
    <div class="mb-3">
      <label class="form-label">BCC (opcjonalnie)</label>
      <input type="text" name="bcc_email" class="form-control" value="<?= htmlspecialchars($bccEmail) ?>" placeholder="np. archiwum@firma.pl">
    </div>
    <div class="mb-3">
      <label class="form-label">Temat</label>
      <input type="text" name="subject" class="form-control" value="<?= htmlspecialchars($subject) ?>" required>
    </div>
    <div class="mb-3">
      <label class="form-label">Tresc wiadomosci</label>
      <textarea name="message" rows="8" class="form-control" required><?= htmlspecialchars($messageBody) ?></textarea>
      <div class="form-text">PDF mediaplanu moze zostac dodany automatycznie.</div>
    </div>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" name="include_pdf" id="include_pdf" value="1" <?= $includePdf ? 'checked' : '' ?>>
      <label class="form-check-label" for="include_pdf">Dolacz PDF mediaplanu</label>
    </div>
    <div class="mb-3">
      <label class="form-label">Dodatkowe zalaczniki</label>
      <input type="file" name="attachments[]" class="form-control" multiple>
      <div class="form-text">Maksymalny rozmiar pojedynczego pliku: 10 MB. Blokada .php.</div>
    </div>
    <div class="d-flex justify-content-between">
      <a class="btn btn-outline-secondary" href="kampania_podglad.php?id=<?= (int)$kampaniaId ?>">Anuluj</a>
      <button type="submit" class="btn btn-primary" <?= !$mailerAvailable ? 'disabled' : '' ?>>Wyślij ofertę</button>
    </div>
  </form>
</div>
<?php include 'includes/footer.php'; ?>

<?php
function applyOfferFollowUpForLead(PDO $pdo, int $leadId, int $userId): void {
    $columns = getTableColumns($pdo, 'leady');
    if (!hasColumn($columns, 'next_action') || !hasColumn($columns, 'next_action_at')) {
        return;
    }
    $stmt = $pdo->prepare("SELECT next_action, next_action_at FROM leady WHERE id = :id");
    $stmt->execute([':id' => $leadId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!empty($row['next_action_at'])) {
        return;
    }

    $setParts = ['next_action = :next_action', 'next_action_at = DATE_ADD(NOW(), INTERVAL 2 DAY)'];
    if (hasColumn($columns, 'next_action_date')) {
        $setParts[] = 'next_action_date = DATE(DATE_ADD(NOW(), INTERVAL 2 DAY))';
    }
    $pdo->prepare("UPDATE leady SET " . implode(', ', $setParts) . " WHERE id = :id")
        ->execute([':next_action' => 'Follow-up po ofercie', ':id' => $leadId]);

    $check = $pdo->prepare("SELECT id FROM leady_aktywnosci WHERE lead_id = :lead_id AND typ = 'offer_sent' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE) LIMIT 1");
    $check->execute([':lead_id' => $leadId]);
    if (!$check->fetchColumn()) {
        $stmt = $pdo->prepare("INSERT INTO leady_aktywnosci (lead_id, user_id, typ, opis) VALUES (:lead_id, :user_id, 'offer_sent', :opis)");
        $stmt->execute([
            ':lead_id' => $leadId,
            ':user_id' => $userId,
            ':opis' => 'Oferta wyslana (system)',
        ]);
    }
}

function normalizeUploadedFiles($files): array {
    if (!is_array($files) || empty($files['name'])) {
        return [];
    }
    $result = [];
    if (is_array($files['name'])) {
        $count = count($files['name']);
        for ($i = 0; $i < $count; $i++) {
            $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            $name = (string)($files['name'][$i] ?? '');
            if ($error === UPLOAD_ERR_NO_FILE && $name === '') {
                continue;
            }
            $result[] = [
                'name' => $name,
                'type' => (string)($files['type'][$i] ?? ''),
                'tmp_name' => (string)($files['tmp_name'][$i] ?? ''),
                'error' => $error,
                'size' => (int)($files['size'][$i] ?? 0),
            ];
        }
    } else {
        $error = (int)($files['error'] ?? UPLOAD_ERR_NO_FILE);
        $name = (string)($files['name'] ?? '');
        if ($error === UPLOAD_ERR_NO_FILE && $name === '') {
            return [];
        }
        $result[] = [
            'name' => $name,
            'type' => (string)($files['type'] ?? ''),
            'tmp_name' => (string)($files['tmp_name'] ?? ''),
            'error' => $error,
            'size' => (int)($files['size'] ?? 0),
        ];
    }
    return $result;
}

function sanitizeAttachmentName(string $name): string {
    $name = basename($name);
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    if ($name === '' || $name === '.' || $name === '..') {
        $name = 'attachment';
    }
    return $name;
}

function uniqueFilename(string $dir, string $name): string {
    $base = pathinfo($name, PATHINFO_FILENAME);
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $candidate = $name;
    $i = 1;
    while (file_exists($dir . DIRECTORY_SEPARATOR . $candidate)) {
        $suffix = '_' . $i;
        $candidate = $base . $suffix . ($ext !== '' ? '.' . $ext : '');
        $i++;
    }
    return $candidate;
}

function ensureAttachmentDir(?int $clientId, int $mailMessageId): string {
    $base = dirname(__DIR__) . '/storage/mail_attachments';
    if (!is_dir($base) && !@mkdir($base, 0775, true)) {
        error_log('wyslij_oferte: cannot create attachment base directory: ' . $base);
        throw new RuntimeException('Nie udalo sie utworzyc katalogu zalacznikow.');
    }
    if (!is_writable($base)) {
        error_log('wyslij_oferte: attachment base directory is not writable: ' . $base);
        throw new RuntimeException('Katalog zalacznikow nie jest zapisywalny.');
    }
    $clientPart = $clientId ? (string)$clientId : '0';
    $dir = $base . '/' . $clientPart . '/' . $mailMessageId;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        error_log('wyslij_oferte: cannot create attachment directory: ' . $dir);
        throw new RuntimeException('Nie udalo sie utworzyc katalogu zalacznikow dla wiadomosci.');
    }
    if (!is_writable($dir)) {
        error_log('wyslij_oferte: attachment directory is not writable: ' . $dir);
        throw new RuntimeException('Katalog zalacznikow dla wiadomosci nie jest zapisywalny.');
    }
    return $dir;
}

function uploadErrorMessage(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'Plik przekracza limit upload_max_filesize.';
        case UPLOAD_ERR_FORM_SIZE:
            return 'Plik przekracza limit formularza.';
        case UPLOAD_ERR_PARTIAL:
            return 'Plik zostal wgrany tylko czesciowo.';
        case UPLOAD_ERR_NO_FILE:
            return 'Nie wybrano pliku.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Brak katalogu tymczasowego PHP.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Nie mozna zapisac pliku na dysku.';
        case UPLOAD_ERR_EXTENSION:
            return 'Rozszerzenie PHP przerwalo upload.';
        default:
            return 'Nieznany blad uploadu (kod ' . $code . ').';
    }
}

function validateLocalAttachment(string $path, string $expectedMime = ''): void {
    if (!file_exists($path)) {
        error_log('wyslij_oferte: attachment file does not exist: ' . $path);
        throw new RuntimeException('Plik zalacznika nie istnieje.');
    }
    if (!is_readable($path)) {
        error_log('wyslij_oferte: attachment file is not readable: ' . $path);
        throw new RuntimeException('Plik zalacznika nie jest czytelny.');
    }
    $size = filesize($path);
    if ($size === false || $size <= 0) {
        error_log('wyslij_oferte: attachment file is empty: ' . $path);
        throw new RuntimeException('Plik zalacznika jest pusty.');
    }
    if ($expectedMime === 'application/pdf') {
        $handle = @fopen($path, 'rb');
        $header = $handle ? (string)fread($handle, 4) : '';
        if ($handle) {
            fclose($handle);
        }
        if ($header !== '%PDF') {
            error_log('wyslij_oferte: attachment file is not a PDF: ' . $path);
            throw new RuntimeException('Plik zalacznika nie jest poprawnym PDF.');
        }
    }
}

function insertMailMessage(PDO $pdo, array $data): int {
    return mailboxInsertMessage($pdo, $data);
}

function updateMailMessageStatus(PDO $pdo, int $id, string $status, ?string $error): void {
    $stmt = $pdo->prepare('UPDATE mail_messages SET status = :status, error_message = :error_message WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':error_message' => $error,
        ':id' => $id,
    ]);
}

function sanitizeMailError(?string $message): string {
    $message = trim((string)$message);
    if ($message === '') {
        return 'Nieznany blad wysylki.';
    }
    $message = preg_replace('/(pass(word)?\\s*[:=]\\s*)\\S+/i', '$1***', $message);
    $message = preg_replace('/(haslo\\s*[:=]\\s*)\\S+/i', '$1***', $message);
    if (strlen($message) > 400) {
        $message = substr($message, 0, 400) . '...';
    }
    return $message;
}

function storeMailAttachment(PDO $pdo, int $mailMessageId, array $data): void {
    $stmt = $pdo->prepare('INSERT INTO mail_attachments (mail_message_id, filename, stored_path, storage_path, mime_type, size_bytes)
        VALUES (:mail_message_id, :filename, :stored_path, :storage_path, :mime_type, :size_bytes)');
    $stmt->execute([
        ':mail_message_id' => $mailMessageId,
        ':filename' => $data['filename'],
        ':stored_path' => $data['stored_path'],
        ':storage_path' => $data['stored_path'],
        ':mime_type' => $data['mime_type'],
        ':size_bytes' => $data['size_bytes'],
    ]);
}
?>
