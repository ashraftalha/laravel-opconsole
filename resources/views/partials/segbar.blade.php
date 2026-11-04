{{-- Health split bar drawn in SVG with plain attributes (no inline style=), so it stays strict-CSP compatible.
     $counts: the overview array (critical, warnings, unknown, advisory, healthy). $label: accessible text, or null if decorative. --}}
@php($ocSegParts = array_filter([['is-bad', $counts['critical']], ['is-warning', $counts['warnings']], ['is-unknown', $counts['unknown']], ['is-info', $counts['advisory']], ['is-ok', $counts['healthy']]], fn ($part) => $part[1] > 0))
@php($ocSegTotal = max(1, array_sum(array_column($ocSegParts, 1))))
@php($ocSegGap = count($ocSegParts) > 1 ? 0.6 : 0)
@php($ocSegX = 0)
<svg class="oc-segbar {{ $class ?? '' }}" viewBox="0 0 100 4" preserveAspectRatio="none" focusable="false" @if ($label) role="img" aria-label="{{ $label }}" @else aria-hidden="true" @endif>
    @foreach ($ocSegParts as [$ocSegTone, $ocSegCount])
        @php($ocSegW = max(0.8, 100 * $ocSegCount / $ocSegTotal - ($loop->last ? 0 : $ocSegGap)))
        <rect class="{{ $ocSegTone }}" x="{{ round($ocSegX, 3) }}" y="0" width="{{ round($ocSegW, 3) }}" height="4" />
        @php($ocSegX += $ocSegW + $ocSegGap)
    @endforeach
</svg>
