<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\Member;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Logika tap kartu, dipakai API standar (/api/absensi) dan API lama (/api/v1).
 * Per anggota, per hari kalender Asia/Jakarta:
 *
 *  - kartu tidak terdaftar      -> unknown_card (tetap dicatat, muncul di "Kartu belum terdaftar")
 *  - anggota nonaktif           -> inactive
 *  - < 60 detik dari tap diterima sebelumnya -> duplicate (dicatat, tidak mengubah masuk/pulang)
 *  - tap diterima pertama hari itu           -> check_in
 *  - tap diterima berikutnya                 -> check_out (jam pulang = check_out terakhir)
 *
 * Semua baris memakai status "success" untuk tap yang diterima (check_in / check_out).
 * Tap antrean yang jamnya lebih awal dari check_in yang sudah ada juga dicatat sebagai check_in;
 * check_in yang lama tidak diubah. Rekap & info "Masuk" memakai check_in paling awal hari itu.
 * Tap dengan tap_id yang sudah pernah dicatat alat yang sama tidak dicatat lagi (resent).
 */
class AttendanceService
{
    /**
     * @param  CarbonInterface|null  $at  Waktu tap. Null = sekarang. Tap antrean memakai tapped_at dari alat.
     * @param  array<string, mixed>|null  $payload  JSON mentah dari alat (disimpan apa adanya).
     * @param  string|null  $tapId  ID tap dari alat (API standar). Null untuk API lama.
     */
    public function tap(Device $device, string $cardNumber, ?CarbonInterface $at = null, ?array $payload = null, ?string $tapId = null): TapResult
    {
        $at = Carbon::instance($at ?? now())->setTimezone(config('app.timezone'));

        return DB::transaction(function () use ($device, $cardNumber, $at, $payload, $tapId) {
            // Kunci baris anggota agar dua tap bersamaan tidak sama-sama jadi check_in.
            $member = Member::byCard($cardNumber)->lockForUpdate()->first();

            // Tap yang sama dikirim ulang (mis. dari antrean): jangan dicatat dua kali.
            $existing = $tapId === null ? null : Attendance::with('member')
                ->where('device_id', $device->id)
                ->where('tap_id', $tapId)
                ->first();

            if ($existing !== null) {
                return new TapResult($existing, $existing->member, resent: true);
            }

            $record = fn (AttendanceStatus $status, ?AttendanceType $type = null) => Attendance::create([
                'member_id' => $member?->id,
                'device_id' => $device->id,
                'tap_id' => $tapId,
                'card_uid' => $cardNumber,
                'type' => $type,
                'status' => $status,
                'tapped_at' => $at,
                'payload' => $payload,
            ])->setRelation('member', $member);

            if ($member === null) {
                return new TapResult($record(AttendanceStatus::UnknownCard), null);
            }

            if (! $member->is_active) {
                return new TapResult($record(AttendanceStatus::Inactive), $member);
            }

            $accepted = Attendance::where('member_id', $member->id)->where('status', AttendanceStatus::Success);

            // Duplikat: ada tap diterima dalam jendela (default 60 detik) di sekitar waktu tap ini.
            $window = (int) config('absensi.duplicate_window_seconds', 60);
            $earlier = (clone $accepted)
                ->where('tapped_at', '>', $at->copy()->subSeconds($window))
                ->where('tapped_at', '<', $at->copy()->addSeconds($window))
                ->latest('tapped_at')
                ->first();

            if ($earlier !== null) {
                return new TapResult($record(AttendanceStatus::Duplicate), $member, $earlier);
            }

            // Tap diterima pertama hari itu (sebelum waktu tap ini) = masuk, selanjutnya = pulang.
            $hasEarlierToday = (clone $accepted)
                ->whereBetween('tapped_at', [$at->copy()->startOfDay(), $at])
                ->exists();

            $type = $hasEarlierToday ? AttendanceType::CheckOut : AttendanceType::CheckIn;

            return new TapResult($record(AttendanceStatus::Success, $type), $member);
        });
    }

    /** Jam masuk (check_in pertama) anggota pada hari yang sama dengan $day. */
    public function checkInTime(Member $member, CarbonInterface $day): ?Carbon
    {
        return Attendance::where('member_id', $member->id)
            ->where('status', AttendanceStatus::Success)
            ->where('type', AttendanceType::CheckIn)
            ->whereBetween('tapped_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('tapped_at')
            ->first()?->tapped_at;
    }
}
