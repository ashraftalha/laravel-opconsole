@php($message ??= 'Nothing to show.')

<div class="oc-empty" role="status">
    <span class="oc-empty-icon">@include('opconsole::partials.logo', ['variant' => 'mark', 'label' => null])</span>
    <p>{{ $message }}</p>
    @if (! empty($actionUrl) && ! empty($actionLabel))
        <a href="{{ $actionUrl }}" class="oc-btn oc-btn-sm oc-btn-accent">{{ $actionLabel }}</a>
    @endif
</div>
