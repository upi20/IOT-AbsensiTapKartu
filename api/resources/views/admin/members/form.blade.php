@extends('admin.layouts.app')

@php($editing = $member->exists)
@php($photoSupported = \App\Services\MemberPhoto::isSupported())
@section('title', $editing ? 'Ubah anggota' : 'Tambah anggota')

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $editing ? 'Ubah anggota' : 'Tambah anggota' }}</h1>
            <p><a href="{{ route('admin.members.index') }}">&larr; Kembali ke daftar anggota</a></p>
        </div>
    </div>

    @if (! $editing && request()->filled('kartu') && $member->card_uid)
        <div class="alert info"><p>Nomor kartu <strong class="mono">{{ $member->card_uid }}</strong> diambil dari daftar kartu belum terdaftar.</p></div>
    @endif

    <div class="grid-2">
        <section class="card">
            <form method="POST" action="{{ $editing ? route('admin.members.update', $member) : route('admin.members.store') }}"
                  class="card-body form" enctype="multipart/form-data" novalidate>
                @csrf
                @if ($editing) @method('PUT') @endif

                <div class="field">
                    <label for="name">Nama</label>
                    <input id="name" name="name" type="text" class="input @error('name') is-invalid @enderror"
                           value="{{ old('name', $member->name) }}" maxlength="60" required autofocus>
                    @error('name')<div class="error">{{ $message }}</div>@else<div class="help">2–60 karakter, tampil di layar alat saat tap.</div>@enderror
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="identifier">NIS/NIP <span class="opt">(opsional)</span></label>
                        <input id="identifier" name="identifier" type="text" class="input @error('identifier') is-invalid @enderror"
                               value="{{ old('identifier', $member->identifier) }}" maxlength="30" autocapitalize="none" spellcheck="false">
                        @error('identifier')<div class="error">{{ $message }}</div>@else<div class="help">Huruf, angka, titik, strip, atau garis miring.</div>@enderror
                    </div>
                    <div class="field">
                        <label for="card_uid">Nomor kartu</label>
                        <input id="card_uid" name="card_uid" type="text" class="input mono @error('card_uid') is-invalid @enderror"
                               value="{{ old('card_uid', $member->card_uid) }}" maxlength="40" inputmode="numeric" spellcheck="false" required>
                        @error('card_uid')<div class="error">{{ $message }}</div>@else<div class="help">10 digit, contoh <span class="mono">0218893066</span> (sama dengan pembaca RFID USB).</div>@enderror
                    </div>
                </div>

                <div class="field">
                    <label for="photo">Foto <span class="opt">(opsional)</span></label>
                    @if ($photoSupported)
                        @if ($member->photo_path)
                            <div class="photo-current">
                                <img class="photo-preview" src="{{ $member->photoUrl() }}" alt="Foto {{ $member->name }}">
                                <label class="check"><input type="checkbox" name="remove_photo" value="1"> Hapus foto</label>
                            </div>
                        @endif
                        <input id="photo" name="photo" type="file" class="input @error('photo') is-invalid @enderror" accept="image/jpeg,image/png">
                        @error('photo')<div class="error">{{ $message }}</div>@else<div class="help">JPG atau PNG. Otomatis diperkecil menjadi maks. 160×160 piksel (≤ 30 KB) untuk layar alat.</div>@enderror
                    @else
                        <div class="help">Unggah foto tidak tersedia: ekstensi PHP GD belum terpasang di server.</div>
                    @endif
                </div>

                <label class="check">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editing ? $member->is_active : true))>
                    Aktif (kartu bisa dipakai absen)
                </label>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ $editing ? 'Simpan perubahan' : 'Simpan anggota' }}</button>
                    <a href="{{ route('admin.members.index') }}" class="btn btn-ghost">Batal</a>
                </div>
            </form>
        </section>

        @if ($editing)
            <aside class="card">
                <div class="card-head"><h2>Info</h2></div>
                <div class="card-body">
                    <dl class="kv">
                        <dt>Terdaftar</dt><dd>{{ $member->created_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                        <dt>Diubah</dt><dd>{{ $member->updated_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </dl>
                    <hr class="divider">
                    <form method="POST" action="{{ route('admin.members.destroy', $member) }}"
                          data-confirm="Hapus anggota {{ $member->name }}? Riwayat absensinya tetap ada tetapi tidak lagi terhubung ke nama ini.">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-block"><x-icon name="trash"/> Hapus anggota</button>
                    </form>
                </div>
            </aside>
        @endif
    </div>
@endsection
