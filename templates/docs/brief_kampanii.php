<style>
  body { font-family: dejavusans, sans-serif; color: #111827; font-size: 10pt; }
  h1 { font-size: 18pt; margin-bottom: 8px; }
  h2 { font-size: 12pt; margin: 18px 0 8px; }
  table { width: 100%; border-collapse: collapse; }
  td, th { border: 1px solid #d1d5db; padding: 6px; vertical-align: top; }
  .muted { color: #6b7280; }
</style>

<h1>Brief kampanii</h1>
<div class="muted">Numer dokumentu: <?= htmlspecialchars((string)$docNumber) ?> | Data: <?= htmlspecialchars((string)$docDate) ?></div>

<h2>Kampania</h2>
<table>
  <tr>
    <th style="width: 30%;">Klient</th>
    <td><?= htmlspecialchars((string)($client['nazwa_firmy'] ?? $campaign['klient_nazwa'] ?? '')) ?></td>
  </tr>
  <tr>
    <th>ID kampanii</th>
    <td>#<?= (int)($campaign['id'] ?? 0) ?></td>
  </tr>
  <tr>
    <th>Okres</th>
    <td><?= htmlspecialchars(formatDateForDoc((string)($campaign['data_start'] ?? ''))) ?> - <?= htmlspecialchars(formatDateForDoc((string)($campaign['data_koniec'] ?? ''))) ?></td>
  </tr>
  <tr>
    <th>Status briefu</th>
    <td><?= htmlspecialchars((string)$briefStatusLabel) ?></td>
  </tr>
  <tr>
    <th>Status realizacji</th>
    <td><?= htmlspecialchars((string)$realizationStatusLabel) ?></td>
  </tr>
</table>

<h2>Dane produkcyjne</h2>
<table>
  <tr><th style="width: 30%;">Długość spotu</th><td><?= (int)($brief['spot_length_seconds'] ?? 0) ?> s</td></tr>
  <tr><th>Liczba lektorów</th><td><?= htmlspecialchars((string)($brief['lector_count'] ?? '')) ?></td></tr>
  <tr><th>Grupa docelowa</th><td><?= nl2br(htmlspecialchars((string)($brief['target_group'] ?? ''))) ?></td></tr>
  <tr><th>Najważniejsza informacja</th><td><?= nl2br(htmlspecialchars((string)($brief['main_message'] ?? ''))) ?></td></tr>
  <tr><th>Dodatkowe informacje</th><td><?= nl2br(htmlspecialchars((string)($brief['additional_info'] ?? ''))) ?></td></tr>
  <tr><th>Dane teleadresowe</th><td><?= nl2br(htmlspecialchars((string)($brief['contact_details'] ?? ''))) ?></td></tr>
  <tr><th>Charakter reklamy</th><td><?= nl2br(htmlspecialchars((string)($brief['tone_style'] ?? ''))) ?></td></tr>
  <tr><th>Efekty dźwiękowe</th><td><?= nl2br(htmlspecialchars((string)($brief['sound_effects'] ?? ''))) ?></td></tr>
  <tr><th>Uwagi</th><td><?= nl2br(htmlspecialchars((string)($brief['notes'] ?? ''))) ?></td></tr>
  <tr><th>Opiekun produkcji</th><td><?= htmlspecialchars((string)($brief['production_owner_user_id'] ?? '')) ?></td></tr>
  <tr><th>Studio zewnętrzne</th><td><?= htmlspecialchars((string)($brief['production_external_studio'] ?? '')) ?></td></tr>
</table>
