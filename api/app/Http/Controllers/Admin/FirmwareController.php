<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\FirmwareRelease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Firmware untuk update jarak jauh (OTA). File .bin diunggah di sini, lalu dijadwalkan per alat (halaman Alat)
 * atau untuk semua alat sekaligus. Alat mengunduhnya setelah menerima config.firmware_update di heartbeat.
 */
class FirmwareController extends Controller
{
    public function index(): View
    {
        return view('admin.firmware', [
            'releases' => FirmwareRelease::query()->withCount('devices')->latest('id')->get(),
        ]);
    }

    /**
     * Unggah firmware. Selain ukuran (maks. slot OTA), file diperiksa: byte pertama harus 0xE9 (image aplikasi
     * ESP32) dan teks versinya harus ada di dalam file, supaya alat tidak terus mengunduh ulang karena versi
     * yang dilaporkan setelah update berbeda dengan yang dijadwalkan.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('firmware', [
            'version' => ['required', 'string', 'max:32', 'regex:/^\d+\.\d+\.\d+$/', 'unique:firmware_releases,version'],
            'firmware' => ['required', 'file', 'extensions:bin', 'max:'.(FirmwareRelease::MAX_BYTES / 1024)],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'version.regex' => 'Versi harus berformat angka.angka.angka, mis. 1.5.0.',
            'version.unique' => 'Versi :input sudah pernah diunggah. Naikkan nomor versi firmware.',
            'firmware.extensions' => 'File harus berekstensi .bin.',
            'firmware.max' => 'File terlalu besar: maksimal 1.875 MB (1966080 byte, ukuran slot OTA).',
        ], ['version' => 'versi', 'firmware' => 'file firmware', 'notes' => 'catatan']);

        $file = $data['firmware'];
        $bytes = (string) file_get_contents($file->getRealPath());

        if ($bytes === '' || ord($bytes[0]) !== FirmwareRelease::IMAGE_MAGIC) {
            throw ValidationException::withMessages([
                'firmware' => 'File bukan firmware ESP32 (byte pertama harus 0xE9). Pilih file .bin aplikasi, bukan bootloader/partisi/merged.',
            ])->errorBag('firmware');
        }

        if (! str_contains($bytes, $data['version'])) {
            throw ValidationException::withMessages([
                'firmware' => "Versi {$data['version']} tidak ditemukan di dalam file. Pastikan VERSI_FIRMWARE di config.h sama.",
            ])->errorBag('firmware');
        }

        $path = Storage::disk(FirmwareRelease::DISK)->putFileAs('firmware', $file, $data['version'].'.bin');

        FirmwareRelease::create([
            'version' => $data['version'],
            'path' => $path,
            'size' => strlen($bytes),
            'md5' => md5($bytes),
            'notes' => $data['notes'] ?? null,
        ]);

        return redirect()->route('admin.firmware.index')
            ->with('success', "Firmware {$data['version']} diunggah. Pilih alat yang akan diupdate di halaman Alat, atau terapkan ke semua alat.");
    }

    /** Jadwalkan firmware ini untuk semua alat API standar. */
    public function applyAll(FirmwareRelease $release): RedirectResponse
    {
        $count = Device::query()->whereNotNull('code')->update(['firmware_release_id' => $release->id]);

        return redirect()->route('admin.firmware.index')
            ->with('success', "Firmware {$release->version} dijadwalkan untuk {$count} alat. Alat mengunduhnya pada heartbeat berikutnya (maks. 1 menit).");
    }

    /** Hapus firmware: alat yang menargetkannya otomatis tidak dijadwalkan update lagi (FK null on delete). */
    public function destroy(FirmwareRelease $release): RedirectResponse
    {
        Storage::disk(FirmwareRelease::DISK)->delete($release->path);
        $release->delete();

        return redirect()->route('admin.firmware.index')
            ->with('success', "Firmware {$release->version} dihapus.");
    }
}
