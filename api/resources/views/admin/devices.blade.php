@extends('admin.layouts.app')

@section('title', 'Alat')

@section('content')
    <div class="page-head">
        <div>
            <h1>Alat</h1>
            <p>Alat muncul otomatis saat pertama kali menghubungi server. Aktif bila terlihat &lt; 3 menit lalu.</p>
        </div>
    </div>

    <section class="card">
        <div class="table-wrap">
            <table class="table table-stack">
                <thead>
                <tr><th>ID alat</th><th>Nama &amp; PIN</th><th>Status</th><th>Terakhir terlihat</th><th>Firmware</th><th>IP</th><th class="num">RSSI</th><th>WiFi</th><th class="num">Tap hari ini</th></tr>
                </thead>
                <tbody>
                @forelse ($devices as $device)
                    @php($bag = \App\Http\Controllers\Admin\DeviceController::errorBag($device))
                    @php($failed = $errors->hasBag($bag))
                    <tr>
                        <td class="primary mono">{{ $device->code ?? 'API lama (v1)' }}</td>
                        <td data-label="Nama">
                            <form method="POST" action="{{ route('admin.devices.update', $device) }}" class="inline-edit">
                                @csrf @method('PUT')
                                <label for="name-{{ $device->id }}" class="sr-only">Nama alat</label>
                                <input id="name-{{ $device->id }}" name="name" type="text" class="input input-sm @error('name', $bag) is-invalid @enderror"
                                       value="{{ $failed ? old('name') : $device->name }}" maxlength="100" required>
                                <label for="pin-{{ $device->id }}" class="sr-only">PIN alat</label>
                                <input id="pin-{{ $device->id }}" name="pin" type="text" class="input input-sm mono @error('pin', $bag) is-invalid @enderror" value="{{ $failed ? old('pin') : $device->pin }}"
                                       inputmode="numeric" pattern="[0-9]*" maxlength="8" placeholder="PIN" autocomplete="off" style="max-width:7rem">
                                <button type="submit" class="btn btn-sm">Simpan</button>
                            </form>
                            @error('name', $bag)<div class="error">{{ $message }}</div>@enderror
                            @error('pin', $bag)<div class="error">{{ $message }}</div>@enderror
                        </td>
                        <td data-label="Status">
                            <span class="live"><span @class(['dot', 'on' => $device->isOnline()])></span> {{ $device->isOnline() ? 'Aktif' : 'Tidak aktif' }}</span>
                        </td>
                        <td data-label="Terakhir terlihat" class="time">
                            @if ($device->last_seen_at)
                                {{ $device->last_seen_at->format('d/m/Y H:i:s') }}<span class="sub">{{ $device->last_seen_at->diffForHumans() }}</span>
                            @else
                                <span class="muted">Belum pernah</span>
                            @endif
                        </td>
                        <td data-label="Firmware" class="mono">{{ $device->firmware ?? '—' }}</td>
                        <td data-label="IP" class="mono">{{ $device->ip ?? '—' }}</td>
                        <td data-label="RSSI" class="num">{{ $device->rssi !== null ? $device->rssi.' dBm' : '—' }}</td>
                        <td data-label="WiFi">{{ $device->wifi_ssid ?? '—' }}</td>
                        <td data-label="Tap hari ini" class="num">{{ $device->taps_today }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="empty"><strong>Belum ada alat</strong>Isi Base URL dan API key (lihat Pengaturan) di menu Pengaturan alat, lalu tekan "Tes koneksi".</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="card-foot small muted">PIN menu Pengaturan per alat (4–8 digit). Kosongkan agar alat tetap memakai PIN-nya sendiri (bawaan pabrik <span class="mono">2026</span>).</div>
    </section>
@endsection
