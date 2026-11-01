@php
    $ocRoutePrefix = config('opconsole.route.name_prefix', 'opconsole.');
    $ocCssUrl = is_file(public_path('vendor/opconsole/opconsole.css'))
        ? asset('vendor/opconsole/opconsole.css')
        : route($ocRoutePrefix.'asset', 'opconsole.css');
    $ocJsUrl = is_file(public_path('vendor/opconsole/opconsole.js'))
        ? asset('vendor/opconsole/opconsole.js')
        : route($ocRoutePrefix.'asset', 'opconsole.js');
@endphp
<!doctype html>
<html lang="en" dir="ltr">

    <head>
        <meta charset="utf-8" />
        <title>@hasSection('title')@yield('title') - @endif OpConsole</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @if (config('opconsole.assets.use_cdn'))
            @if (config('opconsole.assets.bootstrap_css'))
                <link href="{{ config('opconsole.assets.bootstrap_css') }}" rel="stylesheet">
            @endif
            @if (config('opconsole.assets.icons_css'))
                <link href="{{ config('opconsole.assets.icons_css') }}" rel="stylesheet">
            @endif
        @endif
        <link href="{{ $ocCssUrl }}" rel="stylesheet">
        @stack('styles')
    </head>

    <body class="oc-standalone">
        <div class="oc-shell">
            <main class="oc-main" id="opconsole-main">
                <div class="container-fluid oc-container">
                    <div class="oc-page-pad">
                        @include('opconsole::partials.flash')
                        @yield('content')
                    </div>
                </div>
            </main>
        </div>
        @if (config('opconsole.assets.use_cdn') && config('opconsole.assets.bootstrap_js'))
            <script src="{{ config('opconsole.assets.bootstrap_js') }}"></script>
        @endif
        <script src="{{ $ocJsUrl }}" defer></script>
        @include('opconsole::partials.confirm-modal')
        @stack('scripts')
    </body>

</html>
