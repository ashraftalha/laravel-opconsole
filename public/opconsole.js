/* Laravel OpConsole — dependency-free confirmation dialog, tabs, filters, command palette, keyboard navigation and clipboard helper. */
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
        initScrollFades();
        initHistoryFilter();
        initSystemSearch();
        initLogKeys();
        initPalette();
        document.querySelectorAll('[data-oc-js-only]').forEach(function (el) { el.hidden = false; });
    });

    var reducedMotion = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;

    // Fade the edges of scroll containers that hide more content; each fade grows with the hidden distance.
    function initScrollFades() {
        var MAX_FADE = 28;
        document.querySelectorAll('[data-oc-scroll-fade]').forEach(function (el) {
            var horizontal = el.dataset.ocScrollFade === 'x';
            var frame = 0;
            function update() {
                var range = horizontal ? el.scrollWidth - el.clientWidth : el.scrollHeight - el.clientHeight;
                var pos = horizontal ? Math.abs(el.scrollLeft) : el.scrollTop; // scrollLeft is negative in RTL
                el.style.setProperty('--oc-fade-start', Math.min(MAX_FADE, Math.max(0, pos)) + 'px');
                el.style.setProperty('--oc-fade-end', Math.min(MAX_FADE, Math.max(0, range - pos)) + 'px');
            }
            function schedule() {
                cancelAnimationFrame(frame);
                frame = requestAnimationFrame(update);
            }
            el.addEventListener('scroll', schedule, { passive: true });
            if (window.ResizeObserver) new ResizeObserver(schedule).observe(el);
            else window.addEventListener('resize', schedule);
            el.classList.add('has-scroll-fade');
            update();
        });
    }

    // Swap a short label with a quick vertical slide (skipped for reduced motion by CSS).
    function swapLabel(label, text) {
        label.textContent = text;
        label.classList.remove('oc-swap');
        void label.offsetWidth; // restart the animation
        label.classList.add('oc-swap');
    }

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
            // Brief cross-fade of surface colors only while switching; never applied to ordinary interaction.
            if (!(reducedMotion && reducedMotion.matches)) {
                root.classList.add('oc-theme-switching');
                setTimeout(function () { root.classList.remove('oc-theme-switching'); }, 260);
            }
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
        var envEl = document.getElementById('opconsole-confirm-env');
        var hintEl = document.getElementById('opconsole-confirm-hint');
        var liveRegion = document.getElementById('opconsole-live-region');
        var pendingForm = null;
        var lastTrigger = null;

        // High-risk confirmation: press and hold, or press twice. Submission is driven by this timer,
        // never by an animation event, and only ever after the dialog was explicitly opened.
        var HOLD_MS = 1200;
        var ARM_MS = 5000;
        var holdMode = false;
        var holdTimer = null;
        var holdStarted = 0;
        var armedUntil = 0;
        var acceptText = 'Confirm';

        function setAcceptLabel(text) {
            if (!accept) return;
            var label = accept.querySelector('[data-oc-accept-label]');
            var fill = accept.querySelector('[data-oc-accept-fill-label]');
            if (label) label.textContent = text; else accept.textContent = text;
            if (fill) fill.textContent = text;
        }

        function resetHold() {
            if (holdTimer) clearTimeout(holdTimer);
            holdTimer = null;
            armedUntil = 0;
            if (!accept) return;
            accept.classList.remove('is-holding', 'is-armed', 'is-confirmed');
            accept.removeAttribute('aria-busy');
            setAcceptLabel(holdMode ? 'Hold to ' + acceptText.charAt(0).toLowerCase() + acceptText.slice(1) : acceptText);
        }

        function startHold() {
            if (!holdMode || holdTimer || !pendingForm) return;
            holdStarted = Date.now();
            accept.classList.add('is-holding');
            accept.setAttribute('aria-busy', 'true');
            holdTimer = setTimeout(function () {
                holdTimer = null;
                if (Date.now() - holdStarted >= HOLD_MS - 50) confirmNow();
            }, HOLD_MS);
        }

        function cancelHold() {
            if (!holdTimer) return;
            clearTimeout(holdTimer);
            holdTimer = null;
            accept.classList.remove('is-holding');
            accept.removeAttribute('aria-busy');
        }

        function confirmNow() {
            if (!pendingForm) return;
            var form = pendingForm;
            accept.classList.remove('is-holding');
            accept.classList.add('is-confirmed');
            accept.disabled = true;
            closeDialog(false);
            form.dataset.ocConfirmed = 'true';
            markRunning(form);
            form.submit();
        }

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
            if (holdTimer) clearTimeout(holdTimer);
            holdTimer = null;
            if (restoreFocus && lastTrigger && typeof lastTrigger.focus === 'function') {
                lastTrigger.focus();
            }
        }

        function markRunning(form) {
            var submitBtn = form.querySelector('button[type="submit"]');
            if (!submitBtn) return;
            submitBtn.disabled = true;
            submitBtn.setAttribute('aria-busy', 'true');
            submitBtn.classList.add('is-running');
            var label = submitBtn.querySelector('[data-oc-btn-label]');
            if (label) label.textContent = 'Running…';
            // The page is about to navigate; stop a second operation from being queued meanwhile.
            document.querySelectorAll('form[data-confirm] button[type="submit"]').forEach(function (other) {
                if (other !== submitBtn) other.disabled = true;
            });
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
            messageEl.textContent = form.dataset.confirm;
            if (envEl) {
                envEl.textContent = form.dataset.confirmEnvironment ? 'Environment: ' + form.dataset.confirmEnvironment : '';
                envEl.hidden = !form.dataset.confirmEnvironment;
                envEl.classList.toggle('is-prod', form.dataset.confirmEnvironment === 'production');
            }
            holdMode = form.dataset.confirmRisk === 'high';
            acceptText = form.dataset.confirmAccept || 'Confirm';
            accept.className = holdMode ? 'oc-btn oc-btn-danger oc-hold' : 'oc-btn oc-btn-primary';
            accept.disabled = false;
            resetHold();
            if (hintEl) {
                hintEl.textContent = holdMode ? 'High-risk action: press and hold the button (or hold Space or Enter) for a moment, or press it twice.' : '';
                hintEl.hidden = !holdMode;
            }

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

        accept.style.setProperty('--oc-hold-ms', HOLD_MS + 'ms');

        accept.addEventListener('pointerdown', function (event) {
            if (!holdMode || event.button !== 0) return;
            if (accept.setPointerCapture) accept.setPointerCapture(event.pointerId);
            startHold();
        });
        ['pointerup', 'pointercancel', 'lostpointercapture'].forEach(function (type) {
            accept.addEventListener(type, cancelHold);
        });

        // Holding a key works like holding the pointer; default activation is suppressed so a tap does nothing.
        accept.addEventListener('keydown', function (event) {
            if (!holdMode || (event.key !== ' ' && event.key !== 'Enter')) return;
            event.preventDefault();
            if (!event.repeat) startHold();
        });
        accept.addEventListener('keyup', function (event) {
            if (!holdMode || (event.key !== ' ' && event.key !== 'Enter')) return;
            event.preventDefault();
            cancelHold();
        });

        accept.addEventListener('click', function () {
            if (!pendingForm) return;
            if (!holdMode) {
                confirmNow();
                return;
            }
            // Alternative to holding (assistive technology, switch access): a second deliberate press.
            if (Date.now() < armedUntil) {
                confirmNow();
                return;
            }
            armedUntil = Date.now() + ARM_MS;
            accept.classList.add('is-armed');
            setAcceptLabel('Press again to confirm');
            if (liveRegion) liveRegion.textContent = 'Press the button again within five seconds to confirm.';
            setTimeout(function () {
                if (pendingForm && Date.now() >= armedUntil) resetHold();
            }, ARM_MS + 50);
        });
    }

    function initCopyButtons() {
        var liveRegion = document.getElementById('opconsole-live-region');

        document.querySelectorAll('[data-copy]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var label = btn.querySelector('[data-oc-copy-label]');
                var originalText = label ? label.textContent : null;

                var done = function () {
                    if (!label) {
                        btn.classList.add('is-done');
                        setTimeout(function () { btn.classList.remove('is-done'); }, 1600);
                    }
                    if (label && originalText !== null) {
                        btn.classList.add('is-done');
                        swapLabel(label, 'Copied');
                        setTimeout(function () {
                            btn.classList.remove('is-done');
                            swapLabel(label, originalText);
                        }, 1600);
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

    // Health: status chips (or the plain select without chips) plus a name filter, all client-side.
    // The status lives in ?check_status= so a copied link opens the same view.
    function initHealthFilter() {
        var filter = document.querySelector('[data-oc-health-filter]');
        if (!filter) return;
        var chipsWrap = document.querySelector('[data-oc-health-chips]');
        var chips = chipsWrap ? Array.prototype.slice.call(chipsWrap.querySelectorAll('[data-oc-health-chip]')) : [];
        var search = document.querySelector('[data-oc-health-search]');
        var items = Array.prototype.slice.call(document.querySelectorAll('[data-oc-check-item]'));
        var groups = Array.prototype.slice.call(document.querySelectorAll('[data-oc-check-group]'));
        var empty = document.querySelector('[data-oc-filter-empty]');
        var count = document.querySelector('[data-oc-health-count]');
        var allowed = ['all', 'attention', 'critical', 'warning', 'unknown', 'healthy'];
        var params = new URLSearchParams(window.location.search);
        var initial = params.get('check_status') || 'all';
        filter.value = allowed.indexOf(initial) !== -1 ? initial : 'all';

        if (chipsWrap && chips.length) {
            chipsWrap.hidden = false;
            filter.hidden = true;
        }

        function apply() {
            var value = filter.value;
            var query = search ? search.value.trim().toLocaleLowerCase() : '';
            var visible = 0;
            items.forEach(function (item) {
                var status = item.dataset.ocStatus;
                var statusMatch = value === 'all' || (value === 'attention' && ['critical', 'warning', 'unknown'].indexOf(status) !== -1) || value === status;
                var textMatch = !query || item.textContent.toLocaleLowerCase().indexOf(query) !== -1;
                var show = statusMatch && textMatch;
                item.hidden = !show;
                if (show) visible++;
            });
            groups.forEach(function (group) {
                group.hidden = !group.querySelector('[data-oc-check-item]:not([hidden])');
            });
            chips.forEach(function (chip) {
                chip.setAttribute('aria-pressed', chip.dataset.ocHealthChip === value ? 'true' : 'false');
            });
            if (empty) empty.hidden = visible > 0;
            if (count) count.textContent = visible + ' health checks shown.';
        }

        function remember(value) {
            if (!window.history || !window.history.replaceState) return;
            var url = new URL(window.location.href);
            if (value === 'all') url.searchParams.delete('check_status');
            else url.searchParams.set('check_status', value);
            url.hash = 'checks';
            window.history.replaceState(null, '', url.toString());
        }

        function choose(value) {
            filter.value = value;
            apply();
            remember(value);
        }

        filter.addEventListener('change', function () { choose(filter.value); });
        chips.forEach(function (chip) {
            chip.addEventListener('click', function () { choose(chip.dataset.ocHealthChip); });
        });
        if (search) search.addEventListener('input', apply);
        var reset = document.querySelector('[data-oc-health-reset]');
        if (reset) reset.addEventListener('click', function () {
            if (search) search.value = '';
            choose('all');
        });
        apply();
    }

    function initHistoryFilter() {
        var chips = Array.prototype.slice.call(document.querySelectorAll('[data-oc-history-chip]'));
        if (!chips.length) return;
        var items = Array.prototype.slice.call(document.querySelectorAll('[data-oc-history-item]'));
        var days = Array.prototype.slice.call(document.querySelectorAll('[data-oc-history-day]'));
        var empty = document.querySelector('[data-oc-history-empty]');
        chips.forEach(function (chip) {
            chip.addEventListener('click', function () {
                var value = chip.dataset.ocHistoryChip;
                var visible = 0;
                chips.forEach(function (other) { other.setAttribute('aria-pressed', other === chip ? 'true' : 'false'); });
                items.forEach(function (item) {
                    var show = value === 'all' || item.dataset.ocHistoryStatus === value;
                    item.hidden = !show;
                    if (show) visible++;
                });
                days.forEach(function (day) { day.hidden = !day.querySelector('[data-oc-history-item]:not([hidden])'); });
                if (empty) empty.hidden = visible > 0;
            });
        });
    }

    function initSystemSearch() {
        var input = document.querySelector('[data-oc-system-search]');
        if (!input) return;
        var rows = Array.prototype.slice.call(document.querySelectorAll('[data-oc-system-row]'));
        var groups = Array.prototype.slice.call(document.querySelectorAll('[data-oc-system-group]'));
        var empty = document.querySelector('[data-oc-system-empty]');
        input.addEventListener('input', function () {
            var query = input.value.trim().toLocaleLowerCase();
            rows.forEach(function (row) {
                var groupName = (row.closest('[data-oc-system-group]') || row).querySelector('h3');
                var haystack = row.textContent + ' ' + (groupName ? groupName.textContent : '');
                row.hidden = !!query && haystack.toLocaleLowerCase().indexOf(query) === -1;
            });
            var any = false;
            groups.forEach(function (group) {
                var show = !!group.querySelector('[data-oc-system-row]:not([hidden])');
                group.hidden = !show;
                any = any || show;
            });
            if (empty) empty.hidden = any;
        });
    }

    // Logs: j / k move between entries, Enter (native <summary>) opens one.
    function initLogKeys() {
        var list = document.querySelector('[data-oc-log-list]');
        if (!list) return;
        document.addEventListener('keydown', function (event) {
            if (event.defaultPrevented || event.metaKey || event.ctrlKey || event.altKey) return;
            if (event.key !== 'j' && event.key !== 'k') return;
            var active = document.activeElement;
            if (active && (/^(INPUT|TEXTAREA|SELECT)$/).test(active.tagName)) return;
            var panel = list.closest('[data-oc-panel]');
            if (panel && panel.hidden) return;
            var summaries = Array.prototype.slice.call(list.querySelectorAll('.oc-log-entry > summary'));
            if (!summaries.length) return;
            var index = summaries.indexOf(active);
            var next = event.key === 'j' ? Math.min(index + 1, summaries.length - 1) : Math.max(index - 1, 0);
            if (index === -1) next = 0;
            event.preventDefault();
            summaries[next].focus();
            if (typeof summaries[next].scrollIntoView === 'function') summaries[next].scrollIntoView({ block: 'nearest' });
        });
    }

    // Command palette (Ctrl/Cmd K): jump to tabs, health checks, operations and log files already on the page.
    function initPalette() {
        var dialog = document.getElementById('opconsole-palette');
        var input = document.getElementById('opconsole-palette-input');
        var list = document.getElementById('opconsole-palette-list');
        var openers = Array.prototype.slice.call(document.querySelectorAll('[data-oc-palette-open]'));
        if (!dialog || !input || !list || typeof dialog.showModal !== 'function') return;

        var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
        document.querySelectorAll('[data-oc-palette-kbd]').forEach(function (kbd) { kbd.textContent = mac ? '⌘K' : 'Ctrl K'; });
        openers.forEach(function (btn) { btn.hidden = false; });

        function text(el) { return (el && el.textContent || '').replace(/\s+/g, ' ').trim(); }

        function entries() {
            var out = [];
            document.querySelectorAll('[data-oc-tab]').forEach(function (tab) {
                var label = text(tab.querySelector('span'));
                out.push({ label: label, hint: 'Tab', tone: '', run: function () { tab.click(); tab.focus(); } });
            });
            document.querySelectorAll('[data-oc-check-item]').forEach(function (item) {
                var label = text(item.querySelector('.oc-check-label strong'));
                var tone = (item.className.match(/is-(ok|warning|bad|unknown|info)/) || [''])[0];
                out.push({ label: label, hint: 'Health check', tone: tone, run: function () { go('#checks', item); } });
            });
            document.querySelectorAll('.oc-action[id^="action-"]').forEach(function (card) {
                out.push({ label: text(card.querySelector('h3')), hint: 'Operation', tone: '', run: function () { go('#' + card.id, card); } });
            });
            document.querySelectorAll('[data-oc-palette-item]').forEach(function (link) {
                out.push({ label: link.dataset.ocPaletteItem, hint: 'Open', tone: '', run: function () { window.location.assign(link.href); } });
            });
            return out;
        }

        function go(hash, target) {
            if (window.location.hash === hash) window.dispatchEvent(new HashChangeEvent('hashchange'));
            else window.location.hash = hash;
            setTimeout(function () {
                var details = target.querySelector('details');
                if (details) details.open = true;
                if (target.hidden) return;
                target.scrollIntoView({ block: 'center' });
                var focusable = target.querySelector('summary, button, a');
                if (focusable) focusable.focus({ preventScroll: true });
            }, 30);
        }

        var all = [];
        var shown = [];
        var active = 0;

        function render() {
            var query = input.value.trim().toLocaleLowerCase();
            shown = all.filter(function (entry) {
                return !query || (entry.label + ' ' + entry.hint).toLocaleLowerCase().indexOf(query) !== -1;
            }).slice(0, 40);
            active = Math.min(active, Math.max(shown.length - 1, 0));
            list.textContent = '';
            if (!shown.length) {
                var none = document.createElement('li');
                none.className = 'oc-palette-empty';
                none.textContent = 'No matches. Try a tab name, a check, or a log file.';
                list.appendChild(none);
                input.removeAttribute('aria-activedescendant');
                return;
            }
            shown.forEach(function (entry, index) {
                var li = document.createElement('li');
                li.className = 'oc-palette-item';
                li.id = 'opconsole-palette-item-' + index;
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', index === active ? 'true' : 'false');
                if (entry.tone) {
                    var dot = document.createElement('span');
                    dot.className = 'oc-dot ' + entry.tone;
                    li.appendChild(dot);
                }
                var label = document.createElement('span');
                label.textContent = entry.label;
                li.appendChild(label);
                var hint = document.createElement('small');
                hint.textContent = entry.hint;
                li.appendChild(hint);
                li.addEventListener('mousemove', function () { if (active !== index) { active = index; mark(); } });
                li.addEventListener('click', function () { choose(index); });
                list.appendChild(li);
            });
            mark();
        }

        function mark() {
            Array.prototype.forEach.call(list.children, function (li, index) {
                li.setAttribute('aria-selected', index === active ? 'true' : 'false');
                if (index === active && li.scrollIntoView) li.scrollIntoView({ block: 'nearest' });
            });
            if (shown.length) input.setAttribute('aria-activedescendant', 'opconsole-palette-item-' + active);
        }

        function choose(index) {
            var entry = shown[index];
            if (!entry) return;
            dialog.close();
            entry.run();
        }

        function open() {
            if (dialog.open) return;
            all = entries();
            input.value = '';
            active = 0;
            render();
            dialog.showModal();
            input.focus();
        }

        openers.forEach(function (btn) { btn.addEventListener('click', open); });
        document.addEventListener('keydown', function (event) {
            if ((event.metaKey || event.ctrlKey) && (event.key === 'k' || event.key === 'K')) {
                if (document.getElementById('opconsole-confirm-modal') && document.getElementById('opconsole-confirm-modal').open) return;
                event.preventDefault();
                if (dialog.open) dialog.close(); else open();
            }
        });
        input.addEventListener('input', function () { active = 0; render(); });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') { event.preventDefault(); active = Math.min(active + 1, shown.length - 1); mark(); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); active = Math.max(active - 1, 0); mark(); }
            else if (event.key === 'Enter') { event.preventDefault(); choose(active); }
        });
        dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
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
