<!doctype html>
<html lang="en" dir="ltr">

    <head>
        <meta charset="utf-8" />
        <title>@hasSection('title')@yield('title') - @endif OpConsole</title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <meta name="csrf-token" content="{{ csrf_token() }}">
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
