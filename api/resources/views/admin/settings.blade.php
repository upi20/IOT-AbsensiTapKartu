@extends('admin.layouts.app')

@section('title', 'Pengaturan')

@section('content')
    <div class="page-head">
        <div>
            <h1>Pengaturan</h1>
            <p>Koneksi alat, pengaturan jarak jauh, dan akun Anda.</p>
        </div>
    </div>

    <div class="grid-halves">
        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Koneksi alat</h2>
                    <p>Isi dua nilai ini di menu Pengaturan setiap alat.</p>
                </div>
            </div>
            <div class="card-body form">
                <div class="field">
                    <span class="label">Base URL</span>
                    <div class="key-row">
                        <code id="base-url">{{ $baseUrl }}</code>
                        <button type="button" class="btn" data-copy="#base-url"><x-icon name="copy"/> Salin</button>
                    </div>
                </div>
                <div class="field">
                    <span class="label">API key</span>
                    <div class="key-row">
                        <code id="api-key">{{ $apiKey }}</code>
                        <button type="button" class="btn" data-copy="#api-key"><x-icon name="copy"/> Salin</button>
                    </div>
                </div>
            </div>
            <div class="card-foot">
                <form method="POST" action="{{ route('admin.settings.api-key') }}"
                      data-confirm="Buat API key baru? Semua alat langsung tidak bisa terhubung sampai key baru dimasukkan di masing-masing alat.">
                    @csrf
                    <button type="submit" class="btn btn-sm btn-danger"><x-icon name="key"/> Buat API key baru</button>
                </form>
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Pengaturan jarak jauh</h2>
                    <p>Dikirim ke semua alat lewat heartbeat (maks. 1 menit).</p>
                </div>
            </div>
            <form method="POST" action="{{ route('admin.settings.title') }}" class="card-body form" novalidate>
                @csrf @method('PUT')
                <div class="field">
                    <label for="title">Judul di layar alat</label>
                    <input id="title" name="title" type="text" class="input @error('title', 'title') is-invalid @enderror"
                           value="{{ old('title', $title) }}" maxlength="30" required>
                    @error('title', 'title')<div class="error">{{ $message }}</div>@else<div class="help">Maksimal 30 karakter, mis. nama sekolah atau kantor.</div>@enderror
                </div>
                <p class="help">PIN menu Pengaturan diatur per alat di halaman <a href="{{ route('admin.devices.index') }}">Alat</a>.</p>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Layar alat</h2>
                    <p>Lampu layar meredup saat alat diam. Berlaku untuk semua alat.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('admin.settings.screen') }}" class="card-body form" novalidate>
                @csrf @method('PUT')
                <div class="field">
                    <label for="dim_after">Redup setelah diam (detik)</label>
                    <input id="dim_after" name="dim_after" type="number" class="input @error('dim_after', 'screen') is-invalid @enderror"
                           value="{{ old('dim_after', $dimAfter) }}" min="0" max="{{ \App\Models\Setting::DIM_AFTER_MAX }}" required>
                    @error('dim_after', 'screen')<div class="error">{{ $message }}</div>@else<div class="help">0 = tidak pernah redup, atau 10–3600 detik. Bawaan 60.</div>@enderror
                </div>
                <div class="field">
                    <label for="dim_level">Kecerahan saat redup (%)</label>
                    <input id="dim_level" name="dim_level" type="number" class="input @error('dim_level', 'screen') is-invalid @enderror"
                           value="{{ old('dim_level', $dimLevel) }}" min="0" max="{{ \App\Models\Setting::DIM_LEVEL_MAX }}" required>
                    @error('dim_level', 'screen')<div class="error">{{ $message }}</div>@else<div class="help">0 = mati, 100 = terang penuh. Bawaan 20.</div>@enderror
                </div>
                <p class="help">Saat redup, kartu tetap diproses dan layar langsung terang. Sentuhan pertama hanya menyalakan layar. Butuh firmware 1.3.0 ke atas.</p>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Ganti password</h2>
                    <p>Masuk sebagai <strong>{{ auth()->user()->username }}</strong> ({{ auth()->user()->email }})</p>
                </div>
            </div>
            <form method="POST" action="{{ route('admin.settings.password') }}" class="card-body form" novalidate>
                @csrf @method('PUT')
                <div class="field">
                    <label for="current_password">Password saat ini</label>
                    <input id="current_password" name="current_password" type="password" class="input @error('current_password', 'password') is-invalid @enderror" autocomplete="current-password" required>
                    @error('current_password', 'password')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="field">
                    <label for="password">Password baru</label>
                    <input id="password" name="password" type="password" class="input @error('password', 'password') is-invalid @enderror" autocomplete="new-password" minlength="8" required>
                    @error('password', 'password')<div class="error">{{ $message }}</div>@else<div class="help">Minimal 8 karakter.</div>@enderror
                </div>
                <div class="field">
                    <label for="password_confirmation">Ulangi password baru</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" class="input" autocomplete="new-password" required>
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Simpan password</button>
                </div>
            </form>
        </section>
    </div>
@endsection
