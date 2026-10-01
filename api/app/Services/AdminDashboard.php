<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\Member;

/**
 * Data dasbor: ringkasan hari ini, 20 tap terakhir, dan status alat.
 */
class AdminDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        $today = [now()->startOfDay(), now()->endOfDay()];

        $acceptedToday = Attendance::query()
            ->where('status', AttendanceStatus::Success)
            ->whereNotNull('member_id')
            ->whereBetween('tapped_at', $today);

        $presentIds = (clone $acceptedToday)->distinct()->pluck('member_id');

        return [
            'counts' => [
                'active' => Member::where('is_active', true)->count(),
                'checked_in' => $presentIds->count(),
                'checked_out' => (clone $acceptedToday)->where('type', AttendanceType::CheckOut)->distinct()->count('member_id'),
                'not_yet' => Member::where('is_active', true)->whereNotIn('id', $presentIds)->count(),
            ],
            'taps' => Attendance::query()
                ->with(['member:id,name', 'device:id,code,name'])
                ->latest('tapped_at')
                ->latest('id')
                ->limit(20)
                ->get(),
            'devices' => Device::orderByDesc('last_seen_at')->orderBy('id')->get(),
        ];
    }

    /**
     * Label & warna lencana untuk satu tap.
     *
     * @return array{0: string, 1: string}
     */
    public static function tapLabel(Attendance $tap): array
    {
        return match ($tap->status) {
            AttendanceStatus::Success => $tap->type === AttendanceType::CheckIn ? ['Masuk', 'success'] : ['Pulang', 'info'],
            AttendanceStatus::Duplicate => ['Sudah tercatat', 'muted'],
            AttendanceStatus::UnknownCard => ['Belum terdaftar', 'warning'],
            AttendanceStatus::Inactive => ['Ditolak (nonaktif)', 'danger'],
        };
    }
}
