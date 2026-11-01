@php($message ??= 'Nothing to show.')

<div class="oc-empty" role="status">
    <span class="oc-empty-icon">@include('opconsole::partials.icon', ['name' => 'info'])</span>
    <p>{{ $message }}</p>
    @if (! empty($actionUrl) && ! empty($actionLabel))
        <a href="{{ $actionUrl }}" class="btn btn-sm btn-soft-primary">{{ $actionLabel }}</a>
    @endif
</div>
