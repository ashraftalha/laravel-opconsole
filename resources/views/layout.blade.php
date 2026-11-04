<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ in_array(strtok(app()->getLocale(), '_-'), ['ar', 'he', 'fa', 'ur'], true) ? 'rtl' : 'ltr' }}" data-oc-theme="{{ $theme ?? 'auto' }}">

    <head>
        <meta charset="utf-8" />
        <title>@yield('title', 'OpConsole')@if (config('app.name') && config('app.name') !== 'OpConsole') - {{ config('app.name') }}@endif</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @if (! empty($assets['icon']))<link rel="icon" type="image/svg+xml" href="{{ $assets['icon'] }}">@endif
        @include('opconsole::partials.fonts')
        <link href="{{ $assets['css'] }}" rel="stylesheet">
        @stack('styles')
    </head>

    <body class="oc-standalone">
        <div class="oc-shell">
            <main class="oc-main" id="opconsole-main">
                <div class="oc-container">
                    <div class="oc-page-pad">
                        @include('opconsole::partials.flash')
                        @yield('content')
                    </div>
                </div>
            </main>
        </div>
        <script src="{{ $assets['js'] }}" defer></script>
        @include('opconsole::partials.confirm-modal')
        @stack('scripts')
    </body>

</html>
