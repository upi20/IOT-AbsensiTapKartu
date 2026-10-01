<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\FirmwareRelease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
     * Nama, PIN, jam restart harian & target update firmware satu alat. Galat validasi disimpan di bag
     * "device-{id}" agar tampil di baris alat itu. Jam restart kosong = alat tidak restart otomatis (disimpan null).
     * Target firmware kosong = tidak ada update.
     */
    public function update(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validateWithBag(self::errorBag($device), [
            'name' => ['required', 'string', 'max:100'],
            'pin' => ['nullable', 'string', 'regex:/^[0-9]{4,8}$/'],
            'restart_at' => ['nullable', 'string', 'date_format:H:i'],
            'firmware_release_id' => ['nullable', 'integer', 'exists:firmware_releases,id'],
        ], [
            'pin.regex' => 'PIN harus 4 sampai 8 digit angka.',
            'restart_at.date_format' => 'Jam restart harus berformat JJ:MM (00:00–23:59).',
        ], ['name' => 'nama alat', 'pin' => 'PIN', 'restart_at' => 'jam restart', 'firmware_release_id' => 'firmware']);

        $attributes = ['name' => trim($data['name']), 'pin' => $data['pin'] ?? null];

        // Hanya diubah kalau field-nya dikirim (form halaman Alat selalu mengirimnya, kosong pun).
        foreach (['restart_at', 'firmware_release_id'] as $key) {
            if (array_key_exists($key, $data)) {
                $attributes[$key] = $data[$key];
            }
        }

        $device->update($attributes);

        return redirect()->route('admin.devices.index')
            ->with('success', "Alat {$device->name} disimpan. PIN, jam restart & update firmware diterapkan di alat pada heartbeat berikutnya (maks. 1 menit).");
    }

    /** Nama error bag untuk form alat ini (dipakai juga di view admin.devices). */
    public static function errorBag(Device $device): string
    {
        return 'device-'.$device->id;
    }
}
