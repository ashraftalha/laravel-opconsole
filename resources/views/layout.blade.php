<!doctype html>
<html lang="en" dir="ltr">

    <head>
        <meta charset="utf-8" />
        <title>@hasSection('title')@yield('title') - @endif OpConsole</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        @if (config('opconsole.assets.use_cdn'))
            <link href="{{ config('opconsole.assets.bootstrap_css') }}" rel="stylesheet">
            <link href="{{ config('opconsole.assets.icons_css') }}" rel="stylesheet">
        @endif
        <link href="{{ asset('vendor/opconsole/opconsole.css') }}" rel="stylesheet">
        @stack('styles')
    </head>

    <body>
        <div class="oc-shell">
            <main class="oc-main">
                <div class="container-fluid">
                    <div class="py-4">
                        @include('opconsole::partials.flash')
                        @yield('content')
                    </div>
                </div>
            </main>
        </div>
        @if (config('opconsole.assets.use_cdn'))
            <script src="{{ config('opconsole.assets.bootstrap_js') }}"></script>
        @endif
        <script src="{{ asset('vendor/opconsole/opconsole.js') }}" defer></script>
        @include('opconsole::partials.confirm-modal')
        @stack('scripts')
    </body>

</html>
