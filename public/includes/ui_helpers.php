<?php
declare(strict_types=1);

function renderBadge(string $type, string $label): string {
    $label = trim($label);
    $class = 'badge badge-neutral';

    switch ($type) {
        case 'lead_status':
            $map = [
                'Nowy' => 'badge-neutral',
                'Kontakt podjęty' => 'badge-warning',
                'W kontakcie' => 'badge-warning',
                'Oferta wysłana' => 'badge-warning',
                'Negocjacje' => 'badge-warning',
                'Wygrana' => 'badge-success',
                'Skonwertowany' => 'badge-success',
                'Przegrana' => 'badge-danger',
                'Odrzucony' => 'badge-danger',
                'Zakończony' => 'badge-neutral',
            ];
            $class = $map[$label] ?? 'badge-neutral';
            break;
        case 'kampania_status':
            $map = [
                'Zamówiona' => 'badge-warning',
                'W realizacji' => 'badge-success',
                'Zakończona' => 'badge-neutral',
            ];
            $class = $map[$label] ?? 'badge-neutral';
            break;
        case 'audio_status':
            $map = [
                'Do akceptacji' => 'badge-warning',
                'Zaakceptowany' => 'badge-success',
                'Odrzucony' => 'badge-danger',
                'Brak audio' => 'badge-warning',
            ];
            $class = $map[$label] ?? 'badge-neutral';
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
