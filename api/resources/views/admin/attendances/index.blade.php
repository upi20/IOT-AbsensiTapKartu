@extends('admin.layouts.app')

@section('title', 'Kehadiran')

@section('content')
    <div class="page-head">
        <div>
            <h1>Kehadiran</h1>
            <p>Semua tap kartu pada tanggal yang dipilih. Data bisa dihapus, misalnya untuk mengulang tes.</p>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert error" role="alert"><p>{{ $errors->first() }}</p></div>
    @endif

    <section class="card">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.attendances.index') }}" class="toolbar">
                <div class="field">
                    <label for="tanggal">Tanggal</label>
                    <input id="tanggal" name="tanggal" type="date" class="input" value="{{ $date->toDateString() }}" required>
                </div>
                <div class="field grow">
                    <label for="q">Nama atau nomor kartu</label>
                    <input id="q" name="q" type="search" class="input" value="{{ $q }}" placeholder="Kosongkan untuk semua">
                </div>
                <button type="submit" class="btn btn-primary">Tampilkan</button>
            </form>
        </div>

        <div class="table-wrap">
            <table class="table table-stack">
                <thead>
                <tr><th>Waktu</th><th>Nama / kartu</th><th>Hasil</th><th>Alat</th><th class="actions"><span class="sr-only">Aksi</span></th></tr>
                </thead>
                <tbody>
                @forelse ($taps as $tap)
                    @php([$label, $tone] = \App\Services\AdminDashboard::tapLabel($tap))
                    <tr>
                        <td class="time" data-label="Waktu">
                            {{ $tap->tapped_at->format('H:i:s') }}
                            @if ($tap->wasQueued())<span class="sub">dari antrean</span>@endif
                        </td>
                        <td data-label="Nama / kartu">
                            @if ($tap->member)
                                <strong>{{ $tap->member->name }}</strong>
                            @endif
                            <span class="sub mono">{{ $tap->card_uid }}</span>
                        </td>
                        <td data-label="Hasil"><span class="badge {{ $tone }}">{{ $label }}</span></td>
                        <td data-label="Alat" class="muted">{{ $tap->device?->name ?? '—' }}</td>
                        <td class="actions">
                            <form method="POST" action="{{ route('admin.attendances.destroy', $tap) }}" class="inline"
                                  data-confirm="Hapus tap {{ $tap->member?->name ?? $tap->card_uid }} jam {{ $tap->tapped_at->format('H:i:s') }}?">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn btn-sm btn-danger"><x-icon name="trash"/> Hapus</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="empty"><strong>Tidak ada data</strong>Belum ada tap pada tanggal ini.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $taps->links() }}

        @if ($taps->total() > 0)
            <div class="card-body">
                <form method="POST" action="{{ route('admin.attendances.destroy-day') }}"
                      data-confirm="Hapus SEMUA {{ $taps->total() }} data kehadiran tanggal {{ $date->translatedFormat('d F Y') }}{{ $q !== '' ? ' yang cocok dengan “'.$q.'”' : '' }}? Tidak bisa dibatalkan.">
                    @csrf @method('DELETE')
                    <input type="hidden" name="tanggal" value="{{ $date->toDateString() }}">
                    <input type="hidden" name="q" value="{{ $q }}">
                    <button type="submit" class="btn btn-danger"><x-icon name="trash"/>
                        Hapus semua ({{ $taps->total() }}) {{ $q !== '' ? 'yang tampil' : 'tanggal ini' }}
                    </button>
                </form>
            </div>
        @endif
    </section>
@endsection
