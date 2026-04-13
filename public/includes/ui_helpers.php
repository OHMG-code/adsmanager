<?php
declare(strict_types=1);

require_once __DIR__ . '/lead_pipeline_helpers.php';
require_once __DIR__ . '/briefs.php';

function renderBadge(string $type, string $label): string {
    $label = trim($label);
    $class = 'badge badge-neutral';

    switch ($type) {
        case 'lead_status':
            $normalizedLead = normalizeLeadStatus($label);
            $map = [
                'nowy' => 'badge-neutral',
                'w_kontakcie' => 'badge-warning',
                'oferta_wyslana' => 'badge-warning',
                'analiza_potrzeb' => 'badge-primary',
                'oferta_przygotowywana' => 'badge-primary',
                'oferta_zaakceptowana' => 'badge-success',
                'skonwertowany' => 'badge-success',
                'odrzucony' => 'badge-danger',
                'zakonczony' => 'badge-neutral',
            ];
            $class = $map[$normalizedLead] ?? 'badge-neutral';
            break;
        case 'kampania_status':
            $normalizedCampaign = normalizeCampaignStatus($label);
            $map = [
                'propozycja' => 'badge-warning',
                'zamowiona' => 'badge-warning',
                'w_realizacji' => 'badge-success',
                'zakonczona' => 'badge-neutral',
            ];
            $class = $map[$normalizedCampaign] ?? 'badge-neutral';
            break;
        case 'audio_status':
            $normalizedAudio = normalizeAudioStatus($label);
            $map = [
                'robocza' => 'badge-warning',
                'wyslana_do_klienta' => 'badge-warning',
                'zaakceptowana' => 'badge-success',
                'odrzucona' => 'badge-danger',
            ];
            $class = $map[$normalizedAudio] ?? ($label === 'Brak audio' ? 'badge-warning' : 'badge-neutral');
            break;
        case 'priority':
            $map = [
                'Pilny' => 'badge-danger',
                'Wysoki' => 'badge-warning',
                'Średni' => 'badge-neutral',
                'Niski' => 'badge-neutral',
            ];
            $class = $map[$label] ?? 'badge-neutral';
            break;
        case 'client_status':
            $map = [
                'Aktywny' => 'badge-success',
                'W trakcie rozmów' => 'badge-warning',
                'Nowy' => 'badge-neutral',
            ];
            $class = $map[$label] ?? 'badge-neutral';
            break;
        default:
            $class = 'badge badge-neutral';
    }

    return sprintf('<span class="badge %s">%s</span>', htmlspecialchars($class), htmlspecialchars($label !== '' ? $label : '—'));
}
