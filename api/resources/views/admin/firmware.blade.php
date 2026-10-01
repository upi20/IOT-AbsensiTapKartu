@extends('admin.layouts.app')

@section('title', 'Firmware')

@section('content')
    <div class="page-head">
        <div>
            <h1>Firmware</h1>
            <p>Update firmware alat jarak jauh (OTA) lewat WiFi. Unggah file .bin di sini, lalu pilih alat yang diupdate di halaman <a href="{{ route('admin.devices.index') }}">Alat</a>.</p>
        </div>
    </div>

    <div class="grid-2">
        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Daftar firmware</h2>
                    <p>Alat mengunduh firmware yang dijadwalkan pada heartbeat berikutnya (maks. 1 menit).</p>
                </div>
            </div>
            <div class="table-wrap">
                <table class="table table-stack">
                    <thead>
                    <tr><th>Versi</th><th class="num">Ukuran</th><th>MD5</th><th>Diunggah</th><th class="num">Alat</th><th class="actions"><span class="sr-only">Aksi</span></th></tr>
                    </thead>
                    <tbody>
                    @forelse ($releases as $release)
                        <tr>
                            <td class="primary">
                                <span class="mono">{{ $release->version }}</span>
                                @if ($release->notes)
                                    <span class="sub">{{ $release->notes }}</span>
                                @endif
                            </td>
                            <td data-label="Ukuran" class="num nowrap">{{ \Illuminate\Support\Number::fileSize($release->size, 1) }}</td>
                            <td data-label="MD5" class="mono small" title="{{ $release->md5 }}">{{ \Illuminate\Support\Str::limit($release->md5, 12, '…') }}</td>
                            <td data-label="Diunggah" class="time">{{ $release->created_at->format('d/m/Y H:i') }}</td>
                            <td data-label="Alat" class="num" title="Alat yang dijadwalkan update ke versi ini">{{ $release->devices_count }}</td>
                            <td class="actions">
                                <form method="POST" action="{{ route('admin.firmware.apply-all', $release) }}" class="inline"
                                      data-confirm="Jadwalkan firmware {{ $release->version }} untuk SEMUA alat? Setiap alat mengunduh & restart sendiri dalam ±1 menit.">
                                    @csrf
                                    <button type="submit" class="btn btn-sm">Terapkan ke semua alat</button>
                                </form>
                                <form method="POST" action="{{ route('admin.firmware.destroy', $release) }}" class="inline"
                                      data-confirm="Hapus firmware {{ $release->version }}? Alat yang dijadwalkan ke versi ini batal diupdate.">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn-sm btn-danger" title="Hapus"><x-icon name="trash"/><span class="sr-only">Hapus</span></button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty"><strong>Belum ada firmware</strong>Unggah file .bin hasil kompilasi lewat form di samping.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-foot small muted">
                Alat memeriksa ukuran & MD5 file sebelum memasangnya, lalu restart. Kalau firmware baru gagal menyala, alat kembali
                ke versi lama dan halaman Alat menampilkan "Gagal". Perbaiki firmware lalu unggah dengan nomor versi baru.
            </div>
        </section>

        <section class="card">
            <div class="card-head">
                <div>
                    <h2>Unggah firmware</h2>
                    <p>File .bin hasil kompilasi (lihat README bagian Update firmware jarak jauh).</p>
                </div>
            </div>
            <form method="POST" action="{{ route('admin.firmware.store') }}" class="card-body form" enctype="multipart/form-data" novalidate>
                @csrf
                <div class="field">
                    <label for="version">Versi</label>
                    <input id="version" name="version" type="text" class="input mono @error('version', 'firmware') is-invalid @enderror"
                           value="{{ old('version') }}" maxlength="32" placeholder="1.5.0" required>
                    @error('version', 'firmware')<div class="error">{{ $message }}</div>@else<div class="help">Sama dengan VERSI_FIRMWARE di config.h, mis. 1.5.0.</div>@enderror
                </div>
                <div class="field">
                    <label for="firmware">File .bin</label>
                    <input id="firmware" name="firmware" type="file" class="input @error('firmware', 'firmware') is-invalid @enderror" accept=".bin" required>
                    @error('firmware', 'firmware')<div class="error">{{ $message }}</div>@else<div class="help">Maksimal 1.875 MB. Buat dengan <code>cd firmware && ./upload.sh -c absensi</code>.</div>@enderror
                </div>
                <div class="field">
                    <label for="notes">Catatan <span class="opt">(opsional)</span></label>
                    <textarea id="notes" name="notes" class="input @error('notes', 'firmware') is-invalid @enderror" maxlength="1000" placeholder="Perubahan di versi ini">{{ old('notes') }}</textarea>
                    @error('notes', 'firmware')<div class="error">{{ $message }}</div>@enderror
                </div>
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><x-icon name="upload"/> Unggah</button>
                </div>
            </form>
        </section>
    </div>
@endsection
