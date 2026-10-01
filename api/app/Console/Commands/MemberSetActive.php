<?php

namespace App\Console\Commands;

use App\Models\Member;
use Illuminate\Console\Command;

class MemberSetActive extends Command
{
    protected $signature = 'absensi:member-deactivate {card_uid : Nomor kartu anggota} {--activate : Aktifkan kembali alih-alih menonaktifkan}';

    protected $description = 'Nonaktifkan (atau aktifkan kembali dengan --activate) kartu anggota';

    public function handle(): int
    {
        $uid = Member::normalizeUid($this->argument('card_uid'));
        $member = Member::where('card_uid', $uid)->first();

        if ($member === null) {
            $this->error("Tidak ada anggota dengan UID {$uid}.");

            return self::FAILURE;
        }

        $member->update(['is_active' => (bool) $this->option('activate')]);

        $this->info("{$member->name} ({$uid}) sekarang ".($member->is_active ? 'aktif' : 'nonaktif').'.');

        return self::SUCCESS;
    }
}
