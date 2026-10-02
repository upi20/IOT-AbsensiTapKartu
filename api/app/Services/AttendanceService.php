<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\Member;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
 * Tap dengan tap_id yang sudah pernah dicatat alat yang sama tidak dicatat lagi (resent), dari endpoint mana pun.
 *
 * Mode pilih (POST /check-in & /check-out, $mode diisi): jenisnya ditentukan petugas di alat, bukan urutan tap.
 *  - check_in : sudah ada check_in hari itu      -> duplicate (earlier = check_in pertama hari itu)
 *  - check_out: ada check_out hari itu dalam jendela duplikat (default 60 detik) -> duplicate
 *  - selain itu                                  -> check_in / check_out sesuai $mode
 * Kartu tidak terdaftar & anggota nonaktif sama dengan di atas. Barisnya masuk ke data absensi yang sama.
 */
class AttendanceService
{
    /**
     * @param  CarbonInterface|null  $at  Waktu tap. Null = sekarang. Tap antrean memakai tapped_at dari alat.
     * @param  array<string, mixed>|null  $payload  JSON mentah dari alat (disimpan apa adanya).
     * @param  string|null  $tapId  ID tap dari alat (API standar). Null untuk API lama.
     * @param  AttendanceType|null  $mode  Jenis tap dari mode pilih (/check-in, /check-out). Null = ditentukan server (/tap).
     */
    public function tap(Device $device, string $cardNumber, ?CarbonInterface $at = null, ?array $payload = null, ?string $tapId = null, ?AttendanceType $mode = null): TapResult
    {
        $at = Carbon::instance($at ?? now())->setTimezone(config('app.timezone'));

        return DB::transaction(function () use ($device, $cardNumber, $at, $payload, $tapId, $mode) {
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
            $window = (int) config('absensi.duplicate_window_seconds', 60);

            if ($mode !== null) {
                $earlier = $this->selectedModeEarlier((clone $accepted)->where('type', $mode), $mode, $at, $window);

                return $earlier !== null
                    ? new TapResult($record(AttendanceStatus::Duplicate), $member, $earlier)
                    : new TapResult($record(AttendanceStatus::Success, $mode), $member);
            }

            // Duplikat: ada tap diterima dalam jendela (default 60 detik) di sekitar waktu tap ini.
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

    /**
     * Mode pilih: tap diterima sebelumnya yang membuat tap ini duplicate. Datang: check_in pertama hari itu.
     * Pulang: check_out hari itu dalam jendela duplikat di sekitar waktu tap ini.
     *
     * @param  Builder<Attendance>  $sameType  Tap diterima anggota ini dengan jenis $mode.
     */
    private function selectedModeEarlier(Builder $sameType, AttendanceType $mode, Carbon $at, int $window): ?Attendance
    {
        $sameType->whereBetween('tapped_at', [$at->copy()->startOfDay(), $at->copy()->endOfDay()]);

        if ($mode === AttendanceType::CheckIn) {
            return $sameType->orderBy('tapped_at')->first();
        }

        return $sameType
            ->where('tapped_at', '>', $at->copy()->subSeconds($window))
            ->where('tapped_at', '<', $at->copy()->addSeconds($window))
            ->latest('tapped_at')
            ->first();
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
