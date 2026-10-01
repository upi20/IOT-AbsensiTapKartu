<?php

namespace App\Console\Commands;

use App\Models\Member;
use Illuminate\Console\Command;

class MemberList extends Command
{
    protected $signature = 'absensi:member-list';

    protected $description = 'Tampilkan daftar anggota';

    public function handle(): int
    {
        $rows = Member::orderBy('name')->get()->map(fn (Member $m) => [
            $m->id,
            $m->name,
            $m->identifier ?? '-',
            $m->card_uid,
            $m->is_active ? 'aktif' : 'nonaktif',
        ]);

        if ($rows->isEmpty()) {
            $this->info('Belum ada anggota.');

            return self::SUCCESS;
        }

        $this->table(['ID', 'Nama', 'Identifier', 'UID Kartu', 'Status'], $rows);

        return self::SUCCESS;
    }
}
