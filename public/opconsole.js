/* Laravel OpConsole — dependency-free confirmation dialog, hash navigation, flash dismiss, and clipboard helper. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initConfirmDialog();
        initCopyButtons();
        initAlertDismiss();
        initHashReveal();
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
            if (dismiss) dismiss.textContent = form.dataset.confirmDismiss || 'Cancel';
            accept.textContent = form.dataset.confirmAccept || 'Confirm';
            accept.className = form.dataset.confirmRisk === 'high' ? 'btn btn-danger' : 'btn btn-primary';
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
                    riskEl.className = 'oc-risk-badge ' + (form.dataset.confirmRisk === 'high' ? 'is-high' : 'is-low');
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
                    input.style.position = 'fixed';
                    input.style.opacity = '0';
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
})();
