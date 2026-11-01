/* Laravel OpConsole — confirmation modal and copy-to-clipboard buttons. */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        initConfirmModal();
        initCopyButtons();
    });

    function initConfirmModal() {
        var modalEl = document.getElementById('opconsole-confirm-modal');
        if (!modalEl || typeof bootstrap === 'undefined') return;

        var modal = new bootstrap.Modal(modalEl);
        var accept = document.getElementById('opconsole-confirm-accept');
        var pending = null;

        document.addEventListener('submit', function (event) {
            var form = event.target;
            if (!form.dataset || !form.dataset.confirm) return;
            event.preventDefault();
            if (pending) return;
            pending = form;
            document.getElementById('opconsole-confirm-title').textContent = form.dataset.confirmTitle || 'Confirm action';
            document.getElementById('opconsole-confirm-message').textContent = form.dataset.confirm;
            document.getElementById('opconsole-confirm-dismiss').textContent = form.dataset.confirmDismiss || 'Cancel';
            accept.textContent = form.dataset.confirmAccept || 'Confirm';
            accept.disabled = false;
            modal.show();
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
            pending = null;
        });

        accept.addEventListener('click', function () {
            if (!pending) return;
            var form = pending;
            pending = null;
            accept.disabled = true;
            modal.hide();
            form.submit();
        });
    }

    function initCopyButtons() {
        document.querySelectorAll('[data-copy]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var icon = btn.querySelector('i');
                var done = function () {
                    if (!icon) return;
                    var old = icon.className;
                    icon.className = 'uil uil-check';
                    setTimeout(function () { icon.className = old; }, 1500);
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(btn.dataset.copy).then(done);
                } else {
                    var input = document.createElement('textarea'); // keeps line breaks (stack traces)
                    input.value = btn.dataset.copy;
                    document.body.appendChild(input);
                    input.select();
                    document.execCommand('copy');
                    input.remove();
                    done();
                }
            });
        });
    }
})();
