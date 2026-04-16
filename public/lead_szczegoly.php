<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/crm_activity.php';
require_once __DIR__ . '/includes/tasks.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/mailbox_service.php';
require_once __DIR__ . '/includes/lead_pipeline_helpers.php';
require_once __DIR__ . '/includes/process_timeline.php';
require_once __DIR__ . '/includes/mediaplan_pdf.php';
require_once __DIR__ . '/../config/config.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

$pageTitle = 'Szczegoly leada';
ensureLeadColumns($pdo);
ensureCrmMailTables($pdo);
ensureKampanieOwnershipColumns($pdo);
$mailAccount = getMailAccountForUser($pdo, (int)$currentUser['id']);
$mailAccountInactive = mailAccountInactiveFound((int)$currentUser['id']);
$mailSecretError = '';
if ($mailAccount) {
    try {
        mailSecretKey();
    } catch (Throwable $e) {
        $mailSecretError = $e->getMessage();
    }
}
$leadCols = getTableColumns($pdo, 'leady');
$leadId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$lead = null;
$leadError = '';

if ($leadId <= 0) {
    $leadError = 'Brak identyfikatora leada.';
    http_response_code(400);
} else {
    $stmt = $pdo->prepare('SELECT * FROM leady WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $leadId]);
    $lead = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$lead || !canAccessCrmObject('lead', $leadId, $currentUser)) {
        $leadError = 'Brak dostępu do leada lub nie istnieje.';
        http_response_code(403);
        $lead = null;
    }
}

$flash = $_SESSION['lead_detail_flash'] ?? null;
unset($_SESSION['lead_detail_flash']);
$mailSyncFlash = $_SESSION['mail_sync_flash'] ?? null;
unset($_SESSION['mail_sync_flash']);

function normalizeTaskDateTime(?string $input): ?string
{
    $input = trim((string)$input);
    if ($input === '') {
        return null;
    }
    $dt = DateTime::createFromFormat('Y-m-d\\TH:i', $input, new DateTimeZone('Europe/Warsaw'));
    if (!$dt) {
        return null;
    }
    return $dt->format('Y-m-d H:i:s');
}

function resolveLeadOwnerId(array $lead, array $leadCols): int
{
    if (hasColumn($leadCols, 'owner_user_id') && !empty($lead['owner_user_id'])) {
        return (int)$lead['owner_user_id'];
    }
    if (hasColumn($leadCols, 'assigned_user_id') && !empty($lead['assigned_user_id'])) {
        return (int)$lead['assigned_user_id'];
    }
    return 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crm_action'])) {
    $action = trim((string)($_POST['crm_action'] ?? ''));
    $token = $_POST['csrf_token'] ?? '';
    $flash = null;
    $redirectAnchor = '#lead-activity';

    if (!isCsrfTokenValid($token)) {
        $flash = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } elseif (!$lead || !canAccessCrmObject('lead', $leadId, $currentUser)) {
        $flash = ['type' => 'danger', 'msg' => 'Brak dostępu do leada.'];
    } else {
        switch ($action) {
            case 'status':
                $statusId = isset($_POST['crm_status_id']) ? (int)$_POST['crm_status_id'] : 0;
                $comment = trim((string)($_POST['crm_status_comment'] ?? ''));
                if ($statusId <= 0) {
                    $flash = ['type' => 'warning', 'msg' => 'Wybierz status/czynnosc.'];
                    break;
                }
                $availableStatuses = getStatusy('lead');
                $isValidStatus = false;
                foreach ($availableStatuses as $status) {
                    if ((int)($status['id'] ?? 0) === $statusId) {
                        $isValidStatus = true;
                        break;
                    }
                }
                if (!$isValidStatus) {
                    $flash = ['type' => 'danger', 'msg' => 'Wybrany status nie jest dostepny.'];
                    break;
                }
                if (!addActivity('lead', $leadId, 'status', (int)$currentUser['id'], $comment, $statusId)) {
                    $flash = ['type' => 'danger', 'msg' => 'Nie udało się zapisać aktywności.'];
                    break;
                }
                $flash = ['type' => 'success', 'msg' => 'Status/czynność zapisane w osi aktywności.'];
                break;

            case 'note':
                $note = trim((string)($_POST['crm_note'] ?? ''));
                if ($note === '') {
                    $flash = ['type' => 'warning', 'msg' => 'Wpisz treść notatki.'];
                    break;
                }
                if (!addActivity('lead', $leadId, 'notatka', (int)$currentUser['id'], $note)) {
                    $flash = ['type' => 'danger', 'msg' => 'Nie udało się dodać notatki.'];
                    break;
                }
                $flash = ['type' => 'success', 'msg' => 'Notatka dodana do osi aktywności.'];
                break;
            case 'task':
                $taskType = trim((string)($_POST['task_type'] ?? ''));
                $taskTitle = trim((string)($_POST['task_title'] ?? ''));
                $taskDueRaw = trim((string)($_POST['task_due_at'] ?? ''));
                $taskNote = trim((string)($_POST['task_note'] ?? ''));
                $taskTypes = ['telefon', 'email', 'sms', 'spotkanie', 'inne'];
                if ($taskTitle === '') {
                    $flash = ['type' => 'warning', 'msg' => 'Podaj tytul dzialania.'];
                    break;
                }
                if (!in_array($taskType, $taskTypes, true)) {
                    $flash = ['type' => 'warning', 'msg' => 'Wybierz typ dzialania.'];
                    break;
                }
                $taskDueAt = normalizeTaskDateTime($taskDueRaw);
                if (!$taskDueAt) {
                    $flash = ['type' => 'warning', 'msg' => 'Podaj poprawny termin dzialania.'];
                    break;
                }
                $taskOwnerId = (int)$currentUser['id'];
                $leadOwnerId = resolveLeadOwnerId($lead, $leadCols);
                if ($leadOwnerId > 0) {
                    $taskOwnerId = $leadOwnerId;
                }
                if (!addTask('lead', $leadId, $taskType, $taskTitle, $taskDueAt, $taskOwnerId, (int)$currentUser['id'], $taskNote)) {
                    $flash = ['type' => 'danger', 'msg' => 'Nie udało się zapisać działania.'];
                    break;
                }
                $flash = ['type' => 'success', 'msg' => 'Działanie zostało zaplanowane.'];
                break;
            case 'mail_send':
                $toRaw = trim((string)($_POST['mail_to'] ?? ''));
                $subject = trim((string)($_POST['mail_subject'] ?? ''));
                $body = trim((string)($_POST['mail_message'] ?? ''));
                $offerCampaignId = isset($_POST['offer_campaign_id']) ? (int)$_POST['offer_campaign_id'] : 0;
                $offerIncludeMediaplan = !empty($_POST['offer_include_mediaplan']);
                if ($toRaw === '') {
                    $flash = ['type' => 'warning', 'msg' => 'Podaj adres e-mail odbiorcy.'];
                    break;
                }
                $toList = parseEmailList($toRaw);
                if (!$toList) {
                    $flash = ['type' => 'warning', 'msg' => 'Podaj poprawny adres e-mail odbiorcy.'];
                    break;
                }
                if ($subject === '') {
                    $flash = ['type' => 'warning', 'msg' => 'Podaj temat wiadomości.'];
                    break;
                }
                if ($body === '') {
                    $flash = ['type' => 'warning', 'msg' => 'Wpisz treść wiadomości.'];
                    break;
                }
                if ($offerCampaignId > 0 && $offerIncludeMediaplan) {
                    $stmtOfferCampaign = $pdo->prepare('SELECT id FROM kampanie WHERE id = :id AND source_lead_id = :lead_id LIMIT 1');
                    $stmtOfferCampaign->execute([':id' => $offerCampaignId, ':lead_id' => $leadId]);
                    if (!$stmtOfferCampaign->fetchColumn()) {
                        $flash = ['type' => 'warning', 'msg' => 'Nie mozna dolaczyc mediaplanu: kampania nie jest powiazana z tym leadem.'];
                        break;
                    }
                }
                if (!$mailAccount && $mailAccountInactive) {
                    $flash = ['type' => 'danger', 'msg' => 'Konto pocztowe jest wyłączone (is_active=0). Skontaktuj się z administratorem.'];
                    break;
                }
                if (!$mailAccount) {
                    $flash = ['type' => 'danger', 'msg' => 'Brak skonfigurowanego konta poczty dla uzytkownika.'];
                    break;
                }
                if ($mailSecretError !== '') {
                    $flash = ['type' => 'danger', 'msg' => $mailSecretError];
                    break;
                }

                $smtpHost = trim((string)($mailAccount['smtp_host'] ?? ''));
                $smtpUser = trim((string)($mailAccount['username'] ?? ''));
                $smtpPass = mailboxDecryptPassword((string)($mailAccount['password_enc'] ?? ''));
                $fromEmail = trim((string)($mailAccount['email_address'] ?? ''));
                $smtpPort = (int)($mailAccount['smtp_port'] ?? 587);
                $smtpSecure = (string)($mailAccount['smtp_encryption'] ?? 'tls');
                $smtpSecure = $smtpSecure === 'none' ? '' : $smtpSecure;
                if ($smtpHost === '' || $smtpUser === '' || $smtpPass === '' || $fromEmail === '') {
                    $flash = ['type' => 'danger', 'msg' => 'Brak kompletnej konfiguracji SMTP dla uzytkownika.'];
                    break;
                }

                $now = date('Y-m-d H:i:s');
                $messageId = mailboxGenerateMessageId('out:' . (int)$currentUser['id'] . ':' . microtime(true), $fromEmail);
                $threadId = mailboxFindOrCreateThread($pdo, 'lead', $leadId, $subject, $now);
                $mailMessageId = mailboxInsertMessage($pdo, [
                    'client_id' => null,
                    'lead_id' => $leadId,
                    'campaign_id' => $offerCampaignId > 0 ? $offerCampaignId : null,
                    'owner_user_id' => (int)$currentUser['id'],
                    'mail_account_id' => (int)$mailAccount['id'],
                    'thread_id' => $threadId,
                    'direction' => 'out',
                    'from_email' => $fromEmail,
                    'from_name' => trim((string)($currentUser['imie'] ?? '') . ' ' . (string)($currentUser['nazwisko'] ?? '')) ?: (string)($currentUser['login'] ?? ''),
                    'to_email' => $toRaw,
                    'to_emails' => implode(', ', $toList),
                    'subject' => $subject,
                    'body_html' => nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
                    'body_text' => $body,
                    'status' => 'SENT',
                    'error_message' => null,
                    'message_id' => $messageId,
                    'sent_at' => $now,
                    'has_attachments' => 0,
                    'entity_type' => 'lead',
                    'entity_id' => $leadId,
                    'created_by_user_id' => (int)$currentUser['id'],
                ]);

                $attachmentErrors = [];
                $storedAttachments = [];
                $maxAttachmentBytes = 10 * 1024 * 1024;

                if ($mailMessageId > 0 && !empty($_FILES['mail_attachments'])) {
                    $storedAttachments = mailboxStoreUploadedAttachments($pdo, $mailMessageId, 'lead', $leadId, $_FILES['mail_attachments'], $maxAttachmentBytes, $attachmentErrors);
                }

                if ($mailMessageId > 0 && $offerCampaignId > 0 && $offerIncludeMediaplan) {
                    try {
                        $pdfBinary = generateMediaplanPdfBinary($pdo, $offerCampaignId);
                        $pdfName = 'mediaplan_kampania_' . $offerCampaignId . '.pdf';
                        $safePdfName = mailboxSanitizeAttachmentName($pdfName);
                        $dir = mailboxEnsureAttachmentDir('lead', $leadId, $mailMessageId);
                        if ($dir === null) {
                            $attachmentErrors[] = 'Nie udalo sie przygotowac katalogu dla mediaplanu.';
                        } else {
                            $storedName = uniqid('att_', true) . '_' . $safePdfName;
                            $targetPath = $dir . '/' . $storedName;
                            if (@file_put_contents($targetPath, $pdfBinary) === false) {
                                $attachmentErrors[] = 'Nie udalo sie zapisac zalacznika mediaplanu.';
                            } else {
                                $relativePath = 'storage/mail_attachments/lead/' . $leadId . '/' . $mailMessageId . '/' . $storedName;
                                $stmtAtt = $pdo->prepare(
                                    'INSERT INTO mail_attachments (mail_message_id, filename, stored_path, storage_path, mime_type, size_bytes)
                                     VALUES (:mail_message_id, :filename, :stored_path, :storage_path, :mime_type, :size_bytes)'
                                );
                                $stmtAtt->execute([
                                    ':mail_message_id' => $mailMessageId,
                                    ':filename' => $pdfName,
                                    ':stored_path' => $relativePath,
                                    ':storage_path' => $relativePath,
                                    ':mime_type' => 'application/pdf',
                                    ':size_bytes' => strlen($pdfBinary),
                                ]);
                                $storedAttachments[] = [
                                    'path' => $targetPath,
                                    'filename' => $pdfName,
                                    'mime_type' => 'application/pdf',
                                ];
                            }
                        }
                    } catch (Throwable $e) {
                        $attachmentErrors[] = 'Nie udalo sie wygenerowac mediaplanu PDF.';
                        error_log('lead_szczegoly: mediaplan attachment failed: ' . $e->getMessage());
                    }
                }

                if ($mailMessageId > 0 && $storedAttachments) {
                    $stmtUp = $pdo->prepare('UPDATE mail_messages SET has_attachments = 1 WHERE id = :id');
                    $stmtUp->execute([':id' => $mailMessageId]);
                }

                $sendResult = sendCrmEmail(
                    [],
                    [
                        'host' => $smtpHost,
                        'port' => $smtpPort > 0 ? $smtpPort : 587,
                        'secure' => $smtpSecure,
                        'auth' => true,
                        'username' => $smtpUser,
                        'password' => $smtpPass,
                        'from_email' => $fromEmail,
                        'from_name' => trim((string)($currentUser['imie'] ?? '') . ' ' . (string)($currentUser['nazwisko'] ?? '')) ?: (string)($currentUser['login'] ?? ''),
                    ],
                    [
                        'to' => $toList,
                        'subject' => $subject,
                        'body_html' => nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
                        'body_text' => $body,
                        'message_id' => $messageId,
                    ],
                    $storedAttachments
                );

                if (!$sendResult['ok']) {
                    $errorMsg = mailboxSanitizeMailError($sendResult['error'] ?? '');
                    if ($mailMessageId > 0) {
                        $stmtUp = $pdo->prepare('UPDATE mail_messages SET status = :status, error_message = :error_message WHERE id = :id');
                        $stmtUp->execute([
                            ':status' => 'ERROR',
                            ':error_message' => $errorMsg,
                            ':id' => $mailMessageId,
                        ]);
                    }
                    $flash = ['type' => 'danger', 'msg' => 'Błąd wysyłki: ' . $errorMsg];
                    break;
                }

                $snippet = substr($body, 0, 180);
                addActivity('lead', $leadId, 'email_out', (int)$currentUser['id'], $snippet !== '' ? $snippet : null, null, $subject);
                $flashMsg = 'Wiadomość została wysłana.';
                if ($attachmentErrors) {
                    $flashMsg .= ' Część załączników pominięto.';
                }
                $flash = ['type' => 'success', 'msg' => $flashMsg];
                break;
            case 'sms_send':
                $phone = trim((string)($_POST['sms_phone'] ?? ''));
                $content = trim((string)($_POST['sms_content'] ?? ''));
                if ($phone === '') {
                    $flash = ['type' => 'warning', 'msg' => 'Podaj numer telefonu.'];
                    break;
                }
                if (!preg_match('/^[0-9+ ]+$/', $phone)) {
                    $flash = ['type' => 'warning', 'msg' => 'Telefon moze zawierac tylko cyfry, plus i spacje.'];
                    break;
                }
                if ($content === '') {
                    $flash = ['type' => 'warning', 'msg' => 'Wpisz treść SMS.'];
                    break;
                }
                $stmtSms = $pdo->prepare(
                    'INSERT INTO sms_messages (entity_type, entity_id, user_id, direction, phone, content, provider, provider_message_id, status)
                     VALUES (:entity_type, :entity_id, :user_id, :direction, :phone, :content, :provider, :provider_message_id, :status)'
                );
                $stmtSms->execute([
                    ':entity_type' => 'lead',
                    ':entity_id' => $leadId,
                    ':user_id' => (int)$currentUser['id'],
                    ':direction' => 'out',
                    ':phone' => $phone,
                    ':content' => $content,
                    ':provider' => null,
                    ':provider_message_id' => null,
                    ':status' => 'queued',
                ]);
                $smsSnippet = substr($content, 0, 180);
                addActivity('lead', $leadId, 'sms_out', (int)$currentUser['id'], $smsSnippet !== '' ? $smsSnippet : null, null, 'SMS');
                $flash = ['type' => 'success', 'msg' => 'SMS został zapisany do wysyłki.'];
                break;

            default:
                $flash = ['type' => 'danger', 'msg' => 'Nieznana akcja CRM.'];
        }
    }

    if (in_array($action, ['mail_send'], true)) {
        $redirectAnchor = '#lead-mail-pane';
    } elseif (in_array($action, ['sms_send'], true)) {
        $redirectAnchor = '#lead-sms-pane';
    }

    $_SESSION['lead_detail_flash'] = $flash;
    header('Location: ' . BASE_URL . '/lead_szczegoly.php?id=' . $leadId . $redirectAnchor);
    exit;
}

$crmStatuses = $lead ? getStatusy('lead') : [];
$crmActivities = $lead ? getActivities('lead', $leadId) : [];
$activityHistory = $lead ? getActivityHistory($pdo, 'lead', $leadId, 20, true) : [];
$leadOwnerId = $lead ? resolveLeadOwnerId($lead, $leadCols) : 0;
$ownerLabel = 'Nieprzypisany';
if ($lead && $leadOwnerId > 0) {
    $stmtOwner = $pdo->prepare('SELECT login, imie, nazwisko FROM uzytkownicy WHERE id = :id');
    $stmtOwner->execute([':id' => $leadOwnerId]);
    $ownerRow = $stmtOwner->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($ownerRow) {
        $ownerLabel = trim((string)($ownerRow['imie'] ?? '') . ' ' . (string)($ownerRow['nazwisko'] ?? ''));
        if ($ownerLabel === '') {
            $ownerLabel = $ownerRow['login'] ?? ('User #' . $leadOwnerId);
        }
    } else {
        $ownerLabel = 'User #' . $leadOwnerId;
    }
}
$leadAddress = '-';
if ($lead) {
    $addressParts = [];
    $street = hasColumn($leadCols, 'ulica') ? trim((string)($lead['ulica'] ?? '')) : '';
    $buildingNo = hasColumn($leadCols, 'nr_budynku') ? trim((string)($lead['nr_budynku'] ?? '')) : '';
    $localNo = hasColumn($leadCols, 'nr_lokalu') ? trim((string)($lead['nr_lokalu'] ?? '')) : '';
    $postalCode = hasColumn($leadCols, 'kod_pocztowy') ? trim((string)($lead['kod_pocztowy'] ?? '')) : '';
    $city = hasColumn($leadCols, 'miasto') ? trim((string)($lead['miasto'] ?? '')) : '';

    $buildingPart = '';
    if ($buildingNo !== '' && $localNo !== '') {
        $buildingPart = $buildingNo . '/' . $localNo;
    } elseif ($buildingNo !== '') {
        $buildingPart = $buildingNo;
    } elseif ($localNo !== '') {
        $buildingPart = $localNo;
    }

    if ($street !== '' || $buildingPart !== '') {
        $addressParts[] = trim($street . ($buildingPart !== '' ? ' ' . $buildingPart : ''));
    }
    if ($postalCode !== '' || $city !== '') {
        $addressParts[] = trim($postalCode . ($city !== '' ? ' ' . $city : ''));
    }

    if (!$addressParts) {
        if (hasColumn($leadCols, 'adres') && !empty($lead['adres'])) {
            $addressParts[] = $lead['adres'];
        }
        if (hasColumn($leadCols, 'miejscowosc') && !empty($lead['miejscowosc'])) {
            $addressParts[] = $lead['miejscowosc'];
        }
        if (hasColumn($leadCols, 'miasto') && !empty($lead['miasto'])) {
            $addressParts[] = $lead['miasto'];
        }
    }

    if ($addressParts) {
        $leadAddress = implode(', ', $addressParts);
    }
}
$contactPrefLabels = [
    'telefon' => 'Telefon',
    'email' => 'E-mail',
    'sms' => 'SMS',
];
$leadContact = [
    'kontakt_imie_nazwisko' => $lead ? trim((string)($lead['kontakt_imie_nazwisko'] ?? '')) : '',
    'kontakt_stanowisko' => $lead ? trim((string)($lead['kontakt_stanowisko'] ?? '')) : '',
    'kontakt_telefon' => $lead ? trim((string)($lead['kontakt_telefon'] ?? '')) : '',
    'kontakt_email' => $lead ? trim((string)($lead['kontakt_email'] ?? '')) : '',
    'kontakt_preferencja' => $lead ? trim((string)($lead['kontakt_preferencja'] ?? '')) : '',
];
$hasLeadContact = false;
foreach ($leadContact as $value) {
    if ($value !== '') {
        $hasLeadContact = true;
        break;
    }
}


$defaultMailTo = '';
if ($leadContact['kontakt_email'] !== '') {
    $defaultMailTo = $leadContact['kontakt_email'];
} elseif (!empty($lead['email'])) {
    $defaultMailTo = (string)$lead['email'];
}

$offerCampaignId = isset($_GET['offer_campaign_id']) ? (int)$_GET['offer_campaign_id'] : 0;
$mailComposeAutoOpen = $offerCampaignId > 0;
$mailDraftSubject = '';
$mailDraftBody = '';
$mailDraftIncludeMediaplan = $offerCampaignId > 0;

if ($offerCampaignId > 0 && $lead) {
    try {
        $stmtOfferCampaign = $pdo->prepare('SELECT id, klient_nazwa, source_lead_id FROM kampanie WHERE id = :id LIMIT 1');
        $stmtOfferCampaign->execute([':id' => $offerCampaignId]);
        $offerCampaign = $stmtOfferCampaign->fetch(PDO::FETCH_ASSOC) ?: null;
        $isCampaignLeadMatch = $offerCampaign && (int)($offerCampaign['source_lead_id'] ?? 0) === $leadId;
        if ($isCampaignLeadMatch) {
            $leadDisplay = trim((string)($lead['nazwa_firmy'] ?? ''));
            if ($leadDisplay === '') {
                $leadDisplay = trim((string)($offerCampaign['klient_nazwa'] ?? ''));
            }
            if ($leadDisplay === '') {
                $leadDisplay = 'Lead';
            }
            $mailDraftSubject = sprintf('Oferta / Mediaplan - %s - kampania #%d', $leadDisplay, $offerCampaignId);

            $bodyLines = [
                'Dzien dobry,',
                '',
                'W zalaczeniu przesylam mediaplan do kampanii #' . $offerCampaignId . '.',
                'W razie pytan pozostaje do dyspozycji.',
            ];
            $signature = trim((string)($currentUser['email_signature'] ?? ''));
            if ($signature !== '') {
                $bodyLines[] = '';
                $bodyLines[] = $signature;
            }
            $mailDraftBody = implode("\n", $bodyLines);
        } else {
            $offerCampaignId = 0;
            $mailComposeAutoOpen = false;
            $mailDraftIncludeMediaplan = false;
        }
    } catch (Throwable $e) {
        $offerCampaignId = 0;
        $mailComposeAutoOpen = false;
        $mailDraftIncludeMediaplan = false;
        error_log('lead_szczegoly: offer campaign preload failed: ' . $e->getMessage());
    }
}

$defaultSmsPhone = '';
if ($leadContact['kontakt_telefon'] !== '') {
    $defaultSmsPhone = $leadContact['kontakt_telefon'];
} elseif (!empty($lead['telefon'])) {
    $defaultSmsPhone = (string)$lead['telefon'];
}

$mailMessages = [];
$mailAttachments = [];
$mailThreads = [];
$mailThreadFirst = [];
$mailMessageColumns = getTableColumns($pdo, 'mail_messages');
if ($lead) {
    $leadMailEmails = mailboxNormalizeEmailCandidates([
        (string)($lead['email'] ?? ''),
        $leadContact['kontakt_email'],
    ]);
    [$mailEmailSql, $mailEmailParams] = mailboxBuildMessageEmailMatchSql($mailMessageColumns, $leadMailEmails, 'm', 'lead_msg');

    $mailWhere = [
        "(m.entity_type = 'lead' AND m.entity_id = :lead_id)",
        "(m.entity_type IS NULL AND m.lead_id = :lead_id)",
    ];
    $mailParams = [':lead_id' => $leadId];
    $linkedClientId = isset($lead['client_id']) ? (int)$lead['client_id'] : 0;
    if ($linkedClientId > 0) {
        $mailWhere[] = "(m.entity_type = 'client' AND m.entity_id = :client_id)";
        $mailWhere[] = "(m.entity_type IS NULL AND m.client_id = :client_id)";
        $mailParams[':client_id'] = $linkedClientId;
    }
    if ($mailEmailSql !== '') {
        $mailWhere[] = '((m.entity_type IS NULL OR m.entity_id IS NULL) AND ' . $mailEmailSql . ')';
        $mailParams = array_merge($mailParams, $mailEmailParams);
    }

    $stmtMail = $pdo->prepare(
        "SELECT m.*
         FROM mail_messages m
         WHERE " . implode(' OR ', $mailWhere) . "
         ORDER BY COALESCE(m.received_at, m.sent_at, m.created_at) DESC
         LIMIT 50"
    );
    $stmtMail->execute($mailParams);
    $mailMessages = $stmtMail->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $messageIds = array_column($mailMessages, 'id');
    if ($messageIds) {
        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmtAtt = $pdo->prepare("SELECT * FROM mail_attachments WHERE mail_message_id IN ($placeholders)");
        $stmtAtt->execute($messageIds);
        $attachments = $stmtAtt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($attachments as $attachment) {
            $msgId = (int)($attachment['mail_message_id'] ?? 0);
            if ($msgId <= 0) {
                continue;
            }
            if (!isset($mailAttachments[$msgId])) {
                $mailAttachments[$msgId] = [];
            }
            $mailAttachments[$msgId][] = $attachment;
        }
    }

    foreach ($mailMessages as $message) {
        $threadId = (int)($message['thread_id'] ?? 0);
        if ($threadId <= 0) {
            continue;
        }
        if (!isset($mailThreads[$threadId])) {
            $mailThreads[$threadId] = [];
            $mailThreadFirst[$threadId] = (int)$message['id'];
        }
        $mailThreads[$threadId][] = $message;
    }
}

$smsMessages = [];
if ($lead) {
    $stmtSms = $pdo->prepare(
        "SELECT * FROM sms_messages
         WHERE entity_type = 'lead' AND entity_id = :id
         ORDER BY created_at DESC
         LIMIT 50"
    );
    $stmtSms->execute([':id' => $leadId]);
    $smsMessages = $stmtSms->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$leadCampaigns = [];
$leadProcessEvents = [];
$leadCampaignCreateUrl = BASE_URL . '/kalkulator_tygodniowy.php';
$kampanieColumns = tableExists($pdo, 'kampanie') ? getTableColumns($pdo, 'kampanie') : [];
if ($lead) {
    $leadCampaignCreateUrl .= '?' . http_build_query([
        'lead_id' => $leadId,
        'lead_name' => (string)($lead['nazwa_firmy'] ?? ''),
        'lead_nip' => (string)($lead['nip'] ?? ''),
    ]);
}
if ($lead && hasColumn($kampanieColumns, 'source_lead_id')) {
    $select = [
        'k.id',
        'k.klient_nazwa',
        'k.data_start',
        'k.data_koniec',
        'k.razem_brutto',
        'k.created_at',
    ];
    if (hasColumn($kampanieColumns, 'propozycja')) {
        $select[] = 'k.propozycja';
    } else {
        $select[] = '0 AS propozycja';
    }
    if (hasColumn($kampanieColumns, 'status')) {
        $select[] = 'k.status';
    } else {
        $select[] = "'' AS status";
    }
    if (hasColumn($kampanieColumns, 'owner_user_id')) {
        $select[] = 'k.owner_user_id';
        $select[] = 'u.login AS owner_login';
        $select[] = 'u.imie AS owner_imie';
        $select[] = 'u.nazwisko AS owner_nazwisko';
    } else {
        $select[] = 'NULL AS owner_user_id';
        $select[] = "'' AS owner_login";
        $select[] = "'' AS owner_imie";
        $select[] = "'' AS owner_nazwisko";
    }
    $sql = 'SELECT ' . implode(', ', $select) . ' FROM kampanie k';
    if (hasColumn($kampanieColumns, 'owner_user_id')) {
        $sql .= ' LEFT JOIN uzytkownicy u ON u.id = k.owner_user_id';
    }
    $sql .= ' WHERE k.source_lead_id = :lead_id ORDER BY k.created_at DESC, k.id DESC LIMIT 100';
    $stmtKamp = $pdo->prepare($sql);
    $stmtKamp->execute([':lead_id' => $leadId]);
    $leadCampaigns = $stmtKamp->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $campaignIdsForTimeline = [];
    foreach ($leadCampaigns as $leadCampaignRow) {
        $campaignId = (int)($leadCampaignRow['id'] ?? 0);
        if ($campaignId > 0) {
            $campaignIdsForTimeline[] = $campaignId;
        }
        if (count($campaignIdsForTimeline) >= 5) {
            break;
        }
    }

    foreach ($campaignIdsForTimeline as $campaignId) {
        try {
            $events = buildCampaignTimelineEvents($pdo, $campaignId, ['limit' => 4]);
            foreach ($events as $event) {
                $event['campaign_id'] = $campaignId;
                $leadProcessEvents[] = $event;
            }
        } catch (Throwable $e) {
            error_log('lead_szczegoly: campaign timeline failed: ' . $e->getMessage());
        }
    }
    usort($leadProcessEvents, static function (array $a, array $b): int {
        $aTs = strtotime((string)($a['czas'] ?? '')) ?: 0;
        $bTs = strtotime((string)($b['czas'] ?? '')) ?: 0;
        return $bTs <=> $aTs;
    });
    $leadProcessEvents = array_slice($leadProcessEvents, 0, 10);
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="content-wide py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">Szczegoly leada</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($lead['nazwa_firmy'] ?? '') ?></p>
        </div>
        <div class="text-muted small">ID: <?= (int)$leadId ?></div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($leadError): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($leadError) ?></div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Szczegoly leada</span>
                        <a href="lead_edytuj.php?id=<?= (int)$leadId ?>" class="btn btn-sm btn-outline-primary">Edytuj</a>
                    </div>
                    <div class="card-body">
                        <div class="mb-2">
                            <div class="text-muted small">Nazwa firmy</div>
                            <div class="fw-semibold"><?= htmlspecialchars($lead['nazwa_firmy'] ?? '') ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">NIP</div>
                            <div><?= htmlspecialchars($lead['nip'] ?? '-') ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">Adres</div>
                            <div><?= htmlspecialchars($leadAddress) ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">Telefon</div>
                            <div><?= htmlspecialchars($lead['telefon'] ?? '-') ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">Email</div>
                            <div><?= htmlspecialchars($lead['email'] ?? '-') ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">Status</div>
                            <div><?= htmlspecialchars(leadStatusLabel((string)($lead['status'] ?? ''))) ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">Opiekun</div>
                            <div><?= htmlspecialchars($ownerLabel) ?></div>
                        </div>
                        <div class="mt-3 pt-2 border-top">
                            <div class="text-muted small mb-2">Osoba kontaktowa</div>
                            <?php if (!$hasLeadContact): ?>
                                <div class="text-muted">Brak danych osoby kontaktowej.</div>
                            <?php else: ?>
                                <div class="mb-2">
                                    <div class="text-muted small">Imie i nazwisko</div>
                                    <div><?= htmlspecialchars($leadContact['kontakt_imie_nazwisko'] !== '' ? $leadContact['kontakt_imie_nazwisko'] : '-') ?></div>
                                </div>
                                <div class="mb-2">
                                    <div class="text-muted small">Stanowisko</div>
                                    <div><?= htmlspecialchars($leadContact['kontakt_stanowisko'] !== '' ? $leadContact['kontakt_stanowisko'] : '-') ?></div>
                                </div>
                                <div class="mb-2">
                                    <div class="text-muted small">Telefon</div>
                                    <div><?= htmlspecialchars($leadContact['kontakt_telefon'] !== '' ? $leadContact['kontakt_telefon'] : '-') ?></div>
                                </div>
                                <div class="mb-2">
                                    <div class="text-muted small">E-mail</div>
                                    <div><?= htmlspecialchars($leadContact['kontakt_email'] !== '' ? $leadContact['kontakt_email'] : '-') ?></div>
                                </div>
                                <div class="mb-2">
                                    <div class="text-muted small">Preferowany kanal</div>
                                    <div>
                                        <?php
                                        $pref = $leadContact['kontakt_preferencja'];
                                        echo htmlspecialchars($pref !== '' ? ($contactPrefLabels[$pref] ?? $pref) : '-');
                                        ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-0">
                            <div class="text-muted small">Utworzono</div>
                            <div><?= htmlspecialchars($lead['created_at'] ?? '-') ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card" id="lead-activity">
                    <div class="card-body">
                        <ul class="nav nav-tabs" id="leadTabs" role="tablist">
                            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#lead-activity-pane">Aktywnosc</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#lead-campaigns-pane">Kampanie</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#lead-mail-pane">Poczta</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#lead-sms-pane">SMS</a></li>
                        </ul>
                        <div class="tab-content pt-3">
                            <div class="tab-pane fade show active" id="lead-activity-pane">
                                <div class="card mb-3">
                                    <div class="card-body">
                                        <h6 class="mb-2">Zaplanuj dzialanie</h6>
                                        <form method="post" class="row g-2 align-items-end">
                                            <input type="hidden" name="crm_action" value="task">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                            <div class="col-md-3">
                                                <label class="form-label">Typ</label>
                                                <select name="task_type" class="form-select" required>
                                                    <option value="">Wybierz</option>
                                                    <option value="telefon">Telefon</option>
                                                    <option value="email">Email</option>
                                                    <option value="sms">SMS</option>
                                                    <option value="spotkanie">Spotkanie</option>
                                                    <option value="inne">Inne</option>
                                                </select>
                                            </div>
                                            <div class="col-md-3">
                                                <label class="form-label">Termin</label>
                                                <input type="datetime-local" name="task_due_at" class="form-control" required>
                                            </div>
                                            <div class="col-md-6">
                                                <label class="form-label">Tytul</label>
                                                <input type="text" name="task_title" class="form-control" maxlength="160" required>
                                            </div>
                                            <div class="col-12">
                                                <label class="form-label">Notatka (opcjonalnie)</label>
                                                <textarea name="task_note" rows="2" class="form-control"></textarea>
                                            </div>
                                            <div class="col-12">
                                                <button type="submit" class="btn btn-success">Zapisz</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                                <div class="row g-3 mb-3">
                                    <div class="col-lg-6">
                                        <div class="card h-100">
                                            <div class="card-body">
                                                <h6 class="mb-2">Status/czynnosc</h6>
                                                <?php if (empty($crmStatuses)): ?>
                                                    <div class="alert alert-info mb-2">Brak zdefiniowanych statusow CRM.</div>
                                                <?php endif; ?>
                                                <form method="post" class="row g-2 align-items-end">
                                                    <input type="hidden" name="crm_action" value="status">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                    <div class="col-12">
                                                        <label class="form-label">Status/czynnosc</label>
                                                        <select name="crm_status_id" class="form-select" <?= empty($crmStatuses) ? 'disabled' : 'required' ?>>
                                                            <option value="">Wybierz z listy</option>
                                                            <?php foreach ($crmStatuses as $status): ?>
                                                                <option value="<?= (int)($status['id'] ?? 0) ?>"><?= htmlspecialchars($status['nazwa'] ?? '') ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-12">
                                                        <label class="form-label">Komentarz (opcjonalnie)</label>
                                                        <input type="text" name="crm_status_comment" class="form-control" maxlength="255">
                                                    </div>
                                                    <div class="col-12">
                                                        <button type="submit" class="btn btn-primary" <?= empty($crmStatuses) ? 'disabled' : '' ?>>Zapisz</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-lg-6">
                                        <div class="card h-100">
                                            <div class="card-body">
                                                <h6 class="mb-2">Notatka</h6>
                                                <form method="post" class="row g-2 align-items-end">
                                                    <input type="hidden" name="crm_action" value="note">
                                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                    <div class="col-12">
                                                        <label class="form-label">Tresc</label>
                                                        <textarea name="crm_note" rows="3" class="form-control" required></textarea>
                                                    </div>
                                                    <div class="col-12">
                                                        <button type="submit" class="btn btn-outline-primary">Dodaj</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-2 text-muted small">Ostatnie aktywności (najnowsze u góry)</div>
                                <?php if (empty($crmActivities)): ?>
                                    <div class="text-muted">Brak aktywności do wyświetlenia.</div>
                                <?php else: ?>
                                    <?php foreach ($crmActivities as $activity): ?>
                                        <?php
                                        $activityType = $activity['typ'] ?? 'system';
                                        $badgeMap = [
                                            'status' => ['label' => 'Status', 'class' => 'bg-primary'],
                                            'notatka' => ['label' => 'Notatka', 'class' => 'bg-info text-dark'],
                                            'mail' => ['label' => 'Mail', 'class' => 'bg-warning text-dark'],
                                            'email_in' => ['label' => 'E-mail IN', 'class' => 'bg-success'],
                                            'email_out' => ['label' => 'E-mail OUT', 'class' => 'bg-warning text-dark'],
                                            'sms_in' => ['label' => 'SMS IN', 'class' => 'bg-success'],
                                            'sms_out' => ['label' => 'SMS OUT', 'class' => 'bg-warning text-dark'],
                                            'system' => ['label' => 'System', 'class' => 'bg-secondary'],
                                        ];
                                        $badge = $badgeMap[$activityType] ?? $badgeMap['system'];
                                        $title = $activityType === 'status'
                                            ? (string)($activity['status_nazwa'] ?? 'Status')
                                            : $badge['label'];
                                        if (in_array($activityType, ['mail', 'system', 'email_in', 'email_out', 'sms_in', 'sms_out'], true) && !empty($activity['temat'])) {
                                            $title = (string)$activity['temat'];
                                        }
                                        $body = '';
                                        if ($activityType === 'status' && !empty($activity['tresc'])) {
                                            $body = (string)$activity['tresc'];
                                        } elseif (in_array($activityType, ['notatka', 'mail', 'system', 'email_in', 'email_out', 'sms_in', 'sms_out'], true)) {
                                            $body = (string)($activity['tresc'] ?? '');
                                        }
                                        $author = trim((string)($activity['imie'] ?? '') . ' ' . (string)($activity['nazwisko'] ?? ''));
                                        if ($author === '') {
                                            $author = (string)($activity['login'] ?? ('User #' . (int)($activity['user_id'] ?? 0)));
                                        }
                                        $createdAt = !empty($activity['created_at']) ? date('d.m.Y H:i', strtotime($activity['created_at'])) : '';
                                        ?>
                                        <div class="card mb-2">
                                            <div class="card-body py-2">
                                                <div class="d-flex justify-content-between align-items-start gap-3">
                                                    <div>
                                                        <span class="badge <?= htmlspecialchars($badge['class']) ?>"><?= htmlspecialchars($badge['label']) ?></span>
                                                        <div class="fw-semibold mt-1"><?= htmlspecialchars($title) ?></div>
                                                        <?php if ($body !== ''): ?>
                                                            <div class="text-muted small"><?= htmlspecialchars($body) ?></div>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="text-end small text-muted">
                                                        <div><?= htmlspecialchars($author) ?></div>
                                                        <div><?= htmlspecialchars($createdAt) ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                <div class="card mt-4">
                                    <div class="card-body">
                                        <h6 class="mb-2">Historia</h6>
                                        <?php if (empty($activityHistory)): ?>
                                            <div class="text-muted">Brak historii dla tego leada.</div>
                                        <?php else: ?>
                                            <?php
                                            $actionLabels = [
                                                'created' => 'Utworzono',
                                                'rescheduled' => 'Przelozono',
                                                'completed' => 'Wykonano',
                                                'canceled' => 'Anulowano',
                                            ];
                                            ?>
                                            <?php foreach ($activityHistory as $entry): ?>
                                                <?php
                                                $action = (string)($entry['action'] ?? '');
                                                $label = $actionLabels[$action] ?? $action;
                                                $message = trim((string)($entry['message'] ?? ''));
                                                $author = trim((string)($entry['imie'] ?? '') . ' ' . (string)($entry['nazwisko'] ?? ''));
                                                if ($author === '') {
                                                    $author = (string)($entry['login'] ?? ('User #' . (int)($entry['user_id'] ?? 0)));
                                                }
                                                $createdAt = !empty($entry['created_at']) ? date('d.m.Y H:i', strtotime($entry['created_at'])) : '';
                                                ?>
                                                <div class="border rounded p-2 mb-2">
                                                    <div class="d-flex justify-content-between align-items-start gap-3">
                                                        <div>
                                                            <div class="fw-semibold"><?= htmlspecialchars($label) ?></div>
                                                            <?php if ($message !== ''): ?>
                                                                <div class="text-muted small"><?= htmlspecialchars($message) ?></div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="text-end small text-muted">
                                                            <div><?= htmlspecialchars($author) ?></div>
                                                            <div><?= htmlspecialchars($createdAt) ?></div>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card mt-4">
                                    <div class="card-body">
                                        <h6 class="mb-2">Ostatnie zdarzenia procesu (kampanie)</h6>
                                        <?php if (empty($leadProcessEvents)): ?>
                                            <div class="text-muted">Brak zdarzeń procesowych powiązanych z kampaniami tego leada.</div>
                                        <?php else: ?>
                                            <?php foreach ($leadProcessEvents as $event): ?>
                                                <?php
                                                $eventTitle = trim((string)($event['tytul'] ?? 'Zdarzenie'));
                                                $eventTime = trim((string)($event['czas'] ?? ''));
                                                $eventType = trim((string)($event['typ'] ?? ''));
                                                $eventCampaignId = (int)($event['campaign_id'] ?? 0);
                                                ?>
                                                <div class="border rounded p-2 mb-2 d-flex justify-content-between align-items-start gap-3">
                                                    <div>
                                                        <div class="fw-semibold"><?= htmlspecialchars($eventTitle) ?></div>
                                                        <div class="small text-muted">#<?= $eventCampaignId ?> | <?= htmlspecialchars($eventType) ?></div>
                                                    </div>
                                                    <div class="text-end">
                                                        <div class="small text-muted"><?= $eventTime !== '' ? htmlspecialchars(date('d.m.Y H:i', strtotime($eventTime))) : '-' ?></div>
                                                        <?php if ($eventCampaignId > 0): ?>
                                                            <a class="small" href="kampania_podglad.php?id=<?= $eventCampaignId ?>">Kampania</a>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="tab-pane fade" id="lead-campaigns-pane">
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                                    <div>
                                        <div class="fw-semibold">Kampanie (mediaplany)</div>
                                        <div class="text-muted small">Lista kampanii zapisanych z kalkulatora dla tego leada.</div>
                                    </div>
                                    <a href="<?= htmlspecialchars($leadCampaignCreateUrl) ?>" class="btn btn-primary btn-sm">Dodaj kampanie</a>
                                </div>

                                <?php if (!hasColumn($kampanieColumns, 'source_lead_id')): ?>
                                    <div class="alert alert-warning mb-0">
                                        Brak kolumny powiazania kampanii z leadem (`source_lead_id`) w tabeli `kampanie`.
                                    </div>
                                <?php elseif (empty($leadCampaigns)): ?>
                                    <div class="text-muted">Brak kampanii przypisanych do tego leada.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-zebra align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Nazwa</th>
                                                    <th>Okres</th>
                                                    <th>Brutto</th>
                                                    <th>Typ</th>
                                                    <th>Opiekun</th>
                                                    <th>Utworzono</th>
                                                    <th>Akcje</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($leadCampaigns as $campaign): ?>
                                                    <?php
                                                    $campaignOwnerLabel = 'Nieprzypisany';
                                                    if (!empty($campaign['owner_user_id'])) {
                                                        $campaignOwnerLabel = trim((string)($campaign['owner_imie'] ?? '') . ' ' . (string)($campaign['owner_nazwisko'] ?? ''));
                                                        if ($campaignOwnerLabel === '') {
                                                            $campaignOwnerLabel = (string)($campaign['owner_login'] ?? ('User #' . (int)$campaign['owner_user_id']));
                                                        }
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td><?= (int)($campaign['id'] ?? 0) ?></td>
                                                        <td><?= htmlspecialchars($campaign['klient_nazwa'] ?? '') ?></td>
                                                        <td><?= htmlspecialchars(($campaign['data_start'] ?? '') . ' - ' . ($campaign['data_koniec'] ?? '')) ?></td>
                                                        <td><?= number_format((float)($campaign['razem_brutto'] ?? 0), 2, ',', ' ') ?> zł</td>
                                                        <td>
                                                            <?php
                                                            $campaignStatusNorm = strtolower(trim((string)($campaign['status'] ?? '')));
                                                            $isProposal = !empty($campaign['propozycja']) || $campaignStatusNorm === 'propozycja';
                                                            ?>
                                                            <?php if ($isProposal): ?>
                                                                <span class="badge bg-warning text-dark">Propozycja</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-success">Aktywna</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= htmlspecialchars($campaignOwnerLabel) ?></td>
                                                        <td><?= htmlspecialchars($campaign['created_at'] ?? '') ?></td>
                                                        <td>
                                                            <a class="btn btn-sm btn-outline-primary" href="kampania_podglad.php?id=<?= (int)($campaign['id'] ?? 0) ?>">Podgląd</a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="tab-pane fade" id="lead-mail-pane">
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                                    <div>
                                        <div class="fw-semibold">Poczta</div>
                                        <?php if ($mailAccount): ?>
                                            <div class="text-muted small">
                                                Skrzynka: <?= htmlspecialchars($mailAccount['email_address'] ?? '') ?>
                                                <?php if (!empty($mailAccount['last_sync_at'])): ?>
                                                    • Ostatnia synchronizacja: <?= htmlspecialchars($mailAccount['last_sync_at']) ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="d-flex gap-2">
                                        <?php if ($mailAccount && $mailSecretError === ''): ?>
                                            <form method="post" action="mail_sync.php" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                <input type="hidden" name="account_id" value="<?= (int)$mailAccount['id'] ?>">
                                                <input type="hidden" name="return_to" value="<?= htmlspecialchars(BASE_URL . '/lead_szczegoly.php?id=' . $leadId . '#lead-mail-pane') ?>">
                                                <button type="submit" class="btn btn-outline-primary btn-sm">Synchronizuj</button>
                                            </form>
                                        <?php endif; ?>
                                        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#lead-mail-compose" aria-expanded="<?= $mailComposeAutoOpen ? 'true' : 'false' ?>">
                                            Nowa wiadomosc
                                        </button>
                                    </div>
                                </div>

                                <?php if (!$mailAccount && $mailAccountInactive): ?>
                                    <div class="alert alert-warning">Konto pocztowe jest wyłączone (is_active=0). Skontaktuj się z administratorem.</div>
                                <?php elseif (!$mailAccount): ?>
                                    <div class="alert alert-warning">Brak skonfigurowanego konta pocztowego dla uzytkownika.</div>
                                <?php elseif ($mailSecretError !== ''): ?>
                                    <div class="alert alert-danger"><?= htmlspecialchars($mailSecretError) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($mailSyncFlash)): ?>
                                    <div class="alert alert-<?= htmlspecialchars($mailSyncFlash['type'] ?? 'info') ?>">
                                        <?= htmlspecialchars($mailSyncFlash['msg'] ?? '') ?>
                                    </div>
                                <?php endif; ?>

                                <div class="collapse mb-3 <?= $mailComposeAutoOpen ? 'show' : '' ?>" id="lead-mail-compose">
                                    <div class="card">
                                        <div class="card-body">
                                            <form method="post" enctype="multipart/form-data" class="row g-3">
                                                <input type="hidden" name="crm_action" value="mail_send">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                <input type="hidden" name="offer_campaign_id" value="<?= (int)$offerCampaignId ?>">
                                                <div class="col-12">
                                                    <label class="form-label">Do</label>
                                                    <input type="text" name="mail_to" class="form-control" value="<?= htmlspecialchars($defaultMailTo) ?>" placeholder="adres@email.pl" required>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Temat</label>
                                                    <input type="text" name="mail_subject" class="form-control" maxlength="255" value="<?= htmlspecialchars($mailDraftSubject) ?>" required>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Wiadomosc</label>
                                                    <textarea name="mail_message" rows="6" class="form-control" required><?= htmlspecialchars($mailDraftBody) ?></textarea>
                                                </div>
                                                <?php if ($offerCampaignId > 0): ?>
                                                    <div class="col-12">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox" id="offerIncludeMediaplan" name="offer_include_mediaplan" value="1" <?= $mailDraftIncludeMediaplan ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="offerIncludeMediaplan">
                                                                Dolacz mediaplan PDF do oferty
                                                            </label>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="col-12">
                                                    <label class="form-label">Załączniki</label>
                                                    <input type="file" name="mail_attachments[]" class="form-control" multiple>
                                                    <div class="text-muted small mt-1">Maksymalny rozmiar załącznika: 10 MB.</div>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-success" <?= (!$mailAccount || $mailSecretError !== '') ? 'disabled' : '' ?>>Wyślij</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>

                                <?php if (empty($mailMessages)): ?>
                                    <div class="text-muted">Brak wiadomości do wyświetlenia.</div>
                                <?php else: ?>
                                    <?php foreach ($mailMessages as $message): ?>
                                        <?php
                                        $direction = strtolower((string)($message['direction'] ?? ''));
                                        $isOut = $direction === 'out';
                                        $directionLabel = $isOut ? 'OUT' : 'IN';
                                        $directionClass = $isOut ? 'bg-warning text-dark' : 'bg-success';
                                        $dateVal = $message['received_at'] ?: ($message['sent_at'] ?: $message['created_at']);
                                        $subjectVal = trim((string)($message['subject'] ?? ''));
                                        $subjectVal = $subjectVal !== '' ? $subjectVal : '(bez tematu)';
                                        $bodyText = trim((string)($message['body_text'] ?? ''));
                                        $bodyHtml = trim((string)($message['body_html'] ?? ''));
                                        $body = $bodyText !== '' ? $bodyText : trim(strip_tags($bodyHtml));
                                        $body = $body !== '' ? $body : '(brak treści)';
                                        $preview = substr($body, 0, 160);
                                        if (strlen($body) > 160) {
                                            $preview .= '...';
                                        }
                                        $msgId = (int)$message['id'];
                                        $threadId = (int)($message['thread_id'] ?? 0);
                                        $threadCount = $threadId > 0 && !empty($mailThreads[$threadId]) ? count($mailThreads[$threadId]) : 0;
                                        ?>
                                        <div class="card mb-2">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start gap-3">
                                                    <div>
                                                        <span class="badge <?= htmlspecialchars($directionClass) ?>"><?= htmlspecialchars($directionLabel) ?></span>
                                                        <div class="text-muted small mt-1"><?= htmlspecialchars($dateVal ?? '') ?></div>
                                                        <div class="fw-semibold"><?= htmlspecialchars($subjectVal) ?></div>
                                                        <div class="text-muted small"><?= htmlspecialchars($preview) ?></div>
                                                    </div>
                                                    <div class="text-end">
                                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#lead-mail-body-<?= $msgId ?>">
                                                            Rozwiń
                                                        </button>
                                                        <?php if ($threadCount > 1 && (($mailThreadFirst[$threadId] ?? 0) === $msgId)): ?>
                                                            <button class="btn btn-sm btn-outline-primary ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#lead-thread-<?= $threadId ?>">
                                                                Wątek (<?= (int)$threadCount ?>)
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="collapse mt-3" id="lead-mail-body-<?= $msgId ?>">
                                                    <div class="border rounded p-3 bg-light" style="white-space: pre-wrap;"><?= htmlspecialchars($body) ?></div>
                                                    <?php if (!empty($mailAttachments[$msgId])): ?>
                                                        <div class="mt-3">
                                                            <div class="fw-semibold">Załączniki</div>
                                                            <ul class="mb-0">
                                                                <?php foreach ($mailAttachments[$msgId] as $attachment): ?>
                                                                    <li>
                                                                        <a href="mail_attachment_download.php?id=<?= (int)$attachment['id'] ?>">
                                                                            <?= htmlspecialchars($attachment['filename'] ?? 'załącznik') ?>
                                                                        </a>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if ($threadCount > 1 && (($mailThreadFirst[$threadId] ?? 0) === $msgId)): ?>
                                                    <div class="collapse mt-3" id="lead-thread-<?= $threadId ?>">
                                                        <div class="border rounded p-3">
                                                            <div class="fw-semibold mb-2">Wątek</div>
                                                            <ul class="mb-0">
                                                                <?php foreach ($mailThreads[$threadId] as $threadMessage): ?>
                                                                    <?php
                                                                    $threadDirection = strtolower((string)($threadMessage['direction'] ?? ''));
                                                                    $threadLabel = $threadDirection === 'out' ? 'OUT' : 'IN';
                                                                    $threadDate = $threadMessage['received_at'] ?: ($threadMessage['sent_at'] ?: $threadMessage['created_at']);
                                                                    $threadSubject = trim((string)($threadMessage['subject'] ?? ''));
                                                                    $threadSubject = $threadSubject !== '' ? $threadSubject : '(bez tematu)';
                                                                    ?>
                                                                    <li>
                                                                        <span class="text-muted small"><?= htmlspecialchars($threadDate ?? '') ?></span>
                                                                        <span class="badge bg-light text-dark ms-1"><?= htmlspecialchars($threadLabel) ?></span>
                                                                        <span class="ms-1"><?= htmlspecialchars($threadSubject) ?></span>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <div class="tab-pane fade" id="lead-sms-pane">
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                                    <div class="fw-semibold">SMS</div>
                                    <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#lead-sms-compose" aria-expanded="false">
                                        Nowy SMS
                                    </button>
                                </div>
                                <div class="collapse mb-3" id="lead-sms-compose">
                                    <div class="card">
                                        <div class="card-body">
                                            <form method="post" class="row g-3">
                                                <input type="hidden" name="crm_action" value="sms_send">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                <div class="col-12">
                                                    <label class="form-label">Telefon</label>
                                                    <input type="text" name="sms_phone" class="form-control" value="<?= htmlspecialchars($defaultSmsPhone) ?>" required>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Tresc</label>
                                                    <textarea name="sms_content" rows="4" class="form-control" required></textarea>
                                                </div>
                                                <div class="col-12">
                                                    <button type="submit" class="btn btn-success">Zapisz</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <?php if (empty($smsMessages)): ?>
                                    <div class="text-muted">Brak wiadomości SMS.</div>
                                <?php else: ?>
                                    <?php foreach ($smsMessages as $sms): ?>
                                        <?php
                                        $smsDirection = strtolower((string)($sms['direction'] ?? 'out'));
                                        $smsLabel = $smsDirection === 'in' ? 'IN' : 'OUT';
                                        $smsClass = $smsDirection === 'in' ? 'bg-success' : 'bg-warning text-dark';
                                        ?>
                                        <div class="card mb-2">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start gap-3">
                                                    <div>
                                                        <span class="badge <?= htmlspecialchars($smsClass) ?>"><?= htmlspecialchars($smsLabel) ?></span>
                                                        <div class="text-muted small mt-1"><?= htmlspecialchars($sms['created_at'] ?? '') ?></div>
                                                        <div class="fw-semibold"><?= htmlspecialchars($sms['phone'] ?? '') ?></div>
                                                        <div class="text-muted small"><?= htmlspecialchars($sms['content'] ?? '') ?></div>
                                                    </div>
                                                    <div class="text-end small text-muted">
                                                        <div><?= htmlspecialchars($sms['status'] ?? '') ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var hash = window.location.hash;
    if (!hash) {
        return;
    }
    var trigger = document.querySelector('a[data-bs-toggle="tab"][href="' + hash + '"]');
    if (trigger && window.bootstrap && typeof bootstrap.Tab === 'function') {
        new bootstrap.Tab(trigger).show();
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
