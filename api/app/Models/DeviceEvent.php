<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Kejadian pada alat, dicatat dari heartbeat (lihat Device::recordHeartbeat()):
 * boot (menyala ulang), crash, firmware (versi berganti), ota_failed (update firmware gagal).
 * Hanya KEEP kejadian terbaru per alat yang disimpan.
 */
#[Fillable(['type', 'message', 'details'])]
class DeviceEvent extends Model
{
    public const KEEP = 200;

    public const UPDATED_AT = null;

    /** Jenis kejadian => [label, warna lencana]. */
    public const TYPES = [
        'boot' => ['Menyala ulang', 'info'],
        'crash' => ['Crash', 'danger'],
        'firmware' => ['Firmware', 'success'],
        'ota_failed' => ['Update gagal', 'warning'],
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Catat kejadian baru untuk alat, lalu hapus kejadian lama di luar KEEP terbaru.
     *
     * @param  array<string, mixed>|null  $details
     */
    public static function record(Device $device, string $type, string $message, ?array $details = null): self
    {
        $event = $device->events()->create(['type' => $type, 'message' => $message, 'details' => $details]);

        $cutoff = $device->events()->orderByDesc('id')->skip(self::KEEP - 1)->value('id');

        if ($cutoff !== null) {
            $device->events()->where('id', '<', $cutoff)->delete();
        }

        return $event;
    }

    /** @return array{0: string, 1: string} */
    public function label(): array
    {
        return self::TYPES[$this->type] ?? [$this->type, 'muted'];
    }
}
