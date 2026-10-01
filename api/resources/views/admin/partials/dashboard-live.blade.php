{{-- Bagian dasbor yang diperbarui tiap 10 detik (DashboardController@live). --}}
@php($c = $counts)
@if ($attention->isNotEmpty())
    <div class="alert warning" role="status">
        <p>
            <strong>{{ $attention->count() }} alat perlu diperiksa:</strong>
            @foreach ($attention as $deviceId => $issues)
                <a href="{{ route('admin.devices.index') }}#alat-{{ $deviceId }}" title="{{ implode(' · ', $issues) }}">{{ $devices->firstWhere('id', $deviceId)->name }}</a>
                <span class="small">({{ $issues[0] }}{{ count($issues) > 1 ? ', +'.(count($issues) - 1).' lainnya' : '' }})</span>@if (! $loop->last), @endif
            @endforeach
        </p>
    </div>
@endif
<section class="stats" aria-label="Ringkasan hari ini">
    <div class="card stat tone-success">
        <div class="stat-label">Masuk</div>
        <div class="stat-value">{{ $c['checked_in'] }}</div>
        <div class="stat-note">dari {{ $c['active'] }} anggota aktif</div>
    </div>
    <div class="card stat tone-info">
        <div class="stat-label">Pulang</div>
        <div class="stat-value">{{ $c['checked_out'] }}</div>
        <div class="stat-note">sudah tap pulang</div>
    </div>
    <div class="card stat tone-warning">
        <div class="stat-label">Belum hadir</div>
        <div class="stat-value">{{ $c['not_yet'] }}</div>
        <div class="stat-note">anggota aktif belum tap</div>
    </div>
    <div class="card stat tone-accent">
        <div class="stat-label">Alat aktif</div>
        <div class="stat-value">{{ $devices->filter->isOnline()->count() }}</div>
        <div class="stat-note">dari {{ $devices->count() }} alat</div>
    </div>
</section>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <div>
                <h2>Tap terakhir</h2>
                <p>20 tap terbaru dari semua alat · {{ now()->format('H:i:s') }}</p>
            </div>
        </div>
        <div class="table-wrap">
            <table class="table table-stack">
                <thead>
                <tr><th>Waktu</th><th>Nama</th><th>Hasil</th><th>Alat</th></tr>
                </thead>
                <tbody>
                @forelse ($taps as $tap)
                    @php([$label, $tone] = \App\Services\AdminDashboard::tapLabel($tap))
                    <tr>
                        <td class="time" data-label="Waktu">
                            {{ $tap->tapped_at->format('H:i:s') }}
                            <span class="sub">{{ $tap->tapped_at->isToday() ? 'Hari ini' : $tap->tapped_at->translatedFormat('d M') }}@if ($tap->wasQueued()) · antrean @endif</span>
                        </td>
                        <td data-label="Nama">
                            @if ($tap->member)
                                <strong>{{ $tap->member->name }}</strong>
                            @else
                                <span class="mono">{{ $tap->card_uid }}</span>
                                @if ($tap->member_id === null && $tap->status === \App\Enums\AttendanceStatus::UnknownCard)
                                    <a class="sub" href="{{ route('admin.members.create', ['kartu' => $tap->card_uid]) }}">Daftarkan</a>
                                @endif
                            @endif
                        </td>
                        <td data-label="Hasil"><span class="badge {{ $tone }}">{{ $label }}</span></td>
                        <td data-label="Alat" class="muted">{{ $tap->device?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="empty"><strong>Belum ada tap</strong>Tap kartu di alat akan muncul di sini.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <section class="card">
        <div class="card-head">
            <div>
                <h2>Alat</h2>
                <p>Aktif bila terlihat &lt; 3 menit lalu</p>
            </div>
        </div>
        <ul class="device-list">
            @forelse ($devices as $device)
                <li>
                    <span @class(['dot', 'on' => $device->isOnline()])></span>
                    <div>
                        <div class="name">{{ $device->name }}</div>
                        <div class="meta">
                            {{ $device->isOnline() ? 'Aktif' : 'Tidak aktif' }} ·
                            {{ $device->last_seen_at?->diffForHumans() ?? 'belum pernah terhubung' }}
                        </div>
                    </div>
                </li>
            @empty
                <li class="muted">Belum ada alat. Alat muncul otomatis saat pertama kali terhubung.</li>
            @endforelse
        </ul>
    </section>
</div>
