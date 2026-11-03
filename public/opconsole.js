/* Laravel OpConsole — dependency-free confirmation dialog, hash navigation, flash dismiss, and clipboard helper. */
(function () {
    'use strict';

    var securitySearch = document.querySelector('[data-security-search]');
    var securitySeverity = document.querySelector('[data-security-severity]');
    function filterSecurityRows() {
        var query = securitySearch ? securitySearch.value.toLocaleLowerCase() : '';
        var severity = securitySeverity ? securitySeverity.value : 'all';
        document.querySelectorAll('[data-security-row]').forEach(function (row) {
            row.hidden = !row.textContent.toLocaleLowerCase().includes(query) || (severity !== 'all' && row.dataset.securityLevel !== severity);
        });
    }
    if (securitySearch) securitySearch.addEventListener('input', filterSecurityRows);
    if (securitySeverity) securitySeverity.addEventListener('change', filterSecurityRows);

    document.addEventListener('DOMContentLoaded', function () {
        initConfirmDialog();
        initCopyButtons();
        initAlertDismiss();
        initHashReveal();
        initHealthFilter();
        initTabs();
        initRefreshLinks();
        initThemeToggle();
    });

    function initThemeToggle() {
        var button = document.querySelector('[data-oc-theme-toggle]');
        if (!button) return;

        var shell = button.closest('[data-oc-theme]');
        var root = document.documentElement;
        var media = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;

        function effective() {
            var theme = shell ? shell.getAttribute('data-oc-theme') : 'auto';
            if (theme === 'light' || theme === 'dark') return theme;
            return media && media.matches ? 'dark' : 'light';
        }

        function render() {
            var dark = effective() === 'dark';
            var label = dark ? 'Switch to light theme' : 'Switch to dark theme';
            button.classList.toggle('is-dark', dark);
            button.setAttribute('aria-label', label);
            button.title = label;
            button.setAttribute('aria-pressed', dark ? 'true' : 'false');
        }

        button.addEventListener('click', function () {
            var next = effective() === 'dark' ? 'light' : 'dark';
            if (shell) shell.setAttribute('data-oc-theme', next);
            if (root.hasAttribute('data-oc-theme')) root.setAttribute('data-oc-theme', next);
            document.cookie = 'opconsole_theme=' + next + '; path=/; max-age=31536000; SameSite=Lax';
            render();
        });

        if (media && media.addEventListener) media.addEventListener('change', render);

        button.hidden = false;
        render();
    }

    function initConfirmDialog() {
        var dialog = document.getElementById('opconsole-confirm-modal');
        var accept = document.getElementById('opconsole-confirm-accept');
        var dismiss = document.getElementById('opconsole-confirm-dismiss');
        var closeBtn = document.getElementById('opconsole-confirm-close');
        var titleEl = document.getElementById('opconsole-confirm-title');
        var messageEl = document.getElementById('opconsole-confirm-message');
        var commandEl = document.getElementById('opconsole-confirm-command');
        var riskEl = document.getElementById('opconsole-confirm-risk');
        var pendingForm = null;
        var lastTrigger = null;

        function openDialog() {
            if (!dialog) return;
            if (typeof dialog.showModal === 'function') {
                dialog.showModal();
            } else {
                dialog.setAttribute('open', 'open');
                dialog.setAttribute('data-fallback-open', 'true');
            }
            if (dismiss) dismiss.focus();
        }

        function closeDialog(restoreFocus) {
            if (!dialog) return;
            if (typeof dialog.close === 'function' && dialog.open) {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
                dialog.removeAttribute('data-fallback-open');
            }
            pendingForm = null;
            if (restoreFocus && lastTrigger && typeof lastTrigger.focus === 'function') {
                lastTrigger.focus();
            }
        }

        function markRunning(form) {
            var submitBtn = form.querySelector('button[type="submit"]');
            if (!submitBtn) return;
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
            var label = submitBtn.querySelector('[data-oc-btn-label]');
            if (label) label.textContent = 'Running…';
            var liveRegion = document.getElementById('opconsole-live-region');
            if (liveRegion) liveRegion.textContent = 'Operation started: ' + (form.dataset.confirmTitle || 'maintenance action') + '.';
        }

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form || !form.dataset || !form.dataset.confirm) return;
            if (form.dataset.ocConfirmed === 'true') {
                delete form.dataset.ocConfirmed;
                markRunning(form);
                return;
            }

            event.preventDefault();
            if (pendingForm) return;

            // Safety net if a custom host view omitted the dialog partial: never submit unconfirmed.
            if (!dialog || !accept || !titleEl || !messageEl) {
                if (window.confirm(form.dataset.confirm)) {
                    form.dataset.ocConfirmed = 'true';
                    markRunning(form);
                    form.submit();
                }
                return;
            }

            pendingForm = form;
            lastTrigger = document.activeElement;
            titleEl.textContent = form.dataset.confirmTitle || 'Confirm action';
            messageEl.textContent = form.dataset.confirm + (form.dataset.confirmEnvironment ? ' Environment: ' + form.dataset.confirmEnvironment + '.' : '');
            accept.textContent = form.dataset.confirmAccept || 'Confirm';
            accept.className = form.dataset.confirmRisk === 'high' ? 'oc-btn oc-btn-danger' : 'oc-btn oc-btn-primary';
            accept.disabled = false;

            if (commandEl) {
                if (form.dataset.confirmCommand) {
                    commandEl.textContent = form.dataset.confirmCommand;
                    commandEl.hidden = false;
                } else {
                    commandEl.textContent = '';
                    commandEl.hidden = true;
                }
            }

            if (riskEl) {
                if (form.dataset.confirmRiskLabel) {
                    riskEl.textContent = form.dataset.confirmRiskLabel;
                    riskEl.className = 'oc-risk-badge' + (form.dataset.confirmRisk === 'high' ? ' is-high' : (form.dataset.confirmRisk === 'medium' ? ' is-medium' : ''));
                    riskEl.hidden = false;
                } else {
                    riskEl.textContent = '';
                    riskEl.hidden = true;
                }
            }

            openDialog();
        });

        if (!dialog) return;

        [dismiss, closeBtn].forEach(function (btn) {
            if (!btn) return;
            btn.addEventListener('click', function () {
                closeDialog(true);
            });
        });

        dialog.addEventListener('cancel', function (event) {
            event.preventDefault();
            closeDialog(true);
        });

        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                closeDialog(true);
            }
        });

        accept.addEventListener('click', function () {
            if (!pendingForm) return;
            var form = pendingForm;
            accept.disabled = true;
            closeDialog(false);
            form.dataset.ocConfirmed = 'true';
            markRunning(form);
            form.submit();
        });
    }

    function initCopyButtons() {
        var liveRegion = document.getElementById('opconsole-live-region');

        document.querySelectorAll('[data-copy]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var label = btn.querySelector('[data-oc-copy-label]');
                var originalText = label ? label.textContent : null;

                var done = function () {
                    if (label && originalText !== null) {
                        label.textContent = 'Copied';
                        setTimeout(function () { label.textContent = originalText; }, 1600);
                    }
                    if (liveRegion) {
                        liveRegion.textContent = 'Copied to clipboard.';
                    }
                };

                var text = btn.dataset.copy || '';
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(text).then(done);
                } else {
                    var input = document.createElement('textarea');
                    input.value = text;
                    input.setAttribute('readonly', 'readonly');
                    input.className = 'oc-sr-only';
                    document.body.appendChild(input);
                    input.select();
                    document.execCommand('copy');
                    input.remove();
                    done();
                }
            });
        });
    }

    function initAlertDismiss() {
        document.querySelectorAll('[data-oc-dismiss]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var alertEl = btn.closest('[data-oc-alert]');
                if (alertEl) alertEl.remove();
            });
        });
    }

    function initHashReveal() {
        function revealTarget(hash) {
            if (hash === '#checks' || hash === '#history') {
                var el = document.getElementById(hash.slice(1));
                if (el && el.tagName === 'DETAILS') el.open = true;
            }
        }

        revealTarget(window.location.hash);
        window.addEventListener('hashchange', function () {
            revealTarget(window.location.hash);
        });

        document.querySelectorAll('a[href="#checks"], a[href="#history"]').forEach(function (link) {
            link.addEventListener('click', function () {
                revealTarget(link.getAttribute('href'));
            });
        });
    }

    function initRefreshLinks() {
        document.querySelectorAll('[data-oc-refresh]').forEach(function (link) {
            link.addEventListener('click', function () {
                // Keep the current tab hash and every query filter when reloading status.
                link.href = window.location.href;
            });
        });
    }

    function initHealthFilter() {
        var filter = document.querySelector('[data-oc-health-filter]');
        if (!filter) return;
        var items = Array.prototype.slice.call(document.querySelectorAll('[data-oc-check-item]'));
        var groups = Array.prototype.slice.call(document.querySelectorAll('[data-oc-check-group]'));
        var empty = document.querySelector('[data-oc-filter-empty]');
        var count = document.querySelector('[data-oc-health-count]');
        var params = new URLSearchParams(window.location.search);
        var initial = params.get('check_status') || 'all';
        if (['all', 'attention', 'critical', 'warning', 'unknown', 'healthy'].indexOf(initial) !== -1) filter.value = initial;

        function apply(value) {
            var visible = 0;
            items.forEach(function (item) {
                var status = item.dataset.ocStatus;
                var show = value === 'all' || (value === 'attention' && ['critical', 'warning', 'unknown'].indexOf(status) !== -1) || value === status;
                item.hidden = !show;
                if (show) visible++;
            });
            groups.forEach(function (group) {
                group.hidden = !group.querySelector('[data-oc-check-item]:not([hidden])');
            });
            if (empty) empty.hidden = visible > 0;
            if (count) count.textContent = visible + ' health checks shown.';
        }

        filter.addEventListener('change', function () {
            var url = new URL(window.location.href);
            if (filter.value === 'all') url.searchParams.delete('check_status');
            else url.searchParams.set('check_status', filter.value);
            url.hash = 'checks';
            window.location.assign(url.toString());
        });
        var reset = document.querySelector('[data-oc-health-reset]');
        if (reset) reset.addEventListener('click', function () {
            filter.value = 'all';
            apply('all');
            var url = new URL(window.location.href);
            url.searchParams.delete('check_status');
            url.hash = 'checks';
            if (window.history && window.history.replaceState) window.history.replaceState(null, '', url.toString());
        });
        apply(filter.value);
    }

    function initTabs() {
        var tablist = document.querySelector('[data-oc-tabs]');
        if (!tablist) return;

        var tabs = Array.prototype.slice.call(tablist.querySelectorAll('[data-oc-tab]'));
        var panels = {};
        tabs.forEach(function (tab) {
            var panel = document.getElementById(tab.getAttribute('aria-controls'));
            if (panel) panels[tab.dataset.ocTab] = panel;
        });
        tabs = tabs.filter(function (tab) { return panels[tab.dataset.ocTab]; });
        if (!tabs.length) return;

        var shell = tablist.closest('.oc-embedded-shell') || document.body;
        var storageKey = 'opconsole:tab';

        function store(key) {
            try { window.localStorage.setItem(storageKey, key); } catch (e) { /* storage unavailable */ }
        }

        function stored() {
            try { return window.localStorage.getItem(storageKey); } catch (e) { return null; }
        }

        function tabFor(key) {
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].dataset.ocTab === key) return tabs[i];
            }
            return null;
        }

        // A hash can name a tab (#checks) or any element inside a panel (#action-migrate).
        function tabForHash(hash) {
            if (!hash || hash.length < 2) return null;
            var id = decodeURIComponent(hash.slice(1));
            if (id === 'actions') return tabFor('operations');
            for (var i = 0; i < tabs.length; i++) {
                if (tabs[i].dataset.ocHash === id) return tabs[i];
            }
            var target = document.getElementById(id);
            var panel = target && target.closest('[data-oc-panel]');
            return panel ? tabFor(panel.dataset.ocPanel) : null;
        }

        function activate(tab, options) {
            options = options || {};
            tabs.forEach(function (other) {
                var selected = other === tab;
                other.setAttribute('aria-selected', selected ? 'true' : 'false');
                other.tabIndex = selected ? 0 : -1;
                panels[other.dataset.ocTab].hidden = !selected;
                panels[other.dataset.ocTab].tabIndex = selected ? 0 : -1;
            });
            if (options.remember) store(tab.dataset.ocTab);
            if (options.updateHash && window.history && window.history.replaceState) {
                window.history.replaceState(null, '', '#' + tab.dataset.ocHash);
            }
            if (options.focus) tab.focus();
            if (typeof tab.scrollIntoView === 'function') {
                tab.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            }
        }

        function initialTab() {
            var fromHash = tabForHash(window.location.hash);
            if (fromHash) return fromHash;

            // Log filters and pagination reload the page with query parameters.
            var params = new URLSearchParams(window.location.search);
            if (['log', 'level', 'env', 'q', 'limit'].some(function (key) { return params.has(key); })) {
                var logs = tabFor('logs');
                if (logs) return logs;
            }

            var preferred = tablist.dataset.ocDefault;
            // The server selects Operations after a command result; otherwise Overview is first.
            if ((preferred === 'actions' || preferred === 'operations') && document.querySelector('[data-oc-output]')) {
                return tabFor('operations') || tabs[0];
            }
            if (preferred === 'health') return tabFor('health') || tabs[0];

            return tabFor(stored() === 'actions' ? 'operations' : stored()) || tabFor(preferred === 'actions' ? 'operations' : preferred) || tabs[0];
        }

        var wrap = tablist.closest('[data-oc-tabs-wrap]');
        shell.classList.add('oc-tabbed');
        tablist.hidden = false;
        if (wrap) wrap.hidden = false;
        activate(initialTab());

        // Fade the edge(s) of the tab bar that hide more tabs on narrow screens.
        function updateOverflow() {
            var max = tablist.scrollWidth - tablist.clientWidth;
            var pos = Math.abs(tablist.scrollLeft); // negative in RTL
            tablist.classList.toggle('has-more-start', max > 1 && pos > 1);
            tablist.classList.toggle('has-more-end', max > 1 && pos < max - 1);
        }
        tablist.addEventListener('scroll', updateOverflow, { passive: true });
        window.addEventListener('resize', updateOverflow);
        updateOverflow();

        tabs.forEach(function (tab, index) {
            tab.addEventListener('click', function () {
                activate(tab, { remember: true, updateHash: true });
            });

            tab.addEventListener('keydown', function (event) {
                var next = null;
                if (event.key === 'ArrowRight') next = tabs[(index + 1) % tabs.length];
                else if (event.key === 'ArrowLeft') next = tabs[(index - 1 + tabs.length) % tabs.length];
                else if (event.key === 'Home') next = tabs[0];
                else if (event.key === 'End') next = tabs[tabs.length - 1];
                if (!next) return;
                event.preventDefault();
                activate(next, { remember: true, updateHash: true, focus: true });
            });
        });

        // Tile and advice links (#checks, #logs, #action-migrate, ...) switch to the right tab.
        function followHash() {
            var tab = tabForHash(window.location.hash);
            if (!tab) return;
            activate(tab, { remember: true });
            var target = document.getElementById(decodeURIComponent(window.location.hash.slice(1)));
            if (target && !target.hasAttribute('data-oc-panel') && typeof target.scrollIntoView === 'function') {
                target.scrollIntoView({ block: 'start' });
            }
        }

        window.addEventListener('hashchange', followHash);
        document.addEventListener('click', function (event) {
            var link = event.target.closest && event.target.closest('a[href^="#"]');
            if (!link || link.closest('[data-oc-tabs]')) return;
            if (link.getAttribute('href') === window.location.hash) {
                // hashchange will not fire for the current hash, so switch explicitly.
                setTimeout(followHash, 0);
            }
        });
    }
})();
