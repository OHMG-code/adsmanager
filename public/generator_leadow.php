<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db_schema.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/AiLeadSettingsService.php';

requireLogin();
ensureUserColumns($pdo);
ensureSystemConfigColumns($pdo);
ensureAiLeadTables($pdo);

$pageTitle = 'Generator leadów AI';
$settings = (new AiLeadSettingsService($pdo))->load(false);
$currentUser = currentUser();
$assignableUsers = [];
try {
    $stmt = $pdo->query("SELECT id, login, imie, nazwisko, rola, aktywny FROM uzytkownicy ORDER BY login ASC");
    foreach (($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : []) as $user) {
        if (isset($user['aktywny']) && (int)$user['aktywny'] === 0) {
            continue;
        }
        $full = trim((string)($user['imie'] ?? '') . ' ' . (string)($user['nazwisko'] ?? ''));
        $assignableUsers[] = [
            'id' => (int)$user['id'],
            'label' => $full !== '' ? $full : (string)($user['login'] ?? ('User #' . (int)$user['id'])),
        ];
    }
} catch (Throwable $e) {
    $assignableUsers = [];
}

include __DIR__ . '/includes/header.php';
?>

<main class="container-fluid py-4">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
        <div>
            <h1 class="h4 mb-1">Generator leadów AI</h1>
            <p class="text-muted mb-0">Podgląd wyników z poczekalnią importu i kontrolą duplikatów.</p>
        </div>
        <a href="<?= htmlspecialchars(BASE_URL) ?>/lead.php" class="btn btn-outline-secondary btn-sm">Lista leadów</a>
    </div>

    <div id="aiLeadAlerts"></div>

    <form id="aiLeadGenerateForm" class="card shadow-sm mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label" for="industry">Branża / słowo kluczowe</label>
                    <input type="text" class="form-control" id="industry" name="industry" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="location">Lokalizacja</label>
                    <input type="text" class="form-control" id="location" name="location" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="radius_km">Promień km</label>
                    <input type="number" class="form-control" id="radius_km" name="radius_km" min="1" max="100"
                           value="<?= (int)$settings['ai_default_radius_km'] ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="limit">Liczba leadów</label>
                    <input type="number" class="form-control" id="limit" name="limit" min="1"
                           max="<?= (int)$settings['ai_max_generation_limit'] ?>"
                           value="<?= (int)$settings['ai_default_generation_limit'] ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="assigned_user_id">Przypisz do użytkownika</label>
                    <select class="form-select" id="assigned_user_id" name="assigned_user_id">
                        <?php foreach ($assignableUsers as $user): ?>
                            <option value="<?= (int)$user['id'] ?>" <?= (int)$user['id'] === (int)($currentUser['id'] ?? 0) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['label']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="mode">Źródło</label>
                    <select class="form-select" id="mode" name="mode">
                        <option value="google_ai">Google Places + AI enrichment</option>
                        <option value="google_only">Google Places only</option>
                        <option value="test">AI disabled / test mode</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Generuj podgląd</button>
                    <button type="button" class="btn btn-outline-secondary" id="discardPreview">Odrzuć</button>
                </div>
            </div>
        </div>
    </form>

    <div class="card shadow-sm">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <h2 class="h5 mb-0">Podgląd</h2>
                <button type="button" class="btn btn-success btn-sm" id="importSelected" disabled>Importuj zaznaczone do poczekalni</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle" id="previewTable">
                    <thead class="table-light">
                    <tr>
                        <th><input type="checkbox" id="selectAll"></th>
                        <th>Firma</th>
                        <th>Miasto</th>
                        <th>Telefon</th>
                        <th>WWW</th>
                        <th>Branża</th>
                        <th>Score</th>
                        <th>Pakiet</th>
                        <th>Argument</th>
                        <th>Duplikat</th>
                        <th>Akcje</th>
                    </tr>
                    </thead>
                    <tbody>
                    <tr><td colspan="11" class="text-center text-muted">Brak danych.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</main>

<script>
(function () {
    var generatedLeads = [];
    var importedIds = {};
    var form = document.getElementById('aiLeadGenerateForm');
    var tbody = document.querySelector('#previewTable tbody');
    var alerts = document.getElementById('aiLeadAlerts');
    var importButton = document.getElementById('importSelected');
    var selectAll = document.getElementById('selectAll');

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
            return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
        });
    }

    function showAlert(type, message) {
        alerts.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            esc(message) + '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }

    function selectedIndexes() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-lead-select]:checked')).map(function (el) {
            return parseInt(el.getAttribute('data-index'), 10);
        }).filter(function (idx) { return !isNaN(idx) && !importedIds[idx]; });
    }

    function updateImportState() {
        importButton.disabled = selectedIndexes().length === 0;
    }

    function render() {
        if (!generatedLeads.length) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center text-muted">Brak danych.</td></tr>';
            importButton.disabled = true;
            return;
        }
        tbody.innerHTML = generatedLeads.map(function (lead, index) {
            var website = lead.website ? '<a href="' + esc(lead.website) + '" target="_blank" rel="noopener">WWW</a>' : '';
            var dupClass = lead.duplicate_status === 'duplicate' ? 'badge bg-danger' : 'badge bg-success';
            var dupText = lead.duplicate_status === 'duplicate' ? 'duplicate ' + (lead.duplicate_score || '') : 'safe';
            var imported = importedIds[index];
            return '<tr>' +
                '<td><input type="checkbox" data-lead-select data-index="' + index + '"' + (imported ? ' disabled' : '') + '></td>' +
                '<td>' + esc(lead.company_name) + '</td>' +
                '<td>' + esc(lead.city) + '</td>' +
                '<td>' + esc(lead.phone || '') + '</td>' +
                '<td>' + website + '</td>' +
                '<td>' + esc(lead.industry) + '</td>' +
                '<td>' + esc(lead.score || '') + '</td>' +
                '<td>' + esc(lead.recommended_package || '') + '</td>' +
                '<td class="small">' + esc(lead.opening_argument || '') + '</td>' +
                '<td><span class="' + dupClass + '">' + esc(dupText) + '</span></td>' +
                '<td>' + (imported ? '<div class="d-flex gap-1 flex-wrap"><span class="badge bg-primary">poczekalnia #' + imported + '</span><button type="button" class="btn btn-outline-primary btn-sm" data-promote-one="' + index + '">Dodaj jako lead</button></div>' : '<button type="button" class="btn btn-outline-success btn-sm" data-import-one="' + index + '">Importuj</button>') + '</td>' +
                '</tr>';
        }).join('');
        updateImportState();
    }

    function payloadFromForm() {
        return {
            industry: form.industry.value,
            location: form.location.value,
            radius_km: parseInt(form.radius_km.value || '30', 10),
            limit: parseInt(form.limit.value || '20', 10),
            assigned_user_id: parseInt(form.assigned_user_id.value || '0', 10),
            mode: form.mode.value
        };
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        importButton.disabled = true;
        fetch('api/ai-leads/generate.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payloadFromForm())
        }).then(function (response) {
            return response.json().then(function (json) { return {ok: response.ok, json: json}; });
        }).then(function (result) {
            var data = result.json.data || {};
            generatedLeads = data.leads || [];
            importedIds = {};
            render();
            if (!result.ok) {
                showAlert('warning', ((data.errors || [result.json.error || 'Nie udało się wygenerować leadów.'])[0]));
            } else if ((data.warnings || []).length) {
                showAlert('warning', data.warnings[0]);
            } else {
                showAlert('success', 'Podgląd wygenerowany.');
            }
        }).catch(function () {
            showAlert('danger', 'Nie udało się połączyć z API generatora.');
        });
    });

    tbody.addEventListener('change', updateImportState);
    tbody.addEventListener('click', function (event) {
        var button = event.target.closest('[data-import-one]');
        if (!button) {
            var promote = event.target.closest('[data-promote-one]');
            if (!promote) {
                return;
            }
            var promoteIdx = parseInt(promote.getAttribute('data-promote-one'), 10);
            promoteLead(promoteIdx);
            return;
        }
        importLeads([parseInt(button.getAttribute('data-import-one'), 10)]);
    });
    selectAll.addEventListener('change', function () {
        document.querySelectorAll('[data-lead-select]:not(:disabled)').forEach(function (box) {
            box.checked = selectAll.checked;
        });
        updateImportState();
    });
    document.getElementById('discardPreview').addEventListener('click', function () {
        generatedLeads = [];
        importedIds = {};
        alerts.innerHTML = '';
        render();
    });
    importButton.addEventListener('click', function () {
        importLeads(selectedIndexes());
    });

    function importLeads(indexes) {
        var leads = indexes.map(function (idx) { return generatedLeads[idx]; }).filter(Boolean);
        if (!leads.length) {
            return;
        }
        fetch('api/ai-leads/import.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({leads: leads, assigned_user_id: parseInt(form.assigned_user_id.value || '0', 10)})
        }).then(function (response) {
            return response.json().then(function (json) { return {ok: response.ok, json: json}; });
        }).then(function (result) {
            if (!result.ok || !result.json.success) {
                showAlert('danger', result.json.error || 'Import nie powiódł się.');
                return;
            }
            var saved = (result.json.data || {}).saved || [];
            indexes.forEach(function (idx, pos) {
                if (saved[pos]) {
                    importedIds[idx] = saved[pos].id;
                }
            });
            render();
            showAlert('success', 'Zaimportowano zaznaczone leady do poczekalni.');
        }).catch(function () {
            showAlert('danger', 'Nie udało się połączyć z API importu.');
        });
    }

    function promoteLead(index) {
        var aiLeadId = importedIds[index];
        if (!aiLeadId) {
            return;
        }
        fetch('api/ai-leads/promote.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ai_lead_id: aiLeadId})
        }).then(function (response) {
            return response.json().then(function (json) { return {ok: response.ok, json: json}; });
        }).then(function (result) {
            if (!result.ok || !result.json.success) {
                showAlert('warning', result.json.error || 'Nie udało się dodać leada.');
                return;
            }
            showAlert('success', 'Lead został dodany do głównej listy.');
        }).catch(function () {
            showAlert('danger', 'Nie udało się połączyć z API promocji.');
        });
    }
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
