<?php
declare(strict_types=1);

require_once __DIR__ . '/db_schema.php';

function audioSourceTypeDefinitions(): array
{
    return [
        'produced_by_radio' => 'Produkcja przez radio',
        'provided_by_client' => 'Spot dostarczony przez klienta',
    ];
}

function normalizeAudioSourceType(?string $type): string
{
    $type = trim((string)$type);
    return array_key_exists($type, audioSourceTypeDefinitions()) ? $type : 'produced_by_radio';
}

function clientAudioStatusDefinitions(): array
{
    return [
        'oczekuje_na_plik' => ['label' => 'Oczekuje na plik', 'badge' => 'bg-secondary'],
        'plik_wgrany' => ['label' => 'Plik wgrany', 'badge' => 'bg-primary'],
        'do_weryfikacji' => ['label' => 'Do weryfikacji', 'badge' => 'bg-warning text-dark'],
        'zaakceptowany_do_emisji' => ['label' => 'Zaakceptowany do emisji', 'badge' => 'bg-success'],
        'odrzucony_do_poprawy' => ['label' => 'Odrzucony do poprawy', 'badge' => 'bg-danger'],
    ];
}

function normalizeClientAudioStatus(?string $status): string
{
    $status = trim((string)$status);
    return array_key_exists($status, clientAudioStatusDefinitions()) ? $status : 'oczekuje_na_plik';
}

function clientAudioStatusLabel(?string $status): string
{
    $defs = clientAudioStatusDefinitions();
    $key = normalizeClientAudioStatus($status);
    return (string)$defs[$key]['label'];
}

function clientAudioStatusBadgeClass(?string $status): string
{
    $defs = clientAudioStatusDefinitions();
    $key = normalizeClientAudioStatus($status);
    return (string)$defs[$key]['badge'];
}

function audioAllowedMimeTypes(): array
{
    return [
        'mp3' => ['audio/mpeg', 'audio/mp3', 'audio/x-mpeg', 'audio/mpeg3', 'audio/x-mpeg-3'],
        'wav' => ['audio/wav', 'audio/x-wav', 'audio/wave', 'audio/x-pn-wav'],
        'm4a' => ['audio/mp4', 'audio/x-m4a', 'audio/m4a', 'video/mp4', 'application/mp4'],
    ];
}

function audioDetectMime(string $path, ?string $browserMime = null): string
{
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detected = finfo_file($finfo, $path);
            finfo_close($finfo);
            if (is_string($detected) && trim($detected) !== '') {
                return strtolower(trim($detected));
            }
        }
    }
    if (function_exists('mime_content_type')) {
        $detected = mime_content_type($path);
        if (is_string($detected) && trim($detected) !== '') {
            return strtolower(trim($detected));
        }
    }
    return strtolower(trim((string)$browserMime));
}

function audioMimeMatchesExt(?string $mime, string $ext): bool
{
    $mime = strtolower(trim((string)$mime));
    $ext = strtolower(trim($ext));
    $map = audioAllowedMimeTypes();
    if (!isset($map[$ext])) {
        return false;
    }
    if ($mime === '') {
        return true;
    }
    return in_array($mime, $map[$ext], true);
}

function audioProbeMetadata(string $path): array
{
    $meta = [
        'duration_seconds' => null,
        'bitrate' => null,
        'sample_rate' => null,
        'channels' => null,
    ];
    if (!function_exists('shell_exec')) {
        return $meta;
    }

    $ffprobe = trim((string)@shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where ffprobe 2>NUL' : 'command -v ffprobe'));
    if ($ffprobe === '') {
        return $meta;
    }

    $cmd = 'ffprobe -v error -show_entries format=duration,bit_rate:stream=sample_rate,channels -of json ' . escapeshellarg($path);
    $out = @shell_exec($cmd);
    if (!is_string($out) || trim($out) === '') {
        return $meta;
    }
    $json = json_decode($out, true);
    if (!is_array($json)) {
        return $meta;
    }
    $format = is_array($json['format'] ?? null) ? $json['format'] : [];
    $streams = is_array($json['streams'] ?? null) ? $json['streams'] : [];
    $stream = is_array($streams[0] ?? null) ? $streams[0] : [];

    if (isset($format['duration']) && is_numeric($format['duration'])) {
        $meta['duration_seconds'] = (float)$format['duration'];
    }
    if (isset($format['bit_rate']) && is_numeric($format['bit_rate'])) {
        $meta['bitrate'] = (int)$format['bit_rate'];
    }
    if (isset($stream['sample_rate']) && is_numeric($stream['sample_rate'])) {
        $meta['sample_rate'] = (int)$stream['sample_rate'];
    }
    if (isset($stream['channels']) && is_numeric($stream['channels'])) {
        $meta['channels'] = (int)$stream['channels'];
    }
    return $meta;
}

function audioValidateUploadedFile(array $file, array $allowedExt, int $maxMb): array
{
    if (!isset($file['error']) || (int)$file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Nie udało się przesłać pliku.'];
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) {
        return ['ok' => false, 'error' => 'Plik nie może być pusty.'];
    }
    $maxBytes = max(1, $maxMb) * 1024 * 1024;
    if ($size > $maxBytes) {
        return ['ok' => false, 'error' => 'Plik przekracza limit rozmiaru.'];
    }
    $originalName = (string)($file['name'] ?? '');
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedExt = array_values(array_unique(array_filter(array_map(static fn($v) => strtolower(trim((string)$v)), $allowedExt))));
    if (!$allowedExt) {
        $allowedExt = ['mp3', 'wav', 'm4a'];
    }
    if ($ext === '' || !in_array($ext, $allowedExt, true) || !isset(audioAllowedMimeTypes()[$ext])) {
        return ['ok' => false, 'error' => 'Niedozwolone rozszerzenie pliku.'];
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return ['ok' => false, 'error' => 'Nieprawidłowy plik tymczasowy uploadu.'];
    }
    $mime = audioDetectMime($tmp, (string)($file['type'] ?? ''));
    if (!audioMimeMatchesExt($mime, $ext)) {
        return ['ok' => false, 'error' => 'MIME pliku nie zgadza się z dozwolonym formatem audio.'];
    }

    return [
        'ok' => true,
        'ext' => $ext,
        'mime' => $mime,
        'size' => $size,
        'original_name' => basename($originalName),
    ];
}

function audioStorageDir(): ?string
{
    $dir = dirname(__DIR__, 2) . '/storage/audio';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        return null;
    }
    return is_writable($dir) ? $dir : null;
}

function audioCreateStoredFilename(int $spotId, int $versionNo, string $ext, string $prefix = 'spot'): string
{
    return sprintf('%s_%d_v%d_%d_%s.%s', $prefix, $spotId, $versionNo, time(), bin2hex(random_bytes(8)), strtolower($ext));
}

function audioLogCampaignActivity(PDO $pdo, int $campaignId, string $message, ?int $userId = null, ?string $subject = null): void
{
    if ($campaignId <= 0 || !tableExists($pdo, 'kampanie')) {
        return;
    }
    $stmt = $pdo->prepare('SELECT klient_id, source_lead_id FROM kampanie WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $campaignId]);
    $campaign = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $actor = $userId ?: (!empty($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0);
    if ($actor <= 0 || !function_exists('addActivity')) {
        return;
    }
    if ((int)($campaign['klient_id'] ?? 0) > 0) {
        addActivity('klient', (int)$campaign['klient_id'], 'system', $actor, $message, null, $subject);
    }
    if ((int)($campaign['source_lead_id'] ?? 0) > 0) {
        addActivity('lead', (int)$campaign['source_lead_id'], 'system', $actor, $message, null, $subject);
    }
}

function ensureClientProvidedSpotForCampaign(PDO $pdo, int $campaignId): int
{
    ensureSpotColumns($pdo);
    if ($campaignId <= 0 || !tableExists($pdo, 'kampanie') || !tableExists($pdo, 'spoty')) {
        return 0;
    }

    $stmtExisting = $pdo->prepare("SELECT id FROM spoty WHERE kampania_id = :campaign_id AND audio_source_type = 'provided_by_client' ORDER BY id ASC LIMIT 1");
    $stmtExisting->execute([':campaign_id' => $campaignId]);
    $existingId = (int)($stmtExisting->fetchColumn() ?: 0);
    if ($existingId > 0) {
        return $existingId;
    }

    $stmtAny = $pdo->prepare('SELECT id FROM spoty WHERE kampania_id = :campaign_id ORDER BY id ASC LIMIT 1');
    $stmtAny->execute([':campaign_id' => $campaignId]);
    $spotId = (int)($stmtAny->fetchColumn() ?: 0);
    if ($spotId > 0) {
        $stmtUpdate = $pdo->prepare("UPDATE spoty
            SET audio_source_type = 'provided_by_client',
                client_audio_status = CASE
                    WHEN client_audio_status IS NULL OR client_audio_status = '' THEN 'oczekuje_na_plik'
                    ELSE client_audio_status
                END
            WHERE id = :id");
        $stmtUpdate->execute([':id' => $spotId]);
        return $spotId;
    }

    $stmtCampaign = $pdo->prepare('SELECT id, klient_id, klient_nazwa, dlugosc_spotu, data_start, data_koniec FROM kampanie WHERE id = :id LIMIT 1');
    $stmtCampaign->execute([':id' => $campaignId]);
    $campaign = $stmtCampaign->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$campaign) {
        return 0;
    }

    $spotCols = getTableColumns($pdo, 'spoty');
    $length = (int)($campaign['dlugosc_spotu'] ?? 0);
    if (!in_array($length, [15, 20, 30], true)) {
        $length = 30;
    }
    $name = 'Spot kampanii #' . $campaignId;
    $clientName = trim((string)($campaign['klient_nazwa'] ?? ''));
    if ($clientName !== '') {
        $name .= ' - ' . $clientName;
    }

    $data = [];
    if (hasColumn($spotCols, 'klient_id')) {
        $data['klient_id'] = (int)($campaign['klient_id'] ?? 0) > 0 ? (int)$campaign['klient_id'] : null;
    }
    if (hasColumn($spotCols, 'kampania_id')) {
        $data['kampania_id'] = $campaignId;
    }
    $data['nazwa_spotu'] = $name;
    if (hasColumn($spotCols, 'dlugosc')) {
        $data['dlugosc'] = (string)$length;
    }
    if (hasColumn($spotCols, 'dlugosc_s')) {
        $data['dlugosc_s'] = $length;
    }
    if (hasColumn($spotCols, 'data_start')) {
        $data['data_start'] = trim((string)($campaign['data_start'] ?? '')) ?: null;
    }
    if (hasColumn($spotCols, 'data_koniec')) {
        $data['data_koniec'] = trim((string)($campaign['data_koniec'] ?? '')) ?: null;
    }
    if (hasColumn($spotCols, 'status')) {
        $data['status'] = 'Aktywny';
    }
    if (hasColumn($spotCols, 'aktywny')) {
        $data['aktywny'] = 1;
    }
    if (hasColumn($spotCols, 'rezerwacja')) {
        $data['rezerwacja'] = 0;
    }
    if (hasColumn($spotCols, 'audio_source_type')) {
        $data['audio_source_type'] = 'provided_by_client';
    }
    if (hasColumn($spotCols, 'client_audio_status')) {
        $data['client_audio_status'] = 'oczekuje_na_plik';
    }

    $columns = array_keys($data);
    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $sql = 'INSERT INTO spoty (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')';
    $stmtInsert = $pdo->prepare($sql);
    $stmtInsert->execute(array_values($data));
    return (int)$pdo->lastInsertId();
}
