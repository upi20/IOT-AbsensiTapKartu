<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · Absensi RFID</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ @filemtime(public_path('css/admin.css')) }}">
</head>
<body>
<main class="auth">
    <div class="auth-card">
        <div class="brand">
            <span class="brand-mark"><x-icon name="tap"/></span>
            <span><span class="brand-name">Absensi RFID</span><span class="brand-sub">Panel admin</span></span>
        </div>
        @yield('content')
    </div>
</main>
</body>
</html>
