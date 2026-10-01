<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Models\Attendance;
use App\Models\Member;

/**
 * Hasil satu tap kartu. Controller API mengubahnya menjadi JSON sesuai format API masing-masing.
 */
final class TapResult
{
    public function __construct(
        /** Baris absensi yang dicatat untuk tap ini. */
        public readonly Attendance $attendance,
        /** Pemilik kartu (null = kartu tidak terdaftar). */
        public readonly ?Member $member,
        /** Untuk duplicate: tap diterima sebelumnya yang membuat tap ini dianggap duplikat. */
        public readonly ?Attendance $earlier = null,
        /** true = tap_id ini sudah pernah dicatat (alat mengirim ulang); tidak ada baris baru. */
        public readonly bool $resent = false,
    ) {}

    public function status(): AttendanceStatus
    {
        return $this->attendance->status;
    }

    public function type(): ?AttendanceType
    {
        return $this->attendance->type;
    }
}
