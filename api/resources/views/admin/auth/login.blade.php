@extends('admin.layouts.guest')

@section('title', 'Masuk')

@section('content')
    <div class="card">
        <div class="card-body">
            <h1>Masuk ke panel admin</h1>
            <p class="lead">Gunakan username atau email beserta password Anda.</p>

            @if (session('status'))
                <div class="alert success" role="status"><p>{{ session('status') }}</p></div>
            @endif

            <form method="POST" action="{{ route('login.attempt') }}" class="form" novalidate>
                @csrf
                <div class="field">
                    <label for="login">Username atau email</label>
                    <input id="login" name="login" type="text" class="input @error('login') is-invalid @enderror"
                           value="{{ old('login') }}" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus>
                    @error('login')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" class="input @error('password') is-invalid @enderror"
                           autocomplete="current-password" required>
                    @error('password')<div class="error">{{ $message }}</div>@enderror
                </div>
                <label class="check"><input type="checkbox" name="remember" value="1" @checked(old('remember'))> Ingat saya di perangkat ini</label>
                <button type="submit" class="btn btn-primary btn-block">Masuk</button>
            </form>
        </div>
    </div>
    <p class="auth-foot">Belum punya password? Minta pemilik server menjalankan<br><code>php artisan absensi:admin-password &lt;username&gt;</code></p>
@endsection
