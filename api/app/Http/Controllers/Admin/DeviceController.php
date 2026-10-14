<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\FirmwareRelease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(): View
    {
        $devices = Device::query()
            ->withCount(['attendances as taps_today' => fn ($q) => $q->where('tapped_at', '>=', now()->startOfDay())])
            ->with(['firmwareRelease', 'bootEventsLastDay', 'events' => fn ($q) => $q->latest('id')->limit(10)])
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->get();

        return view('admin.devices', [
            'devices' => $devices,
            'releases' => FirmwareRelease::query()->latest('id')->get(),
        ]);
    }

    /**
     * Nama, PIN, jam restart harian, mode absen & target update firmware satu alat. Galat validasi disimpan di bag
     * "device-{id}" agar tampil di baris alat itu. Jam restart kosong = alat tidak restart otomatis (disimpan null).
     * Target firmware kosong = tidak ada update. Mode absen kosong = ikuti pengaturan di alat (disimpan null); hanya
     * disimpan kalau migrasi mode absen sudah dijalankan. Jadwal mode pilih (jam DATANG & PULANG otomatis) diisi keduanya
     * dengan jam datang lebih awal, atau dikosongkan keduanya (tanpa jadwal); hanya disimpan kalau migrasi jadwal sudah dijalankan.
     */
    public function update(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validateWithBag(self::errorBag($device), [
            'name' => ['required', 'string', 'max:100'],
            'pin' => ['nullable', 'string', 'regex:/^[0-9]{4,8}$/'],
            'restart_at' => ['nullable', 'string', 'date_format:H:i'],
            'firmware_release_id' => ['nullable', 'integer', 'exists:firmware_releases,id'],
            'tap_mode' => ['nullable', 'string', Rule::in(array_keys(Device::TAP_MODES))],
            'schedule_check_in' => ['nullable', 'string', 'date_format:H:i', 'required_with:schedule_check_out'],
            'schedule_check_out' => ['bail', 'nullable', 'string', 'date_format:H:i', 'required_with:schedule_check_in',
                function (string $attribute, mixed $value, \Closure $fail) use ($request) {
                    $checkIn = $request->input('schedule_check_in');
                    // Dibandingkan hanya kalau jadwal DATANG sah (format JJ:MM, jadi urutan teks = urutan jam).
                    if (is_string($checkIn) && preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $checkIn) && $value <= $checkIn) {
                        $fail('Jadwal PULANG harus lebih lambat dari jadwal DATANG.');
                    }
                }],
        ], [
            'pin.regex' => 'PIN harus 4 sampai 8 digit angka.',
            'restart_at.date_format' => 'Jam restart harus berformat JJ:MM (00:00–23:59).',
            'tap_mode.in' => 'Mode absen tidak dikenal.',
            'schedule_check_in.date_format' => 'Jadwal DATANG harus berformat JJ:MM (00:00–23:59).',
            'schedule_check_out.date_format' => 'Jadwal PULANG harus berformat JJ:MM (00:00–23:59).',
            'schedule_check_in.required_with' => 'Jadwal DATANG & PULANG harus diisi keduanya, atau dikosongkan keduanya.',
            'schedule_check_out.required_with' => 'Jadwal DATANG & PULANG harus diisi keduanya, atau dikosongkan keduanya.',
        ], [
            'name' => 'nama alat', 'pin' => 'PIN', 'restart_at' => 'jam restart', 'firmware_release_id' => 'firmware', 'tap_mode' => 'mode absen',
            'schedule_check_in' => 'jadwal DATANG', 'schedule_check_out' => 'jadwal PULANG',
        ]);

        $attributes = ['name' => trim($data['name']), 'pin' => $data['pin'] ?? null];

        // Hanya diubah kalau field-nya dikirim (form halaman Alat selalu mengirimnya, kosong pun).
        $optional = Device::tapModeReady() ? ['restart_at', 'firmware_release_id', 'tap_mode'] : ['restart_at', 'firmware_release_id'];
        if (Device::scheduleReady()) {
            array_push($optional, 'schedule_check_in', 'schedule_check_out');
        }

        foreach ($optional as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }

        $device->update($attributes);

        return redirect()->route('admin.devices.index')
            ->with('success', "Alat {$device->name} disimpan. PIN, jam restart, mode absen, jadwal & update firmware diterapkan di alat pada heartbeat berikutnya (maks. 1 menit).");
    }

    /** Nama error bag untuk form alat ini (dipakai juga di view admin.devices). */
    public static function errorBag(Device $device): string
    {
        return 'device-'.$device->id;
    }
}
