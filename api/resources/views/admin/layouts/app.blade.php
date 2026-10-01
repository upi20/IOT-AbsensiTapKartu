<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title') · Absensi RFID</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ @filemtime(public_path('css/admin.css')) }}">
</head>
<body>
@php
    $nav = [
        ['admin.dashboard', 'admin.dashboard', 'home', 'Dasbor'],
        ['admin.members.index', 'admin.members.*', 'users', 'Anggota'],
        ['admin.attendances.index', 'admin.attendances.*', 'tap', 'Kehadiran'],
        ['admin.unknown-cards', 'admin.unknown-cards', 'card', 'Kartu belum terdaftar'],
        ['admin.reports.index', 'admin.reports.*', 'report', 'Rekap'],
        ['admin.devices.index', 'admin.devices.*', 'device', 'Alat'],
        ['admin.firmware.index', 'admin.firmware.*', 'upload', 'Firmware'],
        ['admin.announcements.index', 'admin.announcements.*', 'megaphone', 'Pengumuman'],
        ['admin.settings', 'admin.settings*', 'settings', 'Pengaturan'],
    ];
    $user = auth()->user();
@endphp
<input type="checkbox" id="nav-toggle" class="nav-toggle" aria-hidden="true">
<div class="shell">
    <label for="nav-toggle" class="nav-backdrop" aria-hidden="true"></label>
    <aside class="sidebar" id="sidebar">
        <a href="{{ route('admin.dashboard') }}" class="brand">
            <span class="brand-mark"><x-icon name="tap"/></span>
            <span><span class="brand-name">Absensi RFID</span><span class="brand-sub">Panel admin</span></span>
        </a>
        <nav class="nav" aria-label="Menu utama">
            @foreach ($nav as [$route, $pattern, $icon, $label])
                <a href="{{ route($route) }}" @class(['active' => request()->routeIs($pattern)]) @if (request()->routeIs($pattern)) aria-current="page" @endif>
                    <x-icon :name="$icon"/>
                    <span>{{ $label }}</span>
                </a>
            @endforeach
        </nav>
        <div class="sidebar-footer">
            <div class="user">
                <span class="avatar">{{ mb_substr($user->name ?: $user->username, 0, 1) }}</span>
                <div>
                    <div class="user-name">{{ $user->name ?: $user->username }}</div>
                    <div class="user-mail" title="{{ $user->email }}">{{ '@'.$user->username }}</div>
                </div>
            </div>
            <form method="POST" action="{{ route('admin.logout') }}">
                @csrf
                <button type="submit" class="btn btn-ghost btn-block" style="justify-content:flex-start"><x-icon name="logout"/> Keluar</button>
            </form>
        </div>
    </aside>

    <div class="main">
        <header class="topbar">
            <label for="nav-toggle" class="menu-btn" aria-label="Buka menu"><x-icon name="menu"/></label>
            <a href="{{ route('admin.dashboard') }}" class="brand">
                <span class="brand-mark"><x-icon name="tap"/></span>
                <span class="brand-name">Absensi RFID</span>
            </a>
        </header>

        <main class="content">
            @if (session('success'))
                <div class="alert success" role="status"><p>{{ session('success') }}</p></div>
            @endif
            @if (session('error'))
                <div class="alert error" role="alert"><p>{{ session('error') }}</p></div>
            @endif

            @yield('content')
        </main>
    </div>
</div>
<script src="{{ asset('js/admin.js') }}?v={{ @filemtime(public_path('js/admin.js')) }}" defer></script>
</body>
</html>
