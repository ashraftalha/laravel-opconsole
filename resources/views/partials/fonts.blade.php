{{-- IBM Plex from Google Fonts. Opt-in via OPCONSOLE_WEB_FONTS=true (config opconsole.web_fonts); off by default to avoid the
     external request. When off, the stylesheet uses the system font stack. --}}
@if (config('opconsole.web_fonts', false))
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500;600&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap">
@endif
