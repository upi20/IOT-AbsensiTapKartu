@extends('admin.layouts.app')

@section('title', 'Kartu belum terdaftar')

@section('content')
    <div class="page-head">
        <div>
            <h1>Kartu belum terdaftar</h1>
            <p>Kartu yang ditempelkan di alat tetapi belum dimiliki anggota mana pun. Klik "Daftarkan" untuk membuat anggota dengan nomor kartu ini.</p>
        </div>
    </div>

    <section class="card">
        <div class="table-wrap">
            <table class="table table-stack">
                <thead>
                <tr><th>Nomor kartu</th><th>Tap terakhir</th><th>Alat</th><th class="num">Jumlah tap</th><th>Pertama kali</th><th class="actions"><span class="sr-only">Aksi</span></th></tr>
                </thead>
                <tbody>
                @forelse ($cards as $card)
                    <tr>
                        <td class="primary mono">{{ $card['uid'] }}</td>
                        <td data-label="Tap terakhir" class="time">{{ $card['last_tap']->format('d/m/Y H:i:s') }}<span class="sub">{{ $card['last_tap']->diffForHumans() }}</span></td>
                        <td data-label="Alat">{{ $card['device'] ?? '—' }}</td>
                        <td data-label="Jumlah tap" class="num">{{ $card['taps'] }}</td>
                        <td data-label="Pertama kali" class="time muted">{{ $card['first_tap']->format('d/m/Y H:i') }}</td>
                        <td class="actions">
                            <a href="{{ route('admin.members.create', ['kartu' => $card['uid']]) }}" class="btn btn-sm btn-primary"><x-icon name="plus"/> Daftarkan</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="empty"><strong>Tidak ada kartu baru</strong>Semua kartu yang pernah di-tap sudah terdaftar.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        @if ($cards->count() >= 100)
            <div class="card-foot small muted">Menampilkan 100 kartu terbaru.</div>
        @endif
    </section>
@endsection
