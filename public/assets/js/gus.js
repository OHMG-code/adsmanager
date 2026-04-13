(function(global) {
    'use strict';

    function normalizeNip(value) {
        return String(value || '').replace(/\D/g, '');
    }

    function setStatus(el, message, tone) {
        if (!el) {
            return;
        }
        el.textContent = message || '';
        el.classList.remove('text-success', 'text-danger', 'text-warning', 'text-muted');
        if (tone === 'success') {
            el.classList.add('text-success');
        } else if (tone === 'danger') {
            el.classList.add('text-danger');
        } else if (tone === 'warning') {
            el.classList.add('text-warning');
        } else if (tone === 'info') {
            el.classList.add('text-muted');
        }
    }

    function buildAddress(data) {
        if (!data || typeof data !== 'object') {
            return '';
        }
        var street = data.ulica || '';
        var number = data.nr_nieruchomosci || '';
        var local = data.nr_lokalu || '';
        if (number && local) {
            number = number + '/' + local;
        } else if (!number && local) {
            number = local;
        }
        var parts = [];
        if (street) {
            parts.push(street);
        }
        if (number) {
            parts.push(number);
        }
        return parts.join(' ');
    }

    function resolveValue(key, data) {
        if (!data || typeof data !== 'object') {
            return '';
        }
        if (Array.isArray(key)) {
            return key
                .map(function(item) {
                    return resolveValue(item, data);
                })
                .filter(Boolean)
                .join(' ');
        }
        if (key === 'adres') {
            return buildAddress(data);
        }
        if (key === 'ulica') {
            return data.ulica || '';
        }
        if (key === 'ulica_nazwa') {
            return data.ulica || '';
        }
        if (key === 'nr_budynku') {
            return data.nr_nieruchomosci || data.nr_budynku || '';
        }
        if (key === 'miasto') {
            return data.miejscowosc || data.miasto || '';
        }
        if (key === 'miejscowosc') {
            return data.miejscowosc || data.miasto || '';
        }
        if (key === 'kod_i_miasto') {
            var city = data.miejscowosc || data.miasto || '';
            var postal = data.kod_pocztowy || '';
            return [postal, city].filter(Boolean).join(' ');
        }
        return data[key] !== undefined && data[key] !== null ? String(data[key]) : '';
    }

    function fillFields(fieldMap, data) {
        Object.keys(fieldMap || {}).forEach(function(fieldId) {
            var el = document.getElementById(fieldId);
            if (!el) {
                return;
            }
            var key = fieldMap[fieldId];
            var value = resolveValue(key, data);
            if (value !== '') {
                el.value = value;
            }
        });
    }

    function parseJsonResponse(response) {
        return response.text().then(function(text) {
            var trimmed = String(text || '').replace(/^\uFEFF/, '').trim();
            var first = trimmed.charAt(0);
            if (first !== '{' && first !== '[') {
                throw new Error('Serwer GUS zwrocil nie-JSON.');
            }

            var payload;
            try {
                payload = JSON.parse(trimmed);
            } catch (err) {
                throw new Error('Niepoprawny JSON z backendu GUS.');
            }

            if (!response.ok) {
                var errMsg = extractErrorMessage(payload, 'Blad polaczenia z GUS (' + response.status + ').');
                throw new Error(errMsg);
            }

            return payload;
        });
    }

    function extractErrorMessage(payload, fallback) {
        if (payload && payload.error && typeof payload.error === 'object' && payload.error.message) {
            return payload.error.message;
        }
        if (payload && typeof payload.error === 'string' && payload.error) {
            return payload.error;
        }
        if (payload && payload.message) {
            return payload.message;
        }
        return fallback;
    }

    function initGusButton(options) {
        var opts = options || {};
        var button = document.getElementById(opts.buttonId || '');
        var nipInput = document.getElementById(opts.nipInputId || '');
        var statusEl = opts.statusId ? document.getElementById(opts.statusId) : null;
        if (!button || !nipInput) {
            return;
        }
        var fieldMap = opts.fieldMap || {};
        var endpoint = opts.endpoint || 'api/gus_lookup.php';
        var onSuccess = typeof opts.onSuccess === 'function' ? opts.onSuccess : null;
        var onError = typeof opts.onError === 'function' ? opts.onError : null;

        button.addEventListener('click', function() {
            var nip = normalizeNip(nipInput.value);
            if (nip.length !== 10) {
                setStatus(statusEl, 'Podaj poprawny NIP (10 cyfr).', 'warning');
                return;
            }

            button.disabled = true;
            setStatus(statusEl, 'Pobieram...', 'info');

            fetch(endpoint + '?nip=' + encodeURIComponent(nip), {
                headers: { 'Accept': 'application/json' }
            })
                .then(parseJsonResponse)
                .then(function(payload) {
                    if (!payload || payload.ok !== true) {
                        var message = extractErrorMessage(payload, 'Nie udalo sie pobrac danych.');
                        throw new Error(message);
                    }
                    var data = payload.data || {};
                    fillFields(fieldMap, data);
                    setStatus(statusEl, 'Uzupelniono.', 'success');
                    if (onSuccess) {
                        onSuccess(data, payload);
                    }
                })
                .catch(function(err) {
                    setStatus(statusEl, err.message || 'Blad pobierania danych.', 'danger');
                    if (onError) {
                        onError(err);
                    }
                })
                .finally(function() {
                    button.disabled = false;
                });
        });
    }

    global.initGusButton = initGusButton;
})(window);
