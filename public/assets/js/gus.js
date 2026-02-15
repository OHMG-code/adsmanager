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
        if (key === 'ulica') {
            return buildAddress(data);
        }
        if (key === 'miejscowosc') {
            var city = data.miejscowosc || '';
            var postal = data.kod_pocztowy || '';
            if (postal && city) {
                return postal + ' ' + city;
            }
            return city || postal;
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

    function initGusButton(options) {
        var opts = options || {};
        var button = document.getElementById(opts.buttonId || '');
        var nipInput = document.getElementById(opts.nipInputId || '');
        var statusEl = opts.statusId ? document.getElementById(opts.statusId) : null;
        if (!button || !nipInput) {
            return;
        }
        var fieldMap = opts.fieldMap || {};
        var endpoint = opts.endpoint || 'gus_lookup.php';

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
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('Blad polaczenia z GUS (' + response.status + ').');
                    }
                    return response.json();
                })
                .then(function(payload) {
                    if (!payload || payload.ok !== true) {
                        var message = payload && payload.message ? payload.message : 'Nie udalo sie pobrac danych.';
                        throw new Error(message);
                    }
                    fillFields(fieldMap, payload.data || {});
                    setStatus(statusEl, 'Uzupelniono.', 'success');
                })
                .catch(function(err) {
                    setStatus(statusEl, err.message || 'Blad pobierania danych.', 'danger');
                })
                .finally(function() {
                    button.disabled = false;
                });
        });
    }

    global.initGusButton = initGusButton;
})(window);
