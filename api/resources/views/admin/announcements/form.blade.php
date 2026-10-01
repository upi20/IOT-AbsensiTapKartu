@extends('admin.layouts.app')

@php($editing = $announcement->exists)
@section('title', $editing ? 'Ubah pengumuman' : 'Tambah pengumuman')

@section('content')
    <div class="page-head">
        <div>
            <h1>{{ $editing ? 'Ubah pengumuman' : 'Tambah pengumuman' }}</h1>
            <p><a href="{{ route('admin.announcements.index') }}">&larr; Kembali ke daftar pengumuman</a></p>
        </div>
    </div>

    <div class="grid-2">
        <section class="card">
            <form method="POST" action="{{ $editing ? route('admin.announcements.update', $announcement) : route('admin.announcements.store') }}"
                  class="card-body form" novalidate>
                @csrf
                @if ($editing) @method('PUT') @endif

                <div class="field">
                    <label for="title">Judul</label>
                    <input id="title" name="title" type="text" class="input @error('title') is-invalid @enderror"
                           value="{{ old('title', $announcement->title) }}" maxlength="{{ \App\Models\Announcement::TITLE_MAX }}" required autofocus>
                    @error('title')<div class="error">{{ $message }}</div>@else<div class="help">Maksimal {{ \App\Models\Announcement::TITLE_MAX }} karakter, mis. "Rapat Guru".</div>@enderror
                </div>

                <div class="field">
                    <label for="description">Deskripsi <span class="opt">(opsional)</span></label>
                    <textarea id="description" name="description" class="input @error('description') is-invalid @enderror" rows="3"
                              maxlength="{{ \App\Models\Announcement::DESCRIPTION_MAX }}" data-count="#description-count">{{ old('description', $announcement->description) }}</textarea>
                    @error('description')<div class="error">{{ $message }}</div>@else<div class="help">Maksimal {{ \App\Models\Announcement::DESCRIPTION_MAX }} karakter. <span id="description-count"></span></div>@enderror
                </div>

                <div class="form-row">
                    <div class="field">
                        <label for="icon">Ikon</label>
                        <select id="icon" name="icon" class="input @error('icon') is-invalid @enderror" required>
                            @foreach (\App\Models\Announcement::ICONS as $code => $label)
                                <option value="{{ $code }}" @selected(old('icon', $announcement->icon) === $code)>{{ $label }} ({{ $code }})</option>
                            @endforeach
                        </select>
                        @error('icon')<div class="error">{{ $message }}</div>@enderror
                    </div>
                    <div class="field">
                        <label for="sort_order">Urutan</label>
                        <input id="sort_order" name="sort_order" type="number" class="input @error('sort_order') is-invalid @enderror"
                               value="{{ old('sort_order', $announcement->sort_order) }}" min="0" max="{{ \App\Models\Announcement::SORT_ORDER_MAX }}" required>
                        @error('sort_order')<div class="error">{{ $message }}</div>@else<div class="help">Angka kecil tampil lebih dulu.</div>@enderror
                    </div>
                </div>

                <label class="check">
                    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $editing ? $announcement->is_active : true))>
                    Aktif (tampil di screensaver alat)
                </label>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">{{ $editing ? 'Simpan perubahan' : 'Simpan pengumuman' }}</button>
                    <a href="{{ route('admin.announcements.index') }}" class="btn btn-ghost">Batal</a>
                </div>
            </form>
        </section>

        @if ($editing)
            <aside class="card">
                <div class="card-head"><h2>Info</h2></div>
                <div class="card-body">
                    <dl class="kv">
                        <dt>Dibuat</dt><dd>{{ $announcement->created_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                        <dt>Diubah</dt><dd>{{ $announcement->updated_at?->format('d/m/Y H:i') ?? '—' }}</dd>
                    </dl>
                    <hr class="divider">
                    <form method="POST" action="{{ route('admin.announcements.destroy', $announcement) }}"
                          data-confirm="Hapus pengumuman &quot;{{ $announcement->title }}&quot;?">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-block"><x-icon name="trash"/> Hapus pengumuman</button>
                    </form>
                </div>
            </aside>
        @endif
    </div>
@endsection
