{{-- OpConsole logo, same geometry as art/logo.svg (which is not shipped to vendor/).
     Colour comes from currentColor / --oc-brand so it follows light, dark and embedded themes.
     $variant: 'mark' (square only) or 'full' (mark + wordmark set in the page font).
     $label: accessible name; pass null for a decorative copy next to visible text. --}}
@php($ocLogoVariant = $variant ?? 'full')
@php($ocLogoLabel = array_key_exists('label', get_defined_vars()) ? $label : 'OpConsole')
<span @class(['oc-logo', 'oc-logo-'.$ocLogoVariant, $class ?? null])@if ($ocLogoLabel) role="img" aria-label="{{ $ocLogoLabel }}"@else aria-hidden="true"@endif>
    <svg class="oc-logo-mark" viewBox="8 10 80 80" width="28" height="28" fill="none" stroke="currentColor" stroke-width="6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <rect x="12" y="14" width="72" height="72" rx="16" />
        <polyline points="24,52 36,52 42,38 52,66 58,52 64,52" />
        <rect x="68" y="47" width="6" height="12" rx="1" fill="currentColor" stroke="none" />
    </svg>
    @if ($ocLogoVariant === 'full')
        <span class="oc-logo-word" aria-hidden="true"><span class="oc-logo-op">Op</span><span class="oc-logo-console">Console</span></span>
    @endif
</span>
