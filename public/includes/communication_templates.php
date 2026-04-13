<?php
declare(strict_types=1);

function communicationTemplateBriefLink(array $ctx): array
{
    $campaignId = (int)($ctx['campaign_id'] ?? 0);
    $clientName = trim((string)($ctx['client_name'] ?? 'Klient'));
    $briefUrl = trim((string)($ctx['brief_url'] ?? ''));
    $senderLabel = trim((string)($ctx['sender_label'] ?? 'Zespół CRM'));

    $subject = 'Brief kampanii #' . $campaignId . ' - prośba o uzupełnienie';
    $body = "Dzien dobry,\n\n";
    $body .= "dla kampanii #" . $campaignId . " (" . $clientName . ") prosimy o uzupelnienie briefu pod ponizszym linkiem:\n";
    $body .= $briefUrl . "\n\n";
    $body .= "Po zapisaniu briefu system automatycznie poinformuje opiekuna.\n\n";
    $body .= "Pozdrawiamy,\n" . ($senderLabel !== '' ? $senderLabel : 'Zespół CRM');

    return ['subject' => $subject, 'body' => $body];
}

function communicationTemplateAudioDispatchClient(array $ctx): array
{
    $campaignId = (int)($ctx['campaign_id'] ?? 0);
    $spotId = (int)($ctx['spot_id'] ?? 0);
    $versionsCount = (int)($ctx['versions_count'] ?? 0);
    $senderLabel = trim((string)($ctx['sender_label'] ?? 'Zespół CRM'));

    $subject = 'Wersje audio kampanii #' . $campaignId . ' zostały wysłane';
    $body = "Dzien dobry,\n\n";
    $body .= 'przesłaliśmy wersje audio do kampanii #' . $campaignId . '.';
    if ($spotId > 0) {
        $body .= ' Spot #' . $spotId . '.';
    }
    if ($versionsCount > 0) {
        $body .= ' Liczba wersji: ' . $versionsCount . '.';
    }
    $body .= "\n\nW razie uwag prosimy o informację zwrotną.\n\n";
    $body .= "Pozdrawiamy,\n" . ($senderLabel !== '' ? $senderLabel : 'Zespół CRM');

    return ['subject' => $subject, 'body' => $body];
}

function communicationTemplateInternal(string $eventType, array $ctx = []): array
{
    $campaignId = (int)($ctx['campaign_id'] ?? 0);
    $spotId = (int)($ctx['spot_id'] ?? 0);
    $map = [
        'brief_received' => 'Otrzymano brief od klienta',
        'production_started' => 'Rozpoczęto produkcję spotu',
        'audio_dispatched' => 'Wersje audio oznaczono jako wysłane do klienta',
        'audio_final_selected' => 'Wybrano finalną wersję audio',
        'campaign_ready_for_emission' => 'Kampania gotowa do emisji',
    ];
    $title = $map[$eventType] ?? 'Zdarzenie procesowe';
    if ($campaignId > 0) {
        $title .= ' | kampania #' . $campaignId;
    }
    if ($spotId > 0) {
        $title .= ' | spot #' . $spotId;
    }

    return [
        'subject' => $title,
        'body' => $title,
    ];
}
