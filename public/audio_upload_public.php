<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/includes/briefs.php';
require_once __DIR__ . '/includes/audio_sources.php';
require_once __DIR__ . '/includes/communication_events.php';
require_once __DIR__ . '/includes/crm_activity.php';

ensureLeadBriefsTable($pdo);
ensureSpotColumns($pdo);
ensureSpotAudioFilesTable($pdo);
ensureSystemConfigColumns($pdo);
ensureCommunicationEventsTable($pdo);

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '' && !empty($_SERVER['REQUEST_URI']) && preg_match('#/audio-upload/([A-Fa-f0-9]{64})#', (string)$_SERVER['REQUEST_URI'], $m)) {
    $token = $m[1];
}
if ($token === '') {
    http_response_code(404);
    exit('Nie znaleziono formularza uploadu.');
}

$brief = getBriefByToken($pdo, $token);
if (!$brief) {
    http_response_code(404);
    exit('Nie znaleziono formularza uploadu.');
}

$stmtCampaign = $pdo->prepare('SELECT id, klient_nazwa, owner_user_id, source_lead_id, klient_id FROM kampanie WHERE id = :id LIMIT 1');
$stmtCampaign->execute([':id' => (int)$brief['campaign_id']]);
$campaign = $stmtCampaign->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$campaign) {
    http_response_code(404);
    exit('Nie znaleziono kampanii.');
}

$spotId = ensureClientProvidedSpotForCampaign($pdo, (int)$campaign['id']);

$cfgStmt = $pdo->query("SELECT audio_upload_max_mb, audio_allowed_ext FROM konfiguracja_systemu WHERE id = 1");
$cfg = $cfgStmt ? ($cfgStmt->fetch(PDO::FETCH_ASSOC) ?: []) : [];
$maxMb = max(1, (int)($cfg['audio_upload_max_mb'] ?? 50));
$allowedExt = (string)($cfg['audio_allowed_ext'] ?? 'wav,mp3,m4a');
$allowedList = array_values(array_filter(array_map('strtolower', array_map('trim', explode(',', $allowedExt)))));

$errors = [];
$success = false;

function publicUploadActorUserId(PDO $pdo, array $campaign): int
{
    $owner = (int)($campaign['owner_user_id'] ?? 0);
    if ($owner > 0) {
        return $owner;
    }
    try {
        $stmt = $pdo->query('SELECT id FROM uzytkownicy ORDER BY id ASC LIMIT 1');
        return (int)($stmt ? $stmt->fetchColumn() : 0);
    } catch (Throwable $e) {
        return 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isCsrfTokenValid($_POST['csrf_token'] ?? '')) {
        $errors[] = 'Niepoprawny token formularza.';
    } else {
        $file = $_FILES['audio_file'] ?? null;
        $validation = $file ? audioValidateUploadedFile($file, $allowedList, $maxMb) : ['ok' => false, 'error' => 'Wybierz plik audio.'];
        if (empty($validation['ok'])) {
            $errors[] = (string)($validation['error'] ?? 'Nieprawidlowy plik audio.');
        } else {
            $storageDir = audioStorageDir();
            if (!$storageDir) {
                $errors[] = 'Brak dostępu do katalogu na pliki audio.';
            } else {
                $stmtMax = $pdo->prepare('SELECT COALESCE(MAX(version_no), 0) FROM spot_audio_files WHERE spot_id = ?');
                $stmtMax->execute([$spotId]);
                $versionNo = ((int)$stmtMax->fetchColumn()) + 1;
                $storedFilename = audioCreateStoredFilename($spotId, $versionNo, (string)$validation['ext'], 'client_spot');
                $targetPath = $storageDir . '/' . $storedFilename;
                if (!move_uploaded_file((string)$file['tmp_name'], $targetPath)) {
                    $errors[] = 'Nie udało się zapisać pliku.';
                } else {
                    $metadata = audioProbeMetadata($targetPath);
                    $actorUserId = publicUploadActorUserId($pdo, $campaign);
                    try {
                        $pdo->beginTransaction();
                        $pdo->prepare('UPDATE spot_audio_files SET is_active = 0 WHERE spot_id = ?')->execute([$spotId]);
                        $stmtInsert = $pdo->prepare("INSERT INTO spot_audio_files
                            (spot_id, version_no, is_active, is_final, original_filename, stored_filename, mime_type, file_size, audio_format, duration_seconds, bitrate, sample_rate, channels, sha256, production_status, client_audio_status, uploaded_by_user_id, upload_note)
                            VALUES (?, ?, 1, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'do_weryfikacji', ?, ?)");
                        $stmtInsert->execute([
                            $spotId,
                            $versionNo,
                            (string)$validation['original_name'],
                            $storedFilename,
                            (string)$validation['mime'],
                            (int)$validation['size'],
                            (string)$validation['ext'],
                            $metadata['duration_seconds'],
                            $metadata['bitrate'],
                            $metadata['sample_rate'],
                            $metadata['channels'],
                            hash_file('sha256', $targetPath) ?: null,
                            audioProductionStatusDbValue('robocza'),
                            $actorUserId > 0 ? $actorUserId : 1,
                            'Upload publiczny klienta',
                        ]);
                        $audioId = (int)$pdo->lastInsertId();
                        $pdo->prepare("UPDATE spoty SET client_audio_status = 'do_weryfikacji' WHERE id = ?")->execute([$spotId]);
                        $pdo->commit();

                        communicationLogEvent($pdo, [
                            'event_type' => 'client_audio_uploaded',
                            'idempotency_key' => communicationBuildIdempotencyKey('client_audio_uploaded', [$audioId, $token]),
                            'direction' => 'inbound_client',
                            'status' => 'logged',
                            'subject' => 'Klient wgral plik audio',
                            'body' => 'Kampania #' . (int)$campaign['id'] . ', spot #' . $spotId,
                            'meta_json' => ['spot_id' => $spotId, 'audio_id' => $audioId],
                            'campaign_id' => (int)$campaign['id'],
                            'brief_id' => (int)$brief['id'],
                            'spot_audio_file_id' => $audioId,
                            'created_by_user_id' => null,
                        ]);
                        if ($actorUserId > 0) {
                            $previousSessionUserId = $_SESSION['user_id'] ?? null;
                            $_SESSION['user_id'] = $actorUserId;
                            audioLogCampaignActivity($pdo, (int)$campaign['id'], 'Klient wgrał plik audio: ' . (string)$validation['original_name'], $actorUserId, 'Klient wgrał plik audio');
                            if ($previousSessionUserId !== null) {
                                $_SESSION['user_id'] = $previousSessionUserId;
                            } else {
                                unset($_SESSION['user_id']);
                            }
                        }
                        $success = true;
                    } catch (Throwable $e) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        @unlink($targetPath);
                        error_log('audio_upload_public: ' . $e->getMessage());
                        $errors[] = 'Błąd zapisu informacji o pliku.';
                    }
                }
            }
        }
    }
}

$assetBase = rtrim((string)BASE_URL, '/');
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Upload pliku audio</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= htmlspecialchars($assetBase) ?>/assets/css/themes/tokens.css" rel="stylesheet">
  <link href="<?= htmlspecialchars($assetBase) ?>/assets/css/style.css" rel="stylesheet">
</head>
<body>
  <main class="container py-5" style="max-width: 760px;">
    <div class="mb-4">
      <div class="text-muted small">Adds Manager 1.0</div>
      <h1 class="h3 mb-1">Przekazanie gotowego spotu audio</h1>
      <div class="text-muted"><?= htmlspecialchars((string)($campaign['klient_nazwa'] ?? ('Kampania #' . (int)$campaign['id']))) ?></div>
    </div>

    <?php if ($success): ?>
      <div class="alert alert-success">Dziękujemy, plik został przekazany do weryfikacji.</div>
    <?php else: ?>
      <?php foreach ($errors as $error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endforeach; ?>
      <form method="post" enctype="multipart/form-data" class="border rounded p-4 bg-white">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(getCsrfToken()) ?>">
        <div class="mb-3">
          <label class="form-label" for="audio_file">Plik audio</label>
          <input class="form-control" type="file" name="audio_file" id="audio_file" accept=".mp3,.wav,.m4a,audio/mpeg,audio/wav,audio/mp4" required>
          <div class="form-text">Dozwolone: <?= htmlspecialchars(implode(', ', $allowedList ?: ['mp3', 'wav', 'm4a'])) ?>. Limit: <?= (int)$maxMb ?> MB.</div>
        </div>
        <button class="btn btn-primary" type="submit" <?= $spotId > 0 ? '' : 'disabled' ?>>Wgraj plik audio</button>
        <?php if ($spotId <= 0): ?>
          <div class="text-muted small mt-2">Link jest poprawny, ale nie udało się przygotować technicznego spotu dla tej kampanii.</div>
        <?php endif; ?>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
