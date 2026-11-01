@if (session('opconsole_success'))
    <div class="oc-flash oc-flash-success" role="status" data-oc-alert>
        @include('opconsole::partials.icon', ['name' => 'check-circle'])
        <span>{{ session('opconsole_success') }}</span>
        <button type="button" class="oc-flash-close" data-oc-dismiss aria-label="Dismiss notification">
            @include('opconsole::partials.icon', ['name' => 'x'])
        </button>
    </div>
@endif

@if (session('opconsole_warning'))
    <div class="oc-flash oc-flash-warning" role="alert" data-oc-alert>
        @include('opconsole::partials.icon', ['name' => 'alert-triangle'])
        <span>{{ session('opconsole_warning') }}</span>
        <button type="button" class="oc-flash-close" data-oc-dismiss aria-label="Dismiss notification">
            @include('opconsole::partials.icon', ['name' => 'x'])
        </button>
    </div>
@endif

@if (session('opconsole_error'))
    <div class="oc-flash oc-flash-danger" role="alert" data-oc-alert>
        @include('opconsole::partials.icon', ['name' => 'alert-octagon'])
        <span>{{ session('opconsole_error') }}</span>
        <button type="button" class="oc-flash-close" data-oc-dismiss aria-label="Dismiss notification">
            @include('opconsole::partials.icon', ['name' => 'x'])
        </button>
    </div>
@endif
