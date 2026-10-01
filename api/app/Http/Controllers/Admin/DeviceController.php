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

    /** Nama & PIN satu alat. Galat validasi disimpan di bag "device-{id}" agar tampil di baris alat itu. */
    public function update(Request $request, Device $device): RedirectResponse
    {
        $data = $request->validateWithBag(self::errorBag($device), [
            'name' => ['required', 'string', 'max:100'],
            'pin' => ['nullable', 'string', 'regex:/^[0-9]{4,8}$/'],
        ], [
            'pin.regex' => 'PIN harus 4 sampai 8 digit angka.',
        ], ['name' => 'nama alat', 'pin' => 'PIN']);

        $device->update(['name' => trim($data['name']), 'pin' => $data['pin'] ?? null]);

        return redirect()->route('admin.devices.index')
            ->with('success', "Alat {$device->name} disimpan. PIN diterapkan di alat pada heartbeat berikutnya (maks. 1 menit).");
    }

    /** Nama error bag untuk form alat ini (dipakai juga di view admin.devices). */
    public static function errorBag(Device $device): string
    {
        return 'device-'.$device->id;
    }
}
