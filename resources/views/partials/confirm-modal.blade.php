{{-- Dependency-free accessible <dialog> confirmation for any <form data-confirm="message">. --}}
<dialog class="oc-dialog" id="opconsole-confirm-modal" aria-labelledby="opconsole-confirm-title" aria-describedby="opconsole-confirm-message">
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
        </div>
        <div class="oc-dialog-foot">
            <button type="button" class="btn btn-soft-secondary" id="opconsole-confirm-dismiss">Cancel</button>
            <button type="button" class="btn btn-danger" id="opconsole-confirm-accept">Confirm</button>
        </div>
    </div>
</dialog>
