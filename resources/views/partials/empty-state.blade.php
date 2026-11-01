@php($message ??= 'Nothing to show.')

<div class="oc-empty">
    <i class="uil uil-info-circle"></i>
    <p>{{ $message }}</p>
</div>
