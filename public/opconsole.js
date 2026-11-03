/* Laravel OpConsole — dependency-free confirmation dialog, hash navigation, flash dismiss, and clipboard helper. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initConfirmDialog();
        initCopyButtons();
        initAlertDismiss();
        initHashReveal();
        initTabs();
    });

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
            messageEl.textContent = form.dataset.confirm;
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
            });
            // Collapsible sections inside a panel are always expanded in tab mode.
            panels[tab.dataset.ocTab].querySelectorAll('details.oc-section').forEach(function (details) {
                details.open = true;
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
            // The server picks "actions" after an action ran and "health" when a check is critical.
            if (preferred === 'actions' && document.querySelector('[data-oc-output]')) {
                return tabFor('actions') || tabs[0];
            }
            if (preferred === 'health') return tabFor('health') || tabs[0];

            return tabFor(stored()) || tabFor(preferred) || tabs[0];
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

        // Summaries inside panels no longer collapse their section.
        shell.querySelectorAll('[data-oc-panel] > details.oc-section > summary').forEach(function (summary) {
            summary.addEventListener('click', function (event) {
                if (event.target.closest('a, button')) return;
                event.preventDefault();
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
