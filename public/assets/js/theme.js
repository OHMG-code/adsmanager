(function () {
    'use strict';

    var THEME_KEY = 'adsmanager_theme';
    var THEME_LIGHT = 'light';
    var THEME_DARK = 'dark';

    function normalizeTheme(theme) {
        return theme === THEME_DARK ? THEME_DARK : THEME_LIGHT;
    }

    function getTheme() {
        try {
            return normalizeTheme(localStorage.getItem(THEME_KEY) || THEME_LIGHT);
        } catch (e) {
            return THEME_LIGHT;
        }
    }

    function applyTheme(theme) {
        var normalized = normalizeTheme(theme);
        document.documentElement.setAttribute('data-theme', normalized);
        document.documentElement.style.colorScheme = normalized === THEME_DARK ? 'dark' : 'light';
        return normalized;
    }

    function saveTheme(theme) {
        try {
            localStorage.setItem(THEME_KEY, normalizeTheme(theme));
        } catch (e) {
            // localStorage may be disabled by browser policy
        }
    }

    function updateFeedback(message) {
        var target = document.querySelector('[data-theme-feedback]');
        if (!target) {
            return;
        }
        target.textContent = message;
    }

    function syncThemeControls(theme) {
        var normalized = normalizeTheme(theme);
        var controls = document.querySelectorAll('[data-theme-control]');
        for (var i = 0; i < controls.length; i++) {
            var control = controls[i];
            if (!control || control.tagName !== 'INPUT') {
                continue;
            }
            if ((control.type || '').toLowerCase() === 'radio') {
                control.checked = control.value === normalized;
            }
        }
    }

    function handleThemeChange(theme) {
        var normalized = applyTheme(theme);
        saveTheme(normalized);
        syncThemeControls(normalized);
    }

    function bindThemeControls() {
        if (window.__adsManagerThemeBound) {
            return;
        }
        window.__adsManagerThemeBound = true;

        var controls = document.querySelectorAll('[data-theme-control]');
        for (var i = 0; i < controls.length; i++) {
            (function (control) {
                control.addEventListener('change', function () {
                    if (control.tagName === 'INPUT' && (control.type || '').toLowerCase() === 'radio' && !control.checked) {
                        return;
                    }
                    handleThemeChange(control.value);
                    updateFeedback('Motyw zapisany lokalnie.');
                });
            })(controls[i]);
        }

        document.addEventListener('click', function (event) {
            var target = event.target;
            if (!target) {
                return;
            }
            if (target.matches && target.matches('[data-theme-save]')) {
                event.preventDefault();
                var selected = document.querySelector('[data-theme-control]:checked');
                var selectedTheme = selected ? selected.value : getTheme();
                handleThemeChange(selectedTheme);
                updateFeedback('Zapisano preferencje motywu.');
            }
        });
    }

    function initTheme() {
        var theme = getTheme();
        applyTheme(theme);
        syncThemeControls(theme);
    }

    window.AdsManagerTheme = {
        getTheme: getTheme,
        applyTheme: applyTheme,
        initTheme: initTheme,
        bindThemeControls: bindThemeControls,
    };

    window.addEventListener('storage', function (event) {
        if (event.key !== THEME_KEY) {
            return;
        }
        var nextTheme = normalizeTheme(event.newValue || THEME_LIGHT);
        applyTheme(nextTheme);
        syncThemeControls(nextTheme);
    });

    function bootTheme() {
        initTheme();
        bindThemeControls();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootTheme);
    } else {
        bootTheme();
    }
})();
