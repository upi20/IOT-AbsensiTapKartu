@extends('admin.layouts.app')

@section('title', 'Anggota')

@section('content')
    <div class="page-head">
        <div>
            <h1>Anggota</h1>
            <p>{{ $total }} anggota terdaftar</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('admin.members.create') }}" class="btn btn-primary"><x-icon name="plus"/> Tambah anggota</a>
        </div>
    </div>

    <section class="card">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.members.index') }}" class="toolbar" role="search">
                <div class="grow search">
                    <label for="q" class="sr-only">Cari</label>
                    <x-icon name="search"/>
                    <input id="q" name="q" type="search" class="input" value="{{ $q }}" placeholder="Cari nama, NIS/NIP, atau nomor kartu">
                </div>
                <button type="submit" class="btn">Cari</button>
                @if ($q !== '')
                    <a href="{{ route('admin.members.index') }}" class="btn btn-ghost">Reset</a>
                @endif
            </form>
        </div>

        <div class="table-wrap">
            <table class="table table-stack">
                <thead>
                <tr><th>Nama</th><th>NIS/NIP</th><th>Nomor kartu</th><th>Status</th><th class="actions"><span class="sr-only">Aksi</span></th></tr>
                </thead>
                <tbody>
                @forelse ($members as $m)
                    <tr>
                        <td class="primary strong">
                            <span class="person">
                                @if ($m->photo_path)
                                    <img class="photo-thumb" src="{{ $m->photoUrl() }}" alt="" loading="lazy">
                                @else
                                    <span class="avatar">{{ mb_substr($m->name, 0, 1) }}</span>
                                @endif
                                {{ $m->name }}
                            </span>
                        </td>
                        <td data-label="NIS/NIP">{{ $m->identifier ?? '—' }}</td>
                        <td data-label="Nomor kartu" class="mono">{{ $m->card_uid }}</td>
                        <td data-label="Status">
                            <span class="badge {{ $m->is_active ? 'success' : 'muted' }}">{{ $m->is_active ? 'Aktif' : 'Nonaktif' }}</span>
                        </td>
                        <td class="actions">
                            <a href="{{ route('admin.members.edit', $m) }}" class="btn btn-sm"><x-icon name="edit"/> Ubah</a>
                            <form method="POST" action="{{ route('admin.members.destroy', $m) }}" class="inline"
                                  data-confirm="Hapus anggota {{ $m->name }}? Riwayat absensinya tetap ada tetapi tidak lagi terhubung ke nama ini.">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger" aria-label="Hapus {{ $m->name }}"><x-icon name="trash"/> Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty">
                        @if ($q !== '')
                            <strong>Tidak ada hasil</strong>Coba kata kunci lain.
                        @else
                            <strong>Belum ada anggota</strong>Tambahkan anggota, atau tap kartu di alat lalu buka "Kartu belum terdaftar".
                        @endif
                    </td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $members->links() }}
    </section>
@endsection
