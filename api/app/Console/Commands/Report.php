<?php

namespace App\Console\Commands;

use App\Enums\AttendanceStatus;
use App\Enums\AttendanceType;
use App\Models\Attendance;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class Report extends Command
{
    protected $signature = 'absensi:report {date? : Tanggal (YYYY-MM-DD), default hari ini}';

    protected $description = 'Rekap check-in pertama & check-out terakhir per anggota untuk satu tanggal';

    public function handle(): int
    {
        $arg = $this->argument('date');
        $date = now()->startOfDay();

        if ($arg !== null) {
            try {
                $date = Carbon::createFromFormat('!Y-m-d', $arg);
            } catch (\Throwable) {
                $date = null;
            }

            // Tolak tanggal yang "meluap" seperti 2026-13-01.
            if ($date === null || $date->format('Y-m-d') !== $arg) {
                $this->error('Tanggal tidak valid, gunakan YYYY-MM-DD.');

                return self::FAILURE;
            }
        }

        $taps = Attendance::query()
            ->whereNotNull('member_id')
            ->where('status', AttendanceStatus::Success)
            ->whereBetween('tapped_at', [$date->copy()->startOfDay(), $date->copy()->endOfDay()])
            ->orderBy('tapped_at')
            ->get()
            ->groupBy('member_id');

        // Semua anggota aktif + anggota (nonaktif) yang tetap punya tap di tanggal itu.
        $members = Member::query()
            ->where('is_active', true)
            ->orWhereIn('id', $taps->keys())
            ->orderBy('name')
            ->get();

        $present = 0;
        $rows = $members->map(function (Member $m) use ($taps, &$present) {
            $memberTaps = $taps->get($m->id, collect());
            $in = $memberTaps->firstWhere('type', AttendanceType::CheckIn);
            $out = $memberTaps->where('type', AttendanceType::CheckOut)->last();
            if ($in) {
                $present++;
            }

            return [
                $m->name,
                $m->identifier ?? '-',
                $in?->tapped_at->format('H:i:s') ?? '-',
                $out?->tapped_at->format('H:i:s') ?? '-',
            ];
        });

        $this->info('Rekap absensi '.$date->toDateString().' ('.config('app.timezone').')');
        $this->table(['Nama', 'Identifier', 'Check-in', 'Check-out'], $rows);
        $this->line("Hadir: {$present} / {$members->count()}");

        return self::SUCCESS;
    }
}
