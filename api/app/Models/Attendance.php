<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu tap kartu. `status` = hasil (success / duplicate / unknown_card / inactive),
 * `type` = check_in / check_out untuk tap yang diterima (status success).
 */
#[Fillable(['member_id', 'device_id', 'tap_id', 'card_uid', 'type', 'status', 'tapped_at', 'payload'])]
class Attendance extends Model
{
    protected function casts(): array
    {
        return [
            'type' => AttendanceType::class,
            'status' => AttendanceStatus::class,
            'tapped_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    /** Tap ini dikirim belakangan dari antrean alat (server sempat tidak bisa dihubungi). */
    public function wasQueued(): bool
    {
        return (bool) ($this->payload['queued'] ?? false);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
