<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/crm_activity.php';
require_once __DIR__ . '/includes/tasks.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/mailbox_service.php';
require_once __DIR__ . '/includes/gus_validation.php';
require_once __DIR__ . '/includes/company_view_model.php';
require_once __DIR__ . '/includes/process_timeline.php';
require_once __DIR__ . '/../services/gus_queue_status.php';
require_once __DIR__ . '/../config/config.php';

requireLogin();
$currentUser = fetchCurrentUser($pdo);
if (!$currentUser) {
    header('Location: ' . BASE_URL . '/logout.php');
    exit;
}

$role = normalizeRole($currentUser);
$canRefreshGus = in_array($role, ['Administrator', 'Manager'], true) || isSuperAdmin();
$canForceGusRefresh = $canRefreshGus;

$pageTitle = 'Szczegoly klienta';
ensureClientLeadColumns($pdo);
ensureKampanieOwnershipColumns($pdo);
ensureKampanieSalesValueColumn($pdo);
$clientColumns = getTableColumns($pdo, 'klienci');
ensureCrmMailTables($pdo);
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
$clientId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$client = null;
$clientError = '';
$companyVm = null;

if ($clientId <= 0) {
    $clientError = 'Brak identyfikatora klienta.';
    http_response_code(400);
} else {
    $stmt = $pdo->prepare('SELECT * FROM klienci WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$client || !canAccessCrmObject('klient', $clientId, $currentUser)) {
        $clientError = 'Brak dostępu do klienta lub nie istnieje.';
        http_response_code(403);
        $client = null;
    }
}

$flash = $_SESSION['client_detail_flash'] ?? null;
unset($_SESSION['client_detail_flash']);
$mailSyncFlash = $_SESSION['mail_sync_flash'] ?? null;
unset($_SESSION['mail_sync_flash']);

$gusSnapshots = [];
$gusLastRefreshAt = null;
$gusLastStatus = null;
$companyIdForGus = 0;
$companyRow = null;

if ($client) {
    try {
        ensureCompaniesTable($pdo);
        ensureGusSnapshotsTable($pdo);

        $identifiers = [];
        $nipValue = '';
        $regonValue = '';
        $krsValue = '';
        if (!empty($client['nip'])) {
            $nipValue = normalizeNip((string)$client['nip']);
            if ($nipValue !== '') {
                $identifiers[] = $nipValue;
            }
        }
        if (!empty($client['regon'])) {
            $regonValue = normalizeRegon((string)$client['regon']);
            if ($regonValue !== '') {
                $identifiers[] = $regonValue;
            }
        }
        if (!empty($client['krs'])) {
            $krsValue = normalizeKrs((string)$client['krs']);
            if ($krsValue !== '') {
                $identifiers[] = $krsValue;
            }
        }

        if (!empty($client['company_id'])) {
            $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => (int)$client['company_id']]);
            $companyRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif ($nipValue !== '') {
            $stmt = $pdo->prepare('SELECT * FROM companies WHERE nip = :nip LIMIT 1');
            $stmt->execute([':nip' => $nipValue]);
            $companyRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } elseif ($regonValue !== '') {
            $stmt = $pdo->prepare('SELECT * FROM companies WHERE regon = :regon LIMIT 1');
            $stmt->execute([':regon' => $regonValue]);
            $companyRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        if ($companyRow) {
            $companyIdForGus = (int)($companyRow['id'] ?? 0);
            $gusLastRefreshAt = $companyRow['gus_last_refresh_at'] ?? null;
            $gusLastStatus = $companyRow['gus_last_status'] ?? null;
        }

        $identifiers = array_values(array_unique($identifiers));
        $where = [];
        $params = [];
        if ($companyIdForGus > 0) {
            $where[] = 'company_id = ?';
            $params[] = $companyIdForGus;
        }
        if ($identifiers) {
            $placeholders = implode(',', array_fill(0, count($identifiers), '?'));
            $where[] = "request_value IN ($placeholders)";
            $params = array_merge($params, $identifiers);
        }
        if ($where) {
            $stmt = $pdo->prepare('SELECT * FROM gus_snapshots WHERE ' . implode(' OR ', $where) . ' ORDER BY created_at DESC LIMIT 20');
            $stmt->execute($params);
            $gusSnapshots = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (Throwable $e) {
        $gusSnapshots = [];
    }
}

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

function campaignStatusKey(?string $status): string
{
    $status = strtolower(trim((string)$status));
    $status = str_replace('_', ' ', $status);
    return $status;
}

function campaignIsProposal(array $campaign): bool
{
    $statusKey = campaignStatusKey((string)($campaign['status'] ?? ''));
    return !empty($campaign['propozycja']) || $statusKey === 'propozycja';
}

function campaignIsTransaction(array $campaign): bool
{
    if (campaignIsProposal($campaign)) {
        return false;
    }
    $statusKey = campaignStatusKey((string)($campaign['status'] ?? ''));
    if ($statusKey === '') {
        return true;
    }
    return in_array($statusKey, ['zamowiona', 'zamówiona', 'w realizacji', 'zakończona', 'zakonczona', 'aktywna'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['crm_action'])) {
    $action = trim((string)($_POST['crm_action'] ?? ''));
    $token = $_POST['csrf_token'] ?? '';
    $flash = null;

    if (!isCsrfTokenValid($token)) {
        $flash = ['type' => 'danger', 'msg' => 'Niepoprawny token CSRF.'];
    } elseif (!$client || !canAccessCrmObject('klient', $clientId, $currentUser)) {
        $flash = ['type' => 'danger', 'msg' => 'Brak dostępu do klienta.'];
    } else {
        switch ($action) {
            case 'status':
                $statusId = isset($_POST['crm_status_id']) ? (int)$_POST['crm_status_id'] : 0;
                $comment = trim((string)($_POST['crm_status_comment'] ?? ''));
                if ($statusId <= 0) {
                    $flash = ['type' => 'warning', 'msg' => 'Wybierz status/czynnosc.'];
                    break;
                }
                $availableStatuses = getStatusy('klient');
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
                if (!addActivity('klient', $clientId, 'status', (int)$currentUser['id'], $comment, $statusId)) {
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
                if (!addActivity('klient', $clientId, 'notatka', (int)$currentUser['id'], $note)) {
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
                if ($client && hasColumn($clientColumns, 'owner_user_id') && !empty($client['owner_user_id'])) {
                    $taskOwnerId = (int)$client['owner_user_id'];
                } elseif ($client && hasColumn($clientColumns, 'assigned_user_id') && !empty($client['assigned_user_id'])) {
                    $taskOwnerId = (int)$client['assigned_user_id'];
                }
                if (!addTask('klient', $clientId, $taskType, $taskTitle, $taskDueAt, $taskOwnerId, (int)$currentUser['id'], $taskNote)) {
                    $flash = ['type' => 'danger', 'msg' => 'Nie udało się zapisać działania.'];
                    break;
                }
                $flash = ['type' => 'success', 'msg' => 'Działanie zostało zaplanowane.'];
                break;
            case 'mail_send':
                $toRaw = trim((string)($_POST['mail_to'] ?? ''));
                $subject = trim((string)($_POST['mail_subject'] ?? ''));
                $body = trim((string)($_POST['mail_message'] ?? ''));
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
                $threadId = mailboxFindOrCreateThread($pdo, 'client', $clientId, $subject, $now);
                $mailMessageId = mailboxInsertMessage($pdo, [
                    'client_id' => $clientId,
                    'lead_id' => null,
                    'campaign_id' => null,
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
                    'entity_type' => 'client',
                    'entity_id' => $clientId,
                    'created_by_user_id' => (int)$currentUser['id'],
                ]);

                $attachmentErrors = [];
                $storedAttachments = [];
                if ($mailMessageId > 0 && !empty($_FILES['mail_attachments'])) {
                    $storedAttachments = mailboxStoreUploadedAttachments($pdo, $mailMessageId, 'client', $clientId, $_FILES['mail_attachments'], 10 * 1024 * 1024, $attachmentErrors);
                    if ($storedAttachments) {
                        $stmtUp = $pdo->prepare('UPDATE mail_messages SET has_attachments = 1 WHERE id = :id');
                        $stmtUp->execute([':id' => $mailMessageId]);
                    }
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
                addActivity('klient', $clientId, 'email_out', (int)$currentUser['id'], $snippet !== '' ? $snippet : null, null, $subject);
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
                    ':entity_type' => 'client',
                    ':entity_id' => $clientId,
                    ':user_id' => (int)$currentUser['id'],
                    ':direction' => 'out',
                    ':phone' => $phone,
                    ':content' => $content,
                    ':provider' => null,
                    ':provider_message_id' => null,
                    ':status' => 'queued',
                ]);
                $smsSnippet = substr($content, 0, 180);
                addActivity('klient', $clientId, 'sms_out', (int)$currentUser['id'], $smsSnippet !== '' ? $smsSnippet : null, null, 'SMS');
                $flash = ['type' => 'success', 'msg' => 'SMS został zapisany do wysyłki.'];
                break;

            default:
                $flash = ['type' => 'danger', 'msg' => 'Nieznana akcja CRM.'];
        }
    }

    $_SESSION['client_detail_flash'] = $flash;
    $anchor = '#client-activity';
    if (in_array($action, ['mail_send'], true)) {
        $anchor = '#client-mail-pane';
    } elseif (in_array($action, ['sms_send'], true)) {
        $anchor = '#client-sms-pane';
    }
    header('Location: ' . BASE_URL . '/klient_szczegoly.php?id=' . $clientId . $anchor);
    exit;
}

$crmStatuses = $client ? getStatusy('klient') : [];
$crmActivities = $client ? getActivities('klient', $clientId) : [];
$activityHistory = $client ? getActivityHistory($pdo, 'klient', $clientId, 20, true) : [];
$ownerLabel = 'Nieprzypisany';
if ($client && hasColumn($clientColumns, 'owner_user_id') && !empty($client['owner_user_id'])) {
    $stmtOwner = $pdo->prepare('SELECT login, imie, nazwisko FROM uzytkownicy WHERE id = :id');
    $stmtOwner->execute([':id' => (int)$client['owner_user_id']]);
    $ownerRow = $stmtOwner->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($ownerRow) {
        $ownerLabel = trim((string)($ownerRow['imie'] ?? '') . ' ' . (string)($ownerRow['nazwisko'] ?? ''));
        if ($ownerLabel === '') {
            $ownerLabel = $ownerRow['login'] ?? ('User #' . (int)$client['owner_user_id']);
        }
    } else {
        $ownerLabel = 'User #' . (int)$client['owner_user_id'];
    }
}
$gusStatus = [];
if ($client && !empty($client['company_id'])) {
    $statusSvc = new GusQueueStatus($pdo);
    $gusStatus = $statusSvc->getForCompanyId((int)$client['company_id']);
}
$companyVm = $client ? buildCompanyViewModel($client, $companyRow, $gusStatus) : null;
if ($companyVm && $client) {
    $legacyName = trim((string)($client['nazwa_firmy'] ?? ''));
    if ($legacyName !== '') {
        $companyVm['company_name_display'] = $legacyName;
    }

    $legacyNip = trim((string)($client['nip'] ?? ''));
    if ($legacyNip !== '') {
        $companyVm['nip_display'] = $legacyNip;
    }

    $legacyRegon = trim((string)($client['regon'] ?? ''));
    if ($legacyRegon !== '') {
        $companyVm['regon_display'] = $legacyRegon;
    }

    $legacyAddress = trim((string)($client['adres'] ?? ''));
    $legacyCity = trim((string)($client['miejscowosc'] ?? ($client['miasto'] ?? '')));
    $legacyWoj = trim((string)($client['wojewodztwo'] ?? ''));
    $legacyAddressParts = [];
    if ($legacyAddress !== '') {
        $legacyAddressParts[] = $legacyAddress;
    }
    if ($legacyCity !== '') {
        $legacyAddressParts[] = $legacyCity;
        $companyVm['address_city_display'] = $legacyCity;
    }
    if ($legacyWoj !== '') {
        $legacyAddressParts[] = $legacyWoj;
        $companyVm['address_wojewodztwo_display'] = $legacyWoj;
    }
    if ($legacyAddressParts) {
        $companyVm['address_display'] = implode(', ', $legacyAddressParts);
    }
}
$clientAddress = $companyVm['address_display'] ?? '-';
$contactPrefLabels = [
    'telefon' => 'Telefon',
    'email' => 'E-mail',
    'sms' => 'SMS',
];
$clientContact = [
    'kontakt_imie_nazwisko' => $client ? trim((string)($client['kontakt_imie_nazwisko'] ?? '')) : '',
    'kontakt_stanowisko' => $client ? trim((string)($client['kontakt_stanowisko'] ?? '')) : '',
    'kontakt_telefon' => $client ? trim((string)($client['kontakt_telefon'] ?? '')) : '',
    'kontakt_email' => $client ? trim((string)($client['kontakt_email'] ?? '')) : '',
    'kontakt_preferencja' => $client ? trim((string)($client['kontakt_preferencja'] ?? '')) : '',
];
$hasClientContact = false;
foreach ($clientContact as $value) {
    if ($value !== '') {
        $hasClientContact = true;
        break;
    }
}


$defaultMailTo = '';
if ($clientContact['kontakt_email'] !== '') {
    $defaultMailTo = $clientContact['kontakt_email'];
} elseif (!empty($client['email'])) {
    $defaultMailTo = (string)$client['email'];
}

$defaultSmsPhone = '';
if ($clientContact['kontakt_telefon'] !== '') {
    $defaultSmsPhone = $clientContact['kontakt_telefon'];
} elseif (!empty($client['telefon'])) {
    $defaultSmsPhone = (string)$client['telefon'];
}

$mailMessages = [];
$mailAttachments = [];
$mailThreads = [];
$mailThreadFirst = [];
$mailMessageColumns = getTableColumns($pdo, 'mail_messages');
$hasLeadsTable = tableExists($pdo, 'leady');
if ($client) {
    $clientMailEmails = mailboxNormalizeEmailCandidates([
        (string)($client['email'] ?? ''),
        $clientContact['kontakt_email'],
    ]);
    [$mailEmailSql, $mailEmailParams] = mailboxBuildMessageEmailMatchSql($mailMessageColumns, $clientMailEmails, 'm', 'client_msg');

    $mailWhere = [
        "(m.entity_type = 'client' AND m.entity_id = :client_id)",
        "(m.entity_type IS NULL AND m.client_id = :client_id)",
    ];
    if ($hasLeadsTable) {
        $mailWhere[] = "(m.entity_type = 'lead' AND EXISTS (SELECT 1 FROM leady l WHERE l.id = m.entity_id AND l.client_id = :client_id))";
        $mailWhere[] = "(m.entity_type IS NULL AND m.lead_id IS NOT NULL AND EXISTS (SELECT 1 FROM leady l WHERE l.id = m.lead_id AND l.client_id = :client_id))";
    }
    $mailParams = [':client_id' => $clientId];
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
if ($client) {
    $stmtSms = $pdo->prepare(
        "SELECT * FROM sms_messages
         WHERE entity_type = 'client' AND entity_id = :id
         ORDER BY created_at DESC
         LIMIT 50"
    );
    $stmtSms->execute([':id' => $clientId]);
    $smsMessages = $stmtSms->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$clientCampaignCreateUrl = BASE_URL . '/kalkulator_tygodniowy.php';
if ($client) {
    $clientNameForCampaign = trim((string)($client['nazwa_firmy'] ?? ''));
    if ($clientNameForCampaign === '') {
        $clientNameForCampaign = trim((string)($companyVm['company_name_display'] ?? ''));
    }
    $clientCampaignCreateUrl .= '?' . http_build_query([
        'client_id' => $clientId,
        'client_name' => $clientNameForCampaign,
        'client_nip' => (string)($client['nip'] ?? ''),
    ]);
}

$clientCampaigns = [];
$clientProcessEvents = [];
$clientTransactionSummary = [
    'count' => 0,
    'sum_netto' => 0.0,
    'sum_brutto' => 0.0,
    'latest_at' => null,
];
if ($client && tableExists($pdo, 'kampanie')) {
    $kampanieColumns = getTableColumns($pdo, 'kampanie');
    $select = [
        'k.id',
        'k.klient_id',
        'k.klient_nazwa',
        'k.data_start',
        'k.data_koniec',
        'k.razem_netto',
        'k.razem_brutto',
        'k.created_at',
    ];
    if (hasColumn($kampanieColumns, 'status')) {
        $select[] = 'k.status';
    } else {
        $select[] = "'' AS status";
    }
    if (hasColumn($kampanieColumns, 'propozycja')) {
        $select[] = 'k.propozycja';
    } else {
        $select[] = '0 AS propozycja';
    }
    if (hasColumn($kampanieColumns, 'wartosc_netto')) {
        $select[] = 'k.wartosc_netto';
    } else {
        $select[] = '0 AS wartosc_netto';
    }
    if (hasColumn($kampanieColumns, 'source_lead_id')) {
        $select[] = 'k.source_lead_id';
    } else {
        $select[] = 'NULL AS source_lead_id';
    }

    $where = ['k.klient_id = :client_id'];
    $params = [':client_id' => $clientId];

    if (hasColumn($kampanieColumns, 'source_lead_id') && tableExists($pdo, 'leady')) {
        $leadStmt = $pdo->prepare('SELECT id FROM leady WHERE client_id = :client_id');
        $leadStmt->execute([':client_id' => $clientId]);
        $leadIds = array_map('intval', $leadStmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
        if (!empty($leadIds)) {
            $leadPlaceholders = [];
            foreach ($leadIds as $idx => $leadIdVal) {
                $ph = ':lead_id_' . $idx;
                $leadPlaceholders[] = $ph;
                $params[$ph] = $leadIdVal;
            }
            $where[] = '(COALESCE(k.klient_id, 0) = 0 AND k.source_lead_id IN (' . implode(', ', $leadPlaceholders) . '))';
        }
    }

    $stmtCampaigns = $pdo->prepare(
        'SELECT ' . implode(', ', $select)
        . ' FROM kampanie k WHERE ' . implode(' OR ', $where)
        . ' ORDER BY k.created_at DESC, k.id DESC LIMIT 200'
    );
    $stmtCampaigns->execute($params);
    $campaignRows = $stmtCampaigns->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $seenCampaignIds = [];
    foreach ($campaignRows as $campaignRow) {
        $campaignRowId = (int)($campaignRow['id'] ?? 0);
        if ($campaignRowId <= 0 || isset($seenCampaignIds[$campaignRowId])) {
            continue;
        }
        $seenCampaignIds[$campaignRowId] = true;

        $transactionNetto = (float)($campaignRow['wartosc_netto'] ?? 0);
        if ($transactionNetto <= 0) {
            $transactionNetto = (float)($campaignRow['razem_netto'] ?? 0);
        }
        $campaignRow['transaction_netto'] = $transactionNetto;
        $campaignRow['transaction_brutto'] = (float)($campaignRow['razem_brutto'] ?? 0);
        $campaignRow['is_proposal'] = campaignIsProposal($campaignRow);
        $campaignRow['is_transaction'] = campaignIsTransaction($campaignRow);
        $clientCampaigns[] = $campaignRow;

        if (!$campaignRow['is_transaction']) {
            continue;
        }
        $clientTransactionSummary['count']++;
        $clientTransactionSummary['sum_netto'] += (float)$campaignRow['transaction_netto'];
        $clientTransactionSummary['sum_brutto'] += (float)$campaignRow['transaction_brutto'];
        $createdAt = trim((string)($campaignRow['created_at'] ?? ''));
        if ($createdAt !== '') {
            if ($clientTransactionSummary['latest_at'] === null || $createdAt > $clientTransactionSummary['latest_at']) {
                $clientTransactionSummary['latest_at'] = $createdAt;
            }
        }
    }

    $campaignIdsForTimeline = [];
    foreach ($clientCampaigns as $clientCampaignRow) {
        $campaignId = (int)($clientCampaignRow['id'] ?? 0);
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
                $clientProcessEvents[] = $event;
            }
        } catch (Throwable $e) {
            error_log('klient_szczegoly: campaign timeline failed: ' . $e->getMessage());
        }
    }
    usort($clientProcessEvents, static function (array $a, array $b): int {
        $aTs = strtotime((string)($a['czas'] ?? '')) ?: 0;
        $bTs = strtotime((string)($b['czas'] ?? '')) ?: 0;
        return $bTs <=> $aTs;
    });
    $clientProcessEvents = array_slice($clientProcessEvents, 0, 10);
}

require_once __DIR__ . '/includes/header.php';
?>

<main class="content-wide py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 mb-1">Szczegoly klienta</h1>
            <p class="text-muted mb-0"><?= htmlspecialchars($companyVm['company_name_display'] ?? '') ?></p>
        </div>
        <div class="text-muted small">ID: <?= (int)$clientId ?></div>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($clientError): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($clientError) ?></div>
    <?php else: ?>
        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <span>Szczegoly klienta</span>
                        <div class="d-flex gap-2">
                            <a href="klient_edytuj.php?id=<?= (int)$clientId ?>" class="btn btn-sm btn-outline-primary">Edytuj</a>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                            <div class="text-muted small" id="gusRefreshMeta">
                                Ostatnie odświeżenie z GUS:
                                <span id="gusLastRefreshAt"><?= htmlspecialchars($gusLastRefreshAt ?? '-') ?></span>
                                <span class="mx-1">·</span>
                                Status:
                                <?php
                                $statusLabel = $gusLastStatus ? strtoupper((string)$gusLastStatus) : '-';
                                $statusClass = $statusLabel === 'OK' ? 'bg-success' : ($statusLabel === '-' ? 'bg-secondary' : 'bg-danger');
                                ?>
                                <span id="gusLastStatus" class="badge <?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars($statusLabel) ?></span>
                                <span class="mx-1">·</span>
                                Źródło danych:
                                <span id="gusLastRefreshSource"><?= htmlspecialchars($gusLastRefreshAt ? 'GUS' : '-') ?></span>
                            </div>
                            <?php if ($canForceGusRefresh): ?>
                                <div class="d-flex gap-2">
                                    <button type="button" class="btn btn-sm btn-outline-success" id="gusRefreshBtn" data-client-id="<?= (int)$clientId ?>">
                                        Odśwież z GUS
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-warning" id="gusForceRefreshBtn" data-client-id="<?= (int)$clientId ?>">
                                        Wymuś odświeżenie
                                    </button>
                                    <?php if (($companyVm['gus_queue_state'] ?? '') === 'failed' || ($companyVm['gus_queue_state'] ?? '') === 'cancelled'): ?>
                                        <form method="post" action="handlers/gus_requeue_one.php" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                            <input type="hidden" name="client_id" value="<?= (int)$clientId ?>">
                                            <input type="hidden" name="company_id" value="<?= (int)($client['company_id'] ?? 0) ?>">
                                            <button class="btn btn-sm btn-outline-primary" type="submit">Ponów (GUS)</button>
                                        </form>
                                    <?php elseif (($companyVm['gus_queue_state'] ?? '') === 'pending' || ($companyVm['gus_queue_state'] ?? '') === 'running'): ?>
                                        <span class="badge bg-info text-dark">W kolejce</span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($companyVm['gus_display_line'])): ?>
                            <div class="text-muted small mb-2"><?= htmlspecialchars($companyVm['gus_display_line']) ?></div>
                        <?php endif; ?>
                        <div id="gusRefreshAlert" class="alert d-none" role="alert"></div>
                        <div id="gusDiffTableWrapper" class="table-responsive d-none mb-3">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Pole</th>
                                        <th>Było</th>
                                        <th>Jest</th>
                                        <th>Zastosowano?</th>
                                    </tr>
                                </thead>
                                <tbody id="gusDiffTableBody"></tbody>
                            </table>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">Nazwa firmy</div>
                            <div class="fw-semibold"><?= htmlspecialchars($client['nazwa_firmy'] ?? '') ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">NIP</div>
                            <div><?= htmlspecialchars($companyVm['nip_display'] ?? '-') ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">Adres</div>
                            <div><?= htmlspecialchars($clientAddress) ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">Telefon</div>
                            <div><?= htmlspecialchars($client['telefon'] ?? '-') ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">Email</div>
                            <div><?= htmlspecialchars($client['email'] ?? '-') ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">Status</div>
                            <div><?= htmlspecialchars($client['status'] ?? '-') ?></div>
                        </div>
                        <div class="mb-2">
                            <div class="text-muted small">Opiekun</div>
                            <div><?= htmlspecialchars($ownerLabel) ?></div>
                        </div>
                        <div class="mt-3 pt-2 border-top">
                            <div class="text-muted small mb-2">Transakcje</div>
                            <div class="mb-2">
                                <div class="text-muted small">Liczba transakcji</div>
                                <div class="fw-semibold"><?= (int)($clientTransactionSummary['count'] ?? 0) ?></div>
                            </div>
                            <div class="mb-2">
                                <div class="text-muted small">Suma netto</div>
                                <div><?= number_format((float)($clientTransactionSummary['sum_netto'] ?? 0), 2, ',', ' ') ?> zł</div>
                            </div>
                            <div class="mb-2">
                                <div class="text-muted small">Suma brutto</div>
                                <div><?= number_format((float)($clientTransactionSummary['sum_brutto'] ?? 0), 2, ',', ' ') ?> zł</div>
                            </div>
                            <div class="mb-0">
                                <div class="text-muted small">Ostatnia transakcja</div>
                                <div><?= htmlspecialchars((string)($clientTransactionSummary['latest_at'] ?? '-')) ?></div>
                            </div>
                        </div>
                        <div class="mt-3 pt-2 border-top">
                            <div class="text-muted small mb-2">Osoba kontaktowa</div>
                            <?php if (!$hasClientContact): ?>
                                <div class="text-muted">Brak danych osoby kontaktowej.</div>
                            <?php else: ?>
                                <div class="mb-2">
                                    <div class="text-muted small">Imie i nazwisko</div>
                                    <div><?= htmlspecialchars($clientContact['kontakt_imie_nazwisko'] !== '' ? $clientContact['kontakt_imie_nazwisko'] : '-') ?></div>
                                </div>
                                <div class="mb-2">
                                    <div class="text-muted small">Stanowisko</div>
                                    <div><?= htmlspecialchars($clientContact['kontakt_stanowisko'] !== '' ? $clientContact['kontakt_stanowisko'] : '-') ?></div>
                                </div>
                                <div class="mb-2">
                                    <div class="text-muted small">Telefon</div>
                                    <div><?= htmlspecialchars($clientContact['kontakt_telefon'] !== '' ? $clientContact['kontakt_telefon'] : '-') ?></div>
                                </div>
                                <div class="mb-2">
                                    <div class="text-muted small">E-mail</div>
                                    <div><?= htmlspecialchars($clientContact['kontakt_email'] !== '' ? $clientContact['kontakt_email'] : '-') ?></div>
                                </div>
                                <div class="mb-2">
                                    <div class="text-muted small">Preferowany kanal</div>
                                    <div>
                                        <?php
                                        $pref = $clientContact['kontakt_preferencja'];
                                        echo htmlspecialchars($pref !== '' ? ($contactPrefLabels[$pref] ?? $pref) : '-');
                                        ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-0">
                            <div class="text-muted small">Utworzono</div>
                            <div><?= htmlspecialchars($client['data_dodania'] ?? '-') ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="card" id="client-activity">
                    <div class="card-body">
                        <ul class="nav nav-tabs" id="clientTabs" role="tablist">
                            <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#client-activity-pane">Aktywnosc</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#client-transactions-pane">Transakcje</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#client-mail-pane">Poczta</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#client-sms-pane">SMS</a></li>
                            <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#client-gus-pane">GUS</a></li>
                        </ul>
                        <div class="tab-content pt-3">
                            <div class="tab-pane fade show active" id="client-activity-pane">
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
                                            <div class="text-muted">Brak historii dla tego klienta.</div>
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
                                        <?php if (empty($clientProcessEvents)): ?>
                                            <div class="text-muted">Brak zdarzeń procesowych powiązanych z kampaniami klienta.</div>
                                        <?php else: ?>
                                            <?php foreach ($clientProcessEvents as $event): ?>
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
                            <div class="tab-pane fade" id="client-transactions-pane">
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                                    <div>
                                        <div class="fw-semibold">Transakcje i kampanie</div>
                                        <div class="text-muted small">Powiązane kampanie oraz wartości transakcji klienta.</div>
                                    </div>
                                    <a href="<?= htmlspecialchars($clientCampaignCreateUrl) ?>" class="btn btn-primary btn-sm">Dodaj kampanię</a>
                                </div>

                                <?php if (empty($clientCampaigns)): ?>
                                    <div class="text-muted">Brak kampanii/transakcji przypisanych do tego klienta.</div>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-zebra align-middle mb-0">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>ID</th>
                                                    <th>Okres</th>
                                                    <th>Status</th>
                                                    <th>Typ</th>
                                                    <th>Netto</th>
                                                    <th>Brutto</th>
                                                    <th>Utworzono</th>
                                                    <th>Akcje</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($clientCampaigns as $campaign): ?>
                                                    <?php
                                                    $statusLabel = trim((string)($campaign['status'] ?? ''));
                                                    if ($statusLabel === '') {
                                                        $statusLabel = $campaign['is_proposal'] ? 'Propozycja' : 'W realizacji';
                                                    }
                                                    ?>
                                                    <tr>
                                                        <td><?= (int)($campaign['id'] ?? 0) ?></td>
                                                        <td><?= htmlspecialchars((string)($campaign['data_start'] ?? '') . ' - ' . (string)($campaign['data_koniec'] ?? '')) ?></td>
                                                        <td><?= htmlspecialchars($statusLabel) ?></td>
                                                        <td>
                                                            <?php if (!empty($campaign['is_proposal'])): ?>
                                                                <span class="badge bg-warning text-dark">Propozycja</span>
                                                            <?php elseif (!empty($campaign['is_transaction'])): ?>
                                                                <span class="badge bg-success">Transakcja</span>
                                                            <?php else: ?>
                                                                <span class="badge bg-secondary">Kampania</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><?= number_format((float)($campaign['transaction_netto'] ?? 0), 2, ',', ' ') ?> zł</td>
                                                        <td><?= number_format((float)($campaign['transaction_brutto'] ?? 0), 2, ',', ' ') ?> zł</td>
                                                        <td><?= htmlspecialchars((string)($campaign['created_at'] ?? '')) ?></td>
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
                            <div class="tab-pane fade" id="client-mail-pane">
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
                                                <input type="hidden" name="return_to" value="<?= htmlspecialchars(BASE_URL . '/klient_szczegoly.php?id=' . $clientId . '#client-mail-pane') ?>">
                                                <button type="submit" class="btn btn-outline-primary btn-sm">Synchronizuj</button>
                                            </form>
                                        <?php endif; ?>
                                        <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#client-mail-compose" aria-expanded="false">
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

                                <div class="collapse mb-3" id="client-mail-compose">
                                    <div class="card">
                                        <div class="card-body">
                                            <form method="post" enctype="multipart/form-data" class="row g-3">
                                                <input type="hidden" name="crm_action" value="mail_send">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
                                                <div class="col-12">
                                                    <label class="form-label">Do</label>
                                                    <input type="text" name="mail_to" class="form-control" value="<?= htmlspecialchars($defaultMailTo) ?>" placeholder="adres@email.pl" required>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Temat</label>
                                                    <input type="text" name="mail_subject" class="form-control" maxlength="255" required>
                                                </div>
                                                <div class="col-12">
                                                    <label class="form-label">Wiadomość</label>
                                                    <textarea name="mail_message" rows="6" class="form-control" required></textarea>
                                                </div>
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
                                                        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#client-mail-body-<?= $msgId ?>">
                                                            Rozwiń
                                                        </button>
                                                        <?php if ($threadCount > 1 && (($mailThreadFirst[$threadId] ?? 0) === $msgId)): ?>
                                                            <button class="btn btn-sm btn-outline-primary ms-1" type="button" data-bs-toggle="collapse" data-bs-target="#client-thread-<?= $threadId ?>">
                                                                Wątek (<?= (int)$threadCount ?>)
                                                            </button>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="collapse mt-3" id="client-mail-body-<?= $msgId ?>">
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
                                                    <div class="collapse mt-3" id="client-thread-<?= $threadId ?>">
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
                            <div class="tab-pane fade" id="client-sms-pane">
                                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                                    <div class="fw-semibold">SMS</div>
                                    <button class="btn btn-primary btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#client-sms-compose" aria-expanded="false">
                                        Nowy SMS
                                    </button>
                                </div>
                                <div class="collapse mb-3" id="client-sms-compose">
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
                            <div class="tab-pane fade" id="client-gus-pane">
                                <div class="card">
                                    <div class="card-body">
                                        <h6 class="mb-3">Snapshoty GUS</h6>
                                        <?php if (empty($gusSnapshots)): ?>
                                            <div class="text-muted">Brak snapshotów dla tego kontrahenta.</div>
                                        <?php else: ?>
                                            <div class="table-responsive">
                                                <table class="table table-sm table-striped align-middle mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th>Data</th>
                                                            <th>Env</th>
                                                            <th>Typ</th>
                                                            <th>Wartość</th>
                                                            <th>Raport</th>
                                                            <th>HTTP</th>
                                                            <th>Status</th>
                                                            <th>Błąd</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($gusSnapshots as $snap): ?>
                                                            <tr>
                                                                <td><?= htmlspecialchars($snap['created_at'] ?? '') ?></td>
                                                                <td><?= htmlspecialchars($snap['env'] ?? '') ?></td>
                                                                <td><?= htmlspecialchars($snap['request_type'] ?? '') ?></td>
                                                                <td><?= htmlspecialchars($snap['request_value'] ?? '') ?></td>
                                                                <td><?= htmlspecialchars($snap['report_type'] ?? '') ?></td>
                                                                <td><?= htmlspecialchars((string)($snap['http_code'] ?? '')) ?></td>
                                                                <td>
                                                                    <?php if (!empty($snap['ok'])): ?>
                                                                        <span class="badge bg-success">OK</span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-danger">Błąd</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td><?= htmlspecialchars($snap['error_message'] ?? '') ?></td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
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
<script>
document.addEventListener('DOMContentLoaded', function () {
    var refreshBtn = document.getElementById('gusRefreshBtn');
    var forceBtn = document.getElementById('gusForceRefreshBtn');
    if (!refreshBtn && !forceBtn) {
        return;
    }
    var alertBox = document.getElementById('gusRefreshAlert');
    var diffWrapper = document.getElementById('gusDiffTableWrapper');
    var diffBody = document.getElementById('gusDiffTableBody');
    var lastRefreshAt = document.getElementById('gusLastRefreshAt');
    var lastRefreshSource = document.getElementById('gusLastRefreshSource');
    var lastStatusBadge = document.getElementById('gusLastStatus');
    var baseUrl = '<?= htmlspecialchars(BASE_URL) ?>';
    var csrfToken = '<?= htmlspecialchars(getCsrfToken()) ?>';

    function showAlert(message, type) {
        if (!alertBox) {
            return;
        }
        alertBox.textContent = message;
        alertBox.className = 'alert alert-' + type;
        alertBox.classList.remove('d-none');
    }

    function renderDiff(diff) {
        if (!diffWrapper || !diffBody) {
            return;
        }
        diffBody.innerHTML = '';
        if (!Array.isArray(diff) || diff.length === 0) {
            diffWrapper.classList.add('d-none');
            return;
        }
        diff.forEach(function (row) {
            var tr = document.createElement('tr');
            var applied = row.applied ? 'tak' : 'nie';
            var appliedBadge = row.applied ? 'bg-success' : (row.locked ? 'bg-warning text-dark' : 'bg-secondary');
            tr.innerHTML = '<td>' + (row.field || '') + '</td>'
                + '<td>' + (row.old === null ? '' : String(row.old)) + '</td>'
                + '<td>' + (row.new === null ? '' : String(row.new)) + '</td>'
                + '<td><span class=\"badge ' + appliedBadge + '\">' + applied + '</span></td>';
            diffBody.appendChild(tr);
        });
        diffWrapper.classList.remove('d-none');
    }

    function setStatusBadge(status) {
        if (!lastStatusBadge) {
            return;
        }
        var label = String(status || '-').toUpperCase();
        lastStatusBadge.textContent = label;
        lastStatusBadge.classList.remove('bg-success', 'bg-danger', 'bg-secondary');
        if (label === 'OK') {
            lastStatusBadge.classList.add('bg-success');
        } else if (label === '-' || label === '') {
            lastStatusBadge.classList.add('bg-secondary');
        } else {
            lastStatusBadge.classList.add('bg-danger');
        }
    }

    function parseJsonResponse(response) {
        return response.text().then(function (text) {
            var trimmed = String(text || '').replace(/^\uFEFF/, '').trim();
            var first = trimmed.charAt(0);
            if (first !== '{' && first !== '[') {
                throw new Error('Serwer GUS zwrócił nie-JSON.');
            }

            var data;
            try {
                data = JSON.parse(trimmed);
            } catch (err) {
                throw new Error('Niepoprawny JSON z backendu GUS.');
            }

            if (!response.ok) {
                var message = data && data.error && typeof data.error === 'object' && data.error.message
                    ? data.error.message
                    : (data && typeof data.error === 'string'
                        ? data.error
                        : (data && data.message ? data.message : 'Błąd odświeżania danych.'));
                throw new Error(message);
            }

            return data;
        });
    }

    function runRefresh(force) {
        var btn = force ? forceBtn : refreshBtn;
        if (!btn) {
            return;
        }
        var clientId = btn.getAttribute('data-client-id');
        if (!clientId) {
            return;
        }
        if (refreshBtn) {
            refreshBtn.disabled = true;
        }
        if (forceBtn) {
            forceBtn.disabled = true;
        }
        showAlert('Pobieranie danych z GUS...', 'info');
        if (diffWrapper) {
            diffWrapper.classList.add('d-none');
        }

        var payload = new URLSearchParams();
        payload.set('csrf_token', csrfToken);
        if (force) {
            payload.set('force', '1');
        }

        fetch(baseUrl + '/companies/' + encodeURIComponent(clientId) + '/refresh-gus', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload.toString()
        })
            .then(parseJsonResponse)
            .then(function (data) {
                showAlert(data.message || 'Dane z GUS zostały zaktualizowane.', 'success');
                renderDiff(data.diff || []);
                if (lastRefreshAt && data.last_refresh_at) {
                    lastRefreshAt.textContent = data.last_refresh_at;
                }
                if (lastRefreshSource && data.source) {
                    lastRefreshSource.textContent = String(data.source).toUpperCase();
                }
                setStatusBadge('OK');
            })
            .catch(function (err) {
                showAlert(err.message || 'Nie udało się odświeżyć danych z GUS.', 'danger');
                setStatusBadge('ERROR');
            })
            .finally(function () {
                if (refreshBtn) {
                    refreshBtn.disabled = false;
                }
                if (forceBtn) {
                    forceBtn.disabled = false;
                }
            });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            runRefresh(false);
        });
    }
    if (forceBtn) {
        forceBtn.addEventListener('click', function () {
            runRefresh(true);
        });
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
