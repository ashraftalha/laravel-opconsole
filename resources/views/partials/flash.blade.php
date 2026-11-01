@if (session('opconsole_success'))
    <div class="alert alert-success alert-dismissible fade show mt-0 mb-3" role="alert">
        <i class="uil uil-check-circle fs-5 align-middle me-1"></i> {{ session('opconsole_success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

@if (session('opconsole_error'))
    <div class="alert alert-danger alert-dismissible fade show mt-0 mb-3" role="alert">
        <i class="uil uil-exclamation-triangle fs-5 align-middle me-1"></i> {{ session('opconsole_error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif
