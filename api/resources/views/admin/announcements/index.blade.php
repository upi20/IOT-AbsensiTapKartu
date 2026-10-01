@extends('admin.layouts.app')

@section('title', 'Pengumuman')

@section('content')
    <div class="page-head">
        <div>
            <h1>Pengumuman</h1>
            <p>Tampil bergantian di layar alat (screensaver) saat alat tidak dipakai. Perubahan sampai ke alat dalam ±1 menit.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('admin.announcements.create') }}" class="btn btn-primary"><x-icon name="plus"/> Tambah pengumuman</a>
        </div>
    </div>

    <div class="grid-2">
        <section class="card">
            @if ($announcements->isNotEmpty())
                <form id="bulk-form" method="POST" action="{{ route('admin.announcements.bulk') }}" class="bulk-bar" data-bulk>
                    @csrf @method('PATCH')
                    <label class="check bulk-all"><input type="checkbox" data-select-all> Pilih semua</label>
                    <span class="small muted" data-bulk-count aria-live="polite">Centang pengumuman, lalu pilih aksi:</span>
                    <div class="bulk-actions">
                        <button type="submit" name="action" value="show" class="btn btn-sm">Tampilkan</button>
                        <button type="submit" name="action" value="hide" class="btn btn-sm">Sembunyikan</button>
                        <button type="submit" name="action" value="delete" class="btn btn-sm btn-danger"
                                data-confirm="Hapus pengumuman yang dipilih? Tidak bisa dibatalkan."
                                data-confirm-template="Hapus :count pengumuman yang dipilih? Tidak bisa dibatalkan."><x-icon name="trash"/> Hapus</button>
                    </div>
                    @if ($errors->bulk->any())
                        <div class="error" role="alert">{{ $errors->bulk->first() }}</div>
                    @endif
                </form>
            @endif
            <div class="table-wrap">
                <table class="table table-stack selectable">
                    <thead>
                    <tr><th class="select"><label class="check" title="Pilih semua"><input type="checkbox" form="bulk-form" data-select-all><span class="sr-only">Pilih semua</span></label></th><th class="num">Urutan</th><th>Pengumuman</th><th>Ikon</th><th>Status</th><th class="actions"><span class="sr-only">Aksi</span></th></tr>
                    </thead>
                    <tbody>
                    @php($activeCount = 0)
                    @forelse ($announcements as $announcement)
                        @php($overLimit = $announcement->is_active && ++$activeCount > \App\Models\Announcement::DEVICE_LIMIT)
                        <tr>
                            <td class="select">
                                <label class="check"><input type="checkbox" name="ids[]" value="{{ $announcement->id }}" form="bulk-form"
                                    @checked(in_array((string) $announcement->id, (array) old('ids', []), true))><span class="sr-only">Pilih {{ $announcement->title }}</span></label>
                            </td>
                            <td data-label="Urutan" class="num">{{ $announcement->sort_order }}</td>
                            <td class="primary">
                                <strong>{{ $announcement->title }}</strong>
                                @if ($announcement->description)
                                    <span class="sub">{{ \Illuminate\Support\Str::limit($announcement->description, 70) }}</span>
                                @endif
                            </td>
                            <td data-label="Ikon">
                                <span class="badge info">{{ $announcement->iconLabel() }}</span>
                                <span class="mono small muted">{{ $announcement->icon }}</span>
                            </td>
                            <td data-label="Status">
                                @if ($overLimit)
                                    <span class="badge warning" title="Alat hanya menampilkan {{ \App\Models\Announcement::DEVICE_LIMIT }} pengumuman aktif pertama">Aktif, tidak tampil</span>
                                @else
                                    <span class="badge {{ $announcement->is_active ? 'success' : 'muted' }}">{{ $announcement->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                                @endif
                            </td>
                            <td class="actions">
                                <a href="{{ route('admin.announcements.edit', $announcement) }}" class="btn btn-sm"><x-icon name="edit"/> Ubah</a>
                                <form method="POST" action="{{ route('admin.announcements.destroy', $announcement) }}" class="inline"
                                      data-confirm="Hapus pengumuman &quot;{{ $announcement->title }}&quot;?">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger" aria-label="Hapus {{ $announcement->title }}"><x-icon name="trash"/> Hapus</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty">
                            <strong>Belum ada pengumuman</strong>Selama daftar kosong, screensaver tidak tampil di alat.
                        </td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-foot small muted">Alat menampilkan maks. {{ \App\Models\Announcement::DEVICE_LIMIT }} pengumuman aktif, urut dari nomor urutan terkecil.</div>
        </section>

        <aside class="card">
            <div class="card-head">
                <div>
                    <h2>Screensaver</h2>
                    <p>Berlaku untuk semua alat.</p>
                </div>
            </div>
            <form method="POST" action="{{ route('admin.announcements.screensaver') }}" class="card-body form" novalidate>
                @csrf @method('PUT')
                <div class="field">
                    <label for="interval">Lama tiap pengumuman (detik)</label>
                    <input id="interval" name="interval" type="number" class="input @error('interval', 'screensaver') is-invalid @enderror"
                           value="{{ old('interval', $interval) }}" min="{{ \App\Models\Setting::SCREENSAVER_INTERVAL_MIN }}" max="{{ \App\Models\Setting::SCREENSAVER_INTERVAL_MAX }}" required>
                    @error('interval', 'screensaver')<div class="error">{{ $message }}</div>@else<div class="help">2–60 detik, bawaan 3.</div>@enderror
                </div>
                <div class="field">
                    <label for="idle">Screensaver muncul setelah diam (detik)</label>
                    <input id="idle" name="idle" type="number" class="input @error('idle', 'screensaver') is-invalid @enderror"
                           value="{{ old('idle', $idle) }}" min="{{ \App\Models\Setting::SCREENSAVER_IDLE_MIN }}" max="{{ \App\Models\Setting::SCREENSAVER_IDLE_MAX }}" required>
                    @error('idle', 'screensaver')<div class="error">{{ $message }}</div>@else<div class="help">5–600 detik, bawaan 30.</div>@enderror
                </div>
                <p class="help">Kartu tetap bisa di-tap selama screensaver. Sentuh layar untuk kembali ke layar utama.</p>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Simpan</button>
                </div>
            </form>
        </aside>
    </div>
@endsection
