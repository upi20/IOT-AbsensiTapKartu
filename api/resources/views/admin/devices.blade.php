@extends('admin.layouts.app')

@section('title', 'Alat')

@section('content')
    <div class="page-head">
        <div>
            <h1>Alat</h1>
            <p>Alat muncul otomatis saat pertama kali menghubungi server. Online bila terlihat &lt; 3 menit lalu. Data kesehatan dari heartbeat terakhir (tiap ±1 menit).</p>
        </div>
    </div>

    @forelse ($devices as $device)
        @php($bag = \App\Http\Controllers\Admin\DeviceController::errorBag($device))
        @php($failed = $errors->hasBag($bag))
        @php($issues = $device->healthIssues())
        @php($update = $device->firmwareUpdateState())
        @php($tapModeReady = \App\Models\Device::tapModeReady())
        <section class="card device-card" id="alat-{{ $device->id }}">
            <div class="card-head">
                <div>
                    <h2>{{ $device->name }}</h2>
                    <p class="mono">{{ $device->code ?? 'API lama (v1)' }}</p>
                </div>
                <div class="pill-row">
                    @if ($issues)
                        <span class="badge warning">{{ count($issues) }} peringatan</span>
                    @endif
                    <span @class(['badge', 'success' => $device->isOnline(), 'muted' => ! $device->isOnline()])>
                        <span @class(['dot', 'on' => $device->isOnline()])></span> {{ $device->isOnline() ? 'Online' : 'Offline' }}
                    </span>
                </div>
            </div>

            @if ($issues)
                <div class="alert warning" role="status">
                    <ul class="issues">
                        @foreach ($issues as $issue)
                            <li>{{ $issue }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="card-body">
                <dl class="health">
                    <div>
                        <dt>Terakhir terlihat</dt>
                        <dd>
                            @if ($device->last_seen_at)
                                {{ $device->last_seen_at->format('d/m/Y H:i:s') }}<span class="sub">{{ $device->last_seen_at->diffForHumans() }}</span>
                            @else
                                <span class="muted">Belum pernah</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Firmware</dt>
                        <dd class="mono">{{ $device->firmware ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>IP</dt>
                        <dd class="mono">{{ $device->ip ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt>WiFi</dt>
                        <dd>{{ $device->wifi_ssid ?? '—' }}<span class="sub">{{ $device->rssi !== null ? $device->rssi.' dBm' : 'sinyal —' }}</span></dd>
                    </div>
                    <div>
                        <dt>Menyala selama</dt>
                        <dd>
                            {{ $device->uptimeForHumans() ?? '—' }}
                            @if ($device->heartbeat_at)
                                <span class="sub">per heartbeat {{ $device->heartbeat_at->format('H:i') }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Restart terakhir</dt>
                        <dd>
                            @if ($device->reset_reason)
                                {{ \App\Models\Device::resetReasonLabel($device->reset_reason) }}<span class="sub mono">{{ $device->reset_reason }}</span>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Pembaca RFID</dt>
                        <dd>
                            @if ($device->rfid_ok === null)
                                —
                            @elseif ($device->rfid_ok)
                                <span class="badge success">Terdeteksi</span>
                            @else
                                <span class="badge danger">Tidak terdeteksi</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Antrean offline</dt>
                        <dd>{{ $device->queue !== null ? $device->queue.' tap' : '—' }}</dd>
                    </div>
                    <div>
                        <dt>RAM bebas</dt>
                        <dd>
                            {{ $device->free_heap !== null ? \Illuminate\Support\Number::fileSize($device->free_heap, 1) : '—' }}
                            @if ($device->min_free_heap !== null)
                                <span class="sub">terendah {{ \Illuminate\Support\Number::fileSize($device->min_free_heap, 1) }}</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Error di layar</dt>
                        <dd>
                            @if ($device->error_code)
                                <span class="badge danger mono">{{ $device->error_code }}</span>
                                <span class="sub">{{ \App\Models\Device::ERROR_CODES[$device->error_code] ?? 'Kode tidak dikenal' }}</span>
                            @else
                                Tidak ada
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt>Update firmware</dt>
                        <dd>
                            @if ($update)
                                <span class="mono">{{ $device->firmwareRelease->version }}</span>
                                <span class="sub"><span class="badge {{ $update[1] }}">{{ $update[0] }}</span></span>
                            @else
                                Tidak ada
                            @endif
                        </dd>
                    </div>
                    @if ($tapModeReady && $device->code !== null)
                        <div>
                            <dt>Mode absen di alat</dt>
                            <dd>
                                @if ($device->reported_tap_mode === 'select')
                                    Pilih Datang/Pulang
                                    <span class="sub">Pilihan saat ini: {{ \App\Models\Device::TAP_SELECTS[$device->tap_select] ?? 'belum dipilih' }}</span>
                                @elseif ($device->reported_tap_mode === 'auto')
                                    Otomatis<span class="sub mono">/tap</span>
                                @else
                                    —<span class="sub">belum dilaporkan (firmware 1.6.0+)</span>
                                @endif
                            </dd>
                        </div>
                    @endif
                    <div>
                        <dt>Tap hari ini</dt>
                        <dd>{{ $device->taps_today }}</dd>
                    </div>
                </dl>

                <form method="POST" action="{{ route('admin.devices.update', $device) }}" class="toolbar device-form">
                    @csrf @method('PUT')
                    <div class="field grow">
                        <label for="name-{{ $device->id }}">Nama alat</label>
                        <input id="name-{{ $device->id }}" name="name" type="text" class="input input-sm @error('name', $bag) is-invalid @enderror"
                               value="{{ $failed ? old('name') : $device->name }}" maxlength="100" required>
                    </div>
                    <div class="field">
                        <label for="pin-{{ $device->id }}">PIN</label>
                        <input id="pin-{{ $device->id }}" name="pin" type="text" class="input input-sm mono @error('pin', $bag) is-invalid @enderror" value="{{ $failed ? old('pin') : $device->pin }}"
                               inputmode="numeric" pattern="[0-9]*" maxlength="8" placeholder="PIN" autocomplete="off" style="max-width:7rem;min-width:0">
                    </div>
                    <div class="field">
                        <label for="restart-{{ $device->id }}">Jam restart</label>
                        <input id="restart-{{ $device->id }}" name="restart_at" type="time" class="input input-sm mono @error('restart_at', $bag) is-invalid @enderror"
                               value="{{ $failed ? old('restart_at') : $device->restart_at }}" title="Jam restart harian (kosong = tidak restart otomatis)" style="max-width:8rem;min-width:0">
                    </div>
                    @if ($device->code !== null)
                        <div class="field">
                            <label for="firmware-{{ $device->id }}">Update firmware ke</label>
                            @php($target = (string) ($failed ? old('firmware_release_id') : $device->firmware_release_id))
                            <select id="firmware-{{ $device->id }}" name="firmware_release_id" class="input input-sm @error('firmware_release_id', $bag) is-invalid @enderror">
                                <option value="">Tidak ada</option>
                                @foreach ($releases as $release)
                                    <option value="{{ $release->id }}" @selected($target === (string) $release->id)>{{ $release->version }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    @if ($tapModeReady && $device->code !== null)
                        <div class="field">
                            <label for="tap-mode-{{ $device->id }}">Mode absen</label>
                            @php($tapMode = (string) ($failed ? old('tap_mode') : $device->tap_mode))
                            <select id="tap-mode-{{ $device->id }}" name="tap_mode" class="input input-sm @error('tap_mode', $bag) is-invalid @enderror">
                                <option value="">Ikuti pengaturan di alat</option>
                                @foreach (\App\Models\Device::TAP_MODES as $value => $label)
                                    <option value="{{ $value }}" @selected($tapMode === $value)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endif
                    <button type="submit" class="btn btn-sm">Simpan</button>
                </form>
                @error('name', $bag)<div class="error">{{ $message }}</div>@enderror
                @error('pin', $bag)<div class="error">{{ $message }}</div>@enderror
                @error('restart_at', $bag)<div class="error">{{ $message }}</div>@enderror
                @error('firmware_release_id', $bag)<div class="error">{{ $message }}</div>@enderror
                @error('tap_mode', $bag)<div class="error">{{ $message }}</div>@enderror
                <div class="small muted" style="margin-top:6px">
                    @if ($device->restart_at)
                        Restart harian pukul <span class="mono">{{ $device->restart_at }}</span>
                    @else
                        Tidak restart otomatis
                    @endif
                </div>

                <details class="events">
                    <summary>Riwayat kejadian <span class="muted">({{ $device->events->count() }} terakhir)</span></summary>
                    @if ($device->events->isEmpty())
                        <p class="small muted">Belum ada kejadian. Restart, crash, ganti firmware & update gagal dicatat di sini.</p>
                    @else
                        <ul class="event-list">
                            @foreach ($device->events as $event)
                                @php([$label, $tone] = $event->label())
                                <li>
                                    <span class="time">{{ $event->created_at->format('d/m H:i') }}</span>
                                    <span class="badge {{ $tone }}">{{ $label }}</span>
                                    <span>
                                        {{ $event->message }}
                                        @if ($event->type === 'crash' && ($event->details['backtrace'] ?? null))
                                            <span class="sub mono">Backtrace: {{ $event->details['backtrace'] }}</span>
                                        @endif
                                        @if ($event->type === 'crash' && ($event->details['elf'] ?? null))
                                            <span class="sub mono">ELF: {{ $event->details['elf'] }}</span>
                                        @endif
                                    </span>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </details>
            </div>
        </section>
    @empty
        <section class="card">
            <div class="empty"><strong>Belum ada alat</strong>Isi Base URL dan API key (lihat Pengaturan) di menu Pengaturan alat, lalu tekan "Tes koneksi".</div>
        </section>
    @endforelse

    <p class="small muted" style="margin-top:16px">PIN menu Pengaturan per alat (4–8 digit). Kosongkan agar alat tetap memakai PIN-nya sendiri (bawaan pabrik <span class="mono">2026</span>).
        Jam restart harian: alat restart sendiri sekali sehari pada jam itu (jam di layar alat) saat sedang tidak dipakai.
        Kosongkan = alat tidak restart otomatis. Bawaan <span class="mono">03:00</span>, sebaiknya jam sepi.
        Update firmware: unggah file di halaman <a href="{{ route('admin.firmware.index') }}">Firmware</a>, pilih versinya di sini; alat mengunduh, memasang & restart sendiri.
        Butuh firmware 1.5.0 ke atas.
        @if (\App\Models\Device::tapModeReady())
            Mode absen: <em>Otomatis</em> = semua tap ke satu endpoint <span class="mono">/tap</span>, server yang menentukan datang/pulang;
            <em>Pilih Datang/Pulang</em> = petugas memilih DATANG atau PULANG di layar alat (dipilih ulang tiap hari), tap dikirim ke
            <span class="mono">/check-in</span> atau <span class="mono">/check-out</span>. <em>Ikuti pengaturan di alat</em> = diatur dari menu alat.
            Butuh firmware 1.6.0 ke atas.
        @endif
    </p>
@endsection
