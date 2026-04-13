<?php
declare(strict_types=1);

require_once __DIR__ . '/tasks.php';
require_once __DIR__ . '/communication_events.php';
require_once __DIR__ . '/briefs.php';

function taskDefaultDueAt(string $kind, ?string $baseAt = null): string
{
    $tz = new DateTimeZone('Europe/Warsaw');
    try {
        $base = $baseAt ? new DateTimeImmutable($baseAt, $tz) : new DateTimeImmutable('now', $tz);
    } catch (Throwable $e) {
        $base = new DateTimeImmutable('now', $tz);
    }

    $date = $base;
    $time = '10:00:00';
    switch ($kind) {
        case 'brief_received_production':
            $date = $base->modify('+1 day');
            $time = '09:00:00';
            break;
        case 'audio_dispatched_waiting':
            $date = $base->modify('+3 days');
            $time = '10:00:00';
            break;
        case 'offer_sent_followup':
            $date = $base->modify('+2 days');
            $time = '10:00:00';
            break;
        case 'alert_production_overdue':
        case 'alert_audio_no_response':
            $date = $base->modify('+1 day');
            $time = '09:00:00';
            break;
    }

    return $date->format('Y-m-d') . ' ' . $time;
}

function taskResolveCampaignAssignee(PDO $pdo, int $campaignId, array $preferred = []): int
{
    if ($campaignId <= 0) {
        return 0;
    }
    $users = communicationResolveCampaignUsers($pdo, $campaignId);
    foreach ($preferred as $field) {
        $userId = (int)($users[$field] ?? 0);
        if ($userId > 0) {
            return $userId;
        }
    }
    foreach (['campaign_owner_user_id', 'production_owner_user_id', 'lead_owner_user_id'] as $field) {
        $userId = (int)($users[$field] ?? 0);
        if ($userId > 0) {
            return $userId;
        }
    }
    return 0;
}

function createEventTask(PDO $pdo, string $eventType, array $context): array
{
    $eventType = trim($eventType);
    $campaignId = (int)($context['campaign_id'] ?? 0);
    $leadId = (int)($context['lead_id'] ?? 0);
    $briefId = (int)($context['brief_id'] ?? 0);
    $dispatchId = (int)($context['dispatch_id'] ?? 0);
    $commEventId = (int)($context['communication_event_id'] ?? 0);
    $eventAt = trim((string)($context['event_at'] ?? ''));
    $actorUserId = !empty($context['actor_user_id']) ? (int)$context['actor_user_id'] : null;

    $payload = [];
    if ($eventType === 'offer_sent') {
        $assignee = $campaignId > 0
            ? taskResolveCampaignAssignee($pdo, $campaignId, ['lead_owner_user_id', 'campaign_owner_user_id'])
            : 0;
        if ($assignee <= 0 && $actorUserId) {
            $assignee = $actorUserId;
        }
        if ($assignee <= 0) {
            return ['ok' => false, 'error' => 'Brak osoby odpowiedzialnej dla taska follow-up oferty.'];
        }

        $relatedType = $leadId > 0 ? 'lead' : 'kampania';
        $relatedId = $leadId > 0 ? $leadId : $campaignId;
        if ($relatedId <= 0) {
            return ['ok' => false, 'error' => 'Brak obiektu powiązanego dla offer_sent task.'];
        }

        $keyPart = $commEventId > 0 ? 'comm:' . $commEventId : ('campaign:' . $campaignId . ':lead:' . $leadId);
        $payload = [
            'type' => 'email',
            'title' => 'Follow-up po wysłaniu oferty',
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'assigned_user_id' => $assignee,
            'due_at' => taskDefaultDueAt('offer_sent_followup', $eventAt),
            'status' => 'OPEN',
            'description' => 'Skontaktuj się z klientem po wysłaniu oferty.',
            'external_key' => 'evt:offer_sent_followup:' . $keyPart,
            'require_external_key' => true,
            'created_by_user_id' => $actorUserId,
            'log_activity' => false,
        ];
    } elseif ($eventType === 'brief_received') {
        $assignee = $campaignId > 0
            ? taskResolveCampaignAssignee($pdo, $campaignId, ['production_owner_user_id', 'campaign_owner_user_id', 'lead_owner_user_id'])
            : 0;
        if ($assignee <= 0) {
            return ['ok' => false, 'error' => 'Brak osoby odpowiedzialnej dla taska produkcji.'];
        }
        if ($campaignId <= 0) {
            return ['ok' => false, 'error' => 'Brak kampanii dla brief_received task.'];
        }

        $keyPart = $briefId > 0 ? 'brief:' . $briefId : ('comm:' . $commEventId);
        $payload = [
            'type' => 'inne',
            'title' => 'Rozpocznij produkcję po otrzymaniu briefu',
            'related_type' => 'kampania',
            'related_id' => $campaignId,
            'assigned_user_id' => $assignee,
            'due_at' => taskDefaultDueAt('brief_received_production', $eventAt),
            'status' => 'OPEN',
            'description' => 'Brief został otrzymany. Zweryfikuj dane i rozpocznij produkcję.',
            'external_key' => 'evt:brief_received_production:' . $keyPart,
            'require_external_key' => true,
            'created_by_user_id' => $actorUserId,
            'log_activity' => false,
        ];
    } elseif ($eventType === 'audio_dispatched') {
        $assignee = $campaignId > 0
            ? taskResolveCampaignAssignee($pdo, $campaignId, ['campaign_owner_user_id', 'lead_owner_user_id', 'production_owner_user_id'])
            : 0;
        if ($assignee <= 0) {
            return ['ok' => false, 'error' => 'Brak osoby odpowiedzialnej dla taska audio.'];
        }
        if ($campaignId <= 0) {
            return ['ok' => false, 'error' => 'Brak kampanii dla audio_dispatched task.'];
        }

        $keyPart = $dispatchId > 0 ? 'dispatch:' . $dispatchId : ('comm:' . $commEventId);
        $payload = [
            'type' => 'inne',
            'title' => 'Oczekiwanie na decyzję klienta (audio)',
            'related_type' => 'kampania',
            'related_id' => $campaignId,
            'assigned_user_id' => $assignee,
            'due_at' => taskDefaultDueAt('audio_dispatched_waiting', $eventAt),
            'status' => 'OPEN',
            'description' => 'Wersje audio zostały wysłane. Sprawdź odpowiedź klienta.',
            'external_key' => 'evt:audio_dispatched_waiting:' . $keyPart,
            'require_external_key' => true,
            'created_by_user_id' => $actorUserId,
            'log_activity' => false,
        ];
    } else {
        return ['ok' => false, 'error' => 'Nieobslugiwany event task: ' . $eventType];
    }

    return createTask($pdo, $payload);
}

function syncAlertTasks(PDO $pdo, array $options = []): array
{
    ensureKampanieOwnershipColumns($pdo);
    ensureCommunicationEventsTable($pdo);
    ensureCrmTasksTable($pdo);

    $productionDays = max(1, (int)($options['production_days'] ?? 7));
    $audioDays = max(1, (int)($options['audio_days'] ?? 5));
    $actorUserId = !empty($options['actor_user_id']) ? (int)$options['actor_user_id'] : null;
    $created = 0;
    $closed = 0;
    $activeProdKeys = [];
    $activeAudioKeys = [];

    try {
        $stmtProd = $pdo->prepare("SELECT k.id AS campaign_id,
                COALESCE(ce.event_at, k.created_at) AS base_at
            FROM kampanie k
            LEFT JOIN (
                SELECT campaign_id, MAX(created_at) AS event_at
                FROM communication_events
                WHERE event_type = 'production_started' AND status IN ('logged','sent')
                GROUP BY campaign_id
            ) ce ON ce.campaign_id = k.id
            WHERE k.realization_status = 'w_produkcji'
              AND TIMESTAMPDIFF(DAY, COALESCE(ce.event_at, k.created_at), NOW()) > :days");
        $stmtProd->execute([':days' => $productionDays]);
        while ($row = $stmtProd->fetch(PDO::FETCH_ASSOC)) {
            $campaignId = (int)($row['campaign_id'] ?? 0);
            if ($campaignId <= 0) {
                continue;
            }
            $externalKey = 'alrt:production_overdue:campaign:' . $campaignId;
            $activeProdKeys[$externalKey] = true;
            $assignee = taskResolveCampaignAssignee($pdo, $campaignId, ['production_owner_user_id', 'campaign_owner_user_id', 'lead_owner_user_id']);
            if ($assignee <= 0) {
                continue;
            }
            $result = createTask($pdo, [
                'type' => 'inne',
                'title' => 'Alert: produkcja trwa zbyt długo',
                'related_type' => 'kampania',
                'related_id' => $campaignId,
                'assigned_user_id' => $assignee,
                'due_at' => taskDefaultDueAt('alert_production_overdue', (string)($row['base_at'] ?? '')),
                'status' => 'OPEN',
                'description' => 'Kampania jest w produkcji dłużej niż ' . $productionDays . ' dni.',
                'external_key' => $externalKey,
                'require_external_key' => true,
                'skip_if_external_exists' => true,
                'created_by_user_id' => $actorUserId,
                'log_activity' => false,
            ]);
            if (!empty($result['ok']) && empty($result['duplicate'])) {
                $created++;
            }
        }

        $stmtAudio = $pdo->prepare("SELECT k.id AS campaign_id,
                COALESCE(ce.event_at, k.created_at) AS base_at
            FROM kampanie k
            LEFT JOIN (
                SELECT campaign_id, MAX(created_at) AS event_at
                FROM communication_events
                WHERE event_type = 'audio_dispatched' AND status IN ('logged','sent')
                GROUP BY campaign_id
            ) ce ON ce.campaign_id = k.id
            WHERE k.realization_status = 'wersje_wyslane'
              AND TIMESTAMPDIFF(DAY, COALESCE(ce.event_at, k.created_at), NOW()) > :days");
        $stmtAudio->execute([':days' => $audioDays]);
        while ($row = $stmtAudio->fetch(PDO::FETCH_ASSOC)) {
            $campaignId = (int)($row['campaign_id'] ?? 0);
            if ($campaignId <= 0) {
                continue;
            }
            $externalKey = 'alrt:audio_no_response:campaign:' . $campaignId;
            $activeAudioKeys[$externalKey] = true;
            $assignee = taskResolveCampaignAssignee($pdo, $campaignId, ['campaign_owner_user_id', 'lead_owner_user_id', 'production_owner_user_id']);
            if ($assignee <= 0) {
                continue;
            }
            $result = createTask($pdo, [
                'type' => 'inne',
                'title' => 'Alert: brak odpowiedzi na wysłane audio',
                'related_type' => 'kampania',
                'related_id' => $campaignId,
                'assigned_user_id' => $assignee,
                'due_at' => taskDefaultDueAt('alert_audio_no_response', (string)($row['base_at'] ?? '')),
                'status' => 'OPEN',
                'description' => 'Brak decyzji klienta po wysyłce wersji audio dłużej niż ' . $audioDays . ' dni.',
                'external_key' => $externalKey,
                'require_external_key' => true,
                'skip_if_external_exists' => true,
                'created_by_user_id' => $actorUserId,
                'log_activity' => false,
            ]);
            if (!empty($result['ok']) && empty($result['duplicate'])) {
                $created++;
            }
        }

        $stmtOpenAlerts = $pdo->query("SELECT id, external_key
            FROM crm_zadania
            WHERE status = 'OPEN'
              AND external_key IS NOT NULL
              AND (external_key LIKE 'alrt:production_overdue:%' OR external_key LIKE 'alrt:audio_no_response:%')");
        while ($task = $stmtOpenAlerts->fetch(PDO::FETCH_ASSOC)) {
            $taskId = (int)($task['id'] ?? 0);
            $externalKey = trim((string)($task['external_key'] ?? ''));
            if ($taskId <= 0 || $externalKey === '') {
                continue;
            }
            $keep = false;
            if (str_starts_with($externalKey, 'alrt:production_overdue:')) {
                $keep = !empty($activeProdKeys[$externalKey]);
            } elseif (str_starts_with($externalKey, 'alrt:audio_no_response:')) {
                $keep = !empty($activeAudioKeys[$externalKey]);
            }
            if ($keep) {
                continue;
            }
            $stmtClose = $pdo->prepare("UPDATE crm_zadania SET status = 'CANCELLED', done_at = NOW() WHERE id = :id AND status = 'OPEN'");
            $stmtClose->execute([':id' => $taskId]);
            if ($stmtClose->rowCount() > 0) {
                $closed++;
            }
        }
    } catch (Throwable $e) {
        error_log('task_automation: syncAlertTasks failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage(), 'created' => $created, 'closed' => $closed];
    }

    return ['ok' => true, 'created' => $created, 'closed' => $closed];
}
