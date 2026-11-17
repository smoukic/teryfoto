<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title', 'Vyber si')</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body>
    <header class="masthead">
        <div class="frame flex items-center justify-between gap-4">
            <span class="brand"><span class="brand-icon" aria-hidden="true">✓</span> Vyber si</span>
            @yield('header')
        </div>
    </header>
    @yield('body')
</body>
</html>
