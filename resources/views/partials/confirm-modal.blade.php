{{-- Any <form data-confirm="message"> asks for confirmation here before it is submitted. --}}
<div class="modal fade" id="opconsole-confirm-modal" tabindex="-1" aria-labelledby="opconsole-confirm-title" aria-describedby="opconsole-confirm-message" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded shadow border-0">
            <div class="modal-header border-bottom">
                <h5 class="modal-title" id="opconsole-confirm-title">Confirm action</h5>
                <button type="button" class="btn btn-icon btn-close" data-bs-dismiss="modal" aria-label="Close"><i class="uil uil-times fs-4 text-dark"></i></button>
            </div>
            <div class="modal-body">
                <p class="text-muted mb-0" id="opconsole-confirm-message"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" id="opconsole-confirm-dismiss" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="opconsole-confirm-accept">Confirm</button>
            </div>
        </div>
    </div>
</div>
