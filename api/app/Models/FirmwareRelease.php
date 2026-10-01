<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * File firmware (.bin) untuk update jarak jauh (OTA), diunggah di halaman Firmware.
 * File disimpan di disk "local" (storage/app/private/firmware/<versi>.bin). Alat yang `firmware_release_id`-nya
 * menunjuk ke sini menerima config.firmware_update lalu mengunduh file lewat GET /api/absensi/firmware/{id}.
 */
#[Fillable(['version', 'path', 'size', 'md5', 'notes'])]
class FirmwareRelease extends Model
{
    /** Ukuran slot OTA pada partisi min_spiffs (1.875 MB). File lebih besar tidak muat. */
    public const MAX_BYTES = 1966080;

    /** Byte pertama image aplikasi ESP32. */
    public const IMAGE_MAGIC = 0xE9;

    public const DISK = 'local';

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /** Alat yang dijadwalkan update ke firmware ini. */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** Path lengkap file .bin di disk. */
    public function absolutePath(): string
    {
        return Storage::disk(self::DISK)->path($this->path);
    }
}
