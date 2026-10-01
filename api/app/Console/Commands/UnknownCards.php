<?php

namespace App\Console\Commands;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use App\Models\Device;
use App\Models\Member;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class UnknownCards extends Command
{
    protected $signature = 'absensi:unknown-cards {--limit=20 : Jumlah UID maksimum} {--all : Termasuk UID yang sekarang sudah terdaftar}';

    protected $description = 'Tampilkan nomor kartu tak dikenal yang baru-baru ini ditempel (untuk mendaftarkan kartu baru)';

    public function handle(): int
    {
        $query = Attendance::query()
            ->where('status', AttendanceStatus::UnknownCard)
            ->selectRaw('card_uid, COUNT(*) as taps, MAX(tapped_at) as last_tap, MAX(id) as last_id')
            ->groupBy('card_uid')
            ->orderByDesc('last_tap')
            ->limit(max((int) $this->option('limit'), 1));

        if (! $this->option('all')) {
            $query->whereNotIn('card_uid', Member::select('card_uid'));
        }

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->info('Tidak ada kartu tak dikenal.');

            return self::SUCCESS;
        }

        $devices = Attendance::whereIn('id', $rows->pluck('last_id'))->pluck('device_id', 'id');
        $deviceNames = Device::whereIn('id', $devices->values())->pluck('name', 'id');

        $this->table(
            ['Nomor Kartu', 'Tap Terakhir', 'Alat', 'Jumlah Tap'],
            $rows->map(fn ($r) => [
                $r->card_uid,
                Carbon::parse($r->last_tap)->format('Y-m-d H:i:s'),
                $deviceNames[$devices[$r->last_id] ?? null] ?? '-',
                $r->taps,
            ]),
        );

        $this->line('Daftarkan dengan: php artisan absensi:member-create "Nama" <nomor-kartu> --identifier=<NIS/NIP>');

        return self::SUCCESS;
    }
}
