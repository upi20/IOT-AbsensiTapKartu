@extends('admin.layouts.app')

@section('title', 'Rekap')

@section('content')
    @php($query = ['dari' => $from->toDateString(), 'sampai' => $to->toDateString()] + ($presentOnly ? ['hadir_saja' => 1] : []))
    <div class="page-head">
        <div>
            <h1>Rekap</h1>
            <p>Jam masuk pertama dan jam pulang terakhir per anggota per hari.</p>
        </div>
        <div class="page-actions">
            <a href="{{ route('admin.reports.export', $query) }}" class="btn"><x-icon name="download"/> Ekspor CSV</a>
        </div>
    </div>

    @if ($errors->any())
        <div class="alert error" role="alert"><p>{{ $errors->first() }}</p></div>
    @endif

    <section class="card">
        <div class="card-body">
            <form method="GET" action="{{ route('admin.reports.index') }}" class="toolbar">
                <div class="field">
                    <label for="dari">Dari tanggal</label>
                    <input id="dari" name="dari" type="date" class="input" value="{{ $from->toDateString() }}" required>
                </div>
                <div class="field">
                    <label for="sampai">Sampai tanggal</label>
                    <input id="sampai" name="sampai" type="date" class="input" value="{{ $to->toDateString() }}" required>
                </div>
                <div class="field" style="align-self:center; padding-top:22px">
                    <label class="check"><input type="checkbox" name="hadir_saja" value="1" @checked($presentOnly)> Hanya yang hadir</label>
                </div>
                <button type="submit" class="btn btn-primary">Tampilkan</button>
            </form>
        </div>
        <div class="card-body" style="padding-top:0">
            <div class="pill-row">
                <span class="badge success">Hadir: {{ $summary['present'] }}</span>
                <span class="badge warning">Tanpa tap pulang: {{ $summary['no_checkout'] }}</span>
                @unless ($presentOnly)<span class="badge muted">Tidak hadir: {{ $summary['absent'] }}</span>@endunless
                <span class="badge">{{ $from->translatedFormat('d M Y') }}@if (! $from->isSameDay($to)) – {{ $to->translatedFormat('d M Y') }}@endif</span>
            </div>
        </div>

        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr><th>Tanggal</th><th>Nama</th><th>NIS/NIP</th><th>Masuk</th><th>Pulang</th><th>Keterangan</th></tr>
                </thead>
                <tbody>
                @forelse ($rows as $row)
                    @php($d = \Illuminate\Support\Carbon::parse($row['date']))
                    <tr>
                        <td class="time">{{ $d->format('d/m/Y') }}<span class="sub">{{ $d->translatedFormat('l') }}</span></td>
                        <td class="strong">{{ $row['name'] }}</td>
                        <td>{{ $row['identifier'] ?? '—' }}</td>
                        <td class="time">{{ $row['check_in'] ?? '—' }}</td>
                        <td class="time">{{ $row['check_out'] ?? '—' }}</td>
                        <td>
                            <span class="badge {{ ['Hadir' => 'success', 'Tanpa tap pulang' => 'warning'][$row['note']] ?? 'muted' }}">{{ $row['note'] }}</span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty"><strong>Tidak ada data</strong>Belum ada absensi pada rentang tanggal ini.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $rows->links() }}
    </section>
@endsection
