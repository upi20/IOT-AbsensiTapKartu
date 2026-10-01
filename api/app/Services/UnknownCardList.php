<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\Member;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * UID dari tap unknown_card yang (sekarang) belum terdaftar sebagai anggota.
 */
class UnknownCardList
{
    /**
     * @return Collection<int, array{uid: string, taps: int, first_tap: Carbon, last_tap: Carbon, device: ?string}>
     */
    public function recent(int $limit = 100): Collection
    {
        $rows = Attendance::query()
            ->where('status', AttendanceStatus::UnknownCard)
            ->whereNotIn('card_uid', Member::select('card_uid'))
            ->selectRaw('card_uid, COUNT(*) as taps, MIN(tapped_at) as first_tap, MAX(tapped_at) as last_tap, MAX(id) as last_id')
            ->groupBy('card_uid')
            ->orderByDesc('last_tap')
            ->limit($limit)
            ->toBase()
            ->get();

        $deviceIds = Attendance::whereIn('id', $rows->pluck('last_id'))->pluck('device_id', 'id');
        $deviceNames = Device::whereIn('id', $deviceIds->values())->pluck('name', 'id');

        return $rows->map(fn ($r) => [
            'uid' => $r->card_uid,
            'taps' => (int) $r->taps,
            'first_tap' => Carbon::parse($r->first_tap),
            'last_tap' => Carbon::parse($r->last_tap),
            'device' => $deviceNames[$deviceIds[$r->last_id] ?? null] ?? null,
        ]);
    }
}
