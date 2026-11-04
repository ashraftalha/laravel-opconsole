{{-- Dependency-free accessible <dialog> confirmation for any <form data-confirm="message">.
     High-risk forms (data-confirm-risk="high") additionally require press-and-hold, or a second explicit press. --}}
<dialog class="oc-dialog" id="opconsole-confirm-modal" aria-modal="true" aria-labelledby="opconsole-confirm-title" aria-describedby="opconsole-confirm-message opconsole-confirm-hint">
    <div class="oc-dialog-card">
        <div class="oc-dialog-head">
            <div>
                <span class="oc-risk-badge" id="opconsole-confirm-risk" hidden></span>
                <h2 class="oc-dialog-title" id="opconsole-confirm-title">Confirm action</h2>
            </div>
            <button type="button" class="oc-icon-btn" id="opconsole-confirm-close" aria-label="Close dialog">
                @include('opconsole::partials.icon', ['name' => 'x'])
            </button>
        </div>
        <div class="oc-dialog-body">
            <p class="oc-dialog-message" id="opconsole-confirm-message"></p>
            <code class="oc-command-pill" id="opconsole-confirm-command" dir="ltr" hidden></code>
            <p class="oc-dialog-env" id="opconsole-confirm-env" hidden></p>
            <p class="oc-dialog-hint" id="opconsole-confirm-hint" hidden></p>
        </div>
        <div class="oc-dialog-foot">
            <button type="button" class="oc-btn oc-btn-soft-secondary" id="opconsole-confirm-dismiss">Cancel</button>
            <button type="button" class="oc-btn oc-btn-danger" id="opconsole-confirm-accept">
                <span class="oc-hold-label" data-oc-accept-label>Confirm</span>
                <span class="oc-hold-fill" aria-hidden="true"><span data-oc-accept-fill-label>Confirm</span></span>
            </button>
        </div>
    </div>
</dialog>
