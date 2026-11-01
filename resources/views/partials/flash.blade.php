@foreach ([
    ['opconsole_success', 'oc-flash-success', 'status', 'check-circle'],
    ['opconsole_warning', 'oc-flash-warning', 'alert', 'alert-triangle'],
    ['opconsole_error', 'oc-flash-danger', 'alert', 'alert-octagon'],
] as [$ocKey, $ocClass, $ocRole, $ocIcon])
    @if (session($ocKey))
        <div class="oc-flash {{ $ocClass }}" role="{{ $ocRole }}" data-oc-alert>
            @include('opconsole::partials.icon', ['name' => $ocIcon])
            <span>{{ session($ocKey) }}</span>
            <button type="button" class="oc-flash-close" data-oc-dismiss aria-label="Dismiss notification">
                @include('opconsole::partials.icon', ['name' => 'x'])
            </button>
        </div>
    @endif
@endforeach
