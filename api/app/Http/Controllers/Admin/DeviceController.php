<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DeviceController extends Controller
{
    public function index(): View
    {
        $devices = Device::query()
            ->withCount(['attendances as taps_today' => fn ($q) => $q->where('tapped_at', '>=', now()->startOfDay())])
            ->orderByDesc('last_seen_at')
            ->orderBy('id')
            ->get();

        return view('admin.devices', ['devices' => $devices]);
    }

    /**
     * Nama, PIN & jam restart harian satu alat. Galat validasi disimpan di bag "device-{id}" agar tampil
     * di baris alat itu. Jam restart kosong = alat tidak restart otomatis (disimpan null).
     */
    public function update(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validateWithBag(self::errorBag($device), [
            'name' => ['required', 'string', 'max:100'],
            'pin' => ['nullable', 'string', 'regex:/^[0-9]{4,8}$/'],
            'restart_at' => ['nullable', 'string', 'date_format:H:i'],
        ], [
            'pin.regex' => 'PIN harus 4 sampai 8 digit angka.',
            'restart_at.date_format' => 'Jam restart harus berformat JJ:MM (00:00–23:59).',
        ], ['name' => 'nama alat', 'pin' => 'PIN', 'restart_at' => 'jam restart']);

        $attributes = ['name' => trim($data['name']), 'pin' => $data['pin'] ?? null];

        // Hanya diubah kalau field-nya dikirim (form halaman Alat selalu mengirimnya, kosong pun).
        if (array_key_exists('restart_at', $data)) {
            $attributes['restart_at'] = $data['restart_at'];
        }

        $device->update($attributes);

        return redirect()->route('admin.devices.index')
            ->with('success', "Alat {$device->name} disimpan. PIN & jam restart diterapkan di alat pada heartbeat berikutnya (maks. 1 menit).");
    }

    /** Nama error bag untuk form alat ini (dipakai juga di view admin.devices). */
    public static function errorBag(Device $device): string
    {
        return 'device-'.$device->id;
    }
}
