<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Models\Attendance;
use App\Models\Member;
use Carbon\CarbonPeriod;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Rekap per anggota per hari: jam masuk pertama & jam pulang terakhir (zona APP_TIMEZONE).
 */
class AttendanceReport
{
    /**
     * @return Collection<int, array{date: string, member_id: int, name: string, identifier: ?string, card_uid: string, check_in: ?string, check_out: ?string, note: string}>
     */
    public function rows(Carbon $from, Carbon $to, bool $presentOnly = false): Collection
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->endOfDay();

        $taps = Attendance::query()
            ->whereNotNull('member_id')
            ->where('status', AttendanceStatus::Success)
            ->whereBetween('tapped_at', [$from, $to])
            ->orderBy('tapped_at')
            ->orderBy('id')
            ->get(['member_id', 'type', 'tapped_at'])
            ->groupBy(fn (Attendance $a) => $a->tapped_at->toDateString().'|'.$a->member_id);

        $memberIdsWithTaps = $taps->keys()->map(fn ($k) => (int) explode('|', $k)[1])->unique();

        $members = Member::query()
            ->where(fn ($q) => $q
                ->when(! $presentOnly, fn ($q) => $q->where('is_active', true))
                ->orWhereIn('id', $memberIdsWithTaps))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $rows = collect();

        foreach (CarbonPeriod::create($from->copy(), '1 day', $to->copy()->startOfDay()) as $day) {
            $date = $day->toDateString();

            foreach ($members as $member) {
                $memberTaps = $taps->get($date.'|'.$member->id);

                if ($memberTaps === null) {
                    // Tidak hadir: hanya anggota aktif yang sudah terdaftar pada hari itu.
                    if ($presentOnly || ! $member->is_active || $member->created_at?->gt($day->copy()->endOfDay())) {
                        continue;
                    }
                }

                $in = $memberTaps?->first(fn (Attendance $a) => $a->type === AttendanceType::CheckIn);
                $out = $memberTaps?->last(fn (Attendance $a) => $a->type === AttendanceType::CheckOut);

                $rows->push([
                    'date' => $date,
                    'member_id' => $member->id,
                    'name' => $member->name,
                    'identifier' => $member->identifier,
                    'card_uid' => $member->card_uid,
                    'check_in' => $in?->tapped_at->format('H:i:s'),
                    'check_out' => $out?->tapped_at->format('H:i:s'),
                    'note' => match (true) {
                        $memberTaps === null => 'Tidak hadir',
                        $out === null => 'Tanpa tap pulang',
                        default => 'Hadir',
                    },
                ]);
            }
        }

        return $rows;
    }
}
