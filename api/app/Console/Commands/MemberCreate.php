<?php

namespace App\Console\Commands;

use App\Models\Member;
use Illuminate\Console\Command;

class MemberCreate extends Command
{
    protected $signature = 'absensi:member-create {name : Nama anggota} {card_uid : Nomor kartu 10 digit, mis. 0218893066} {--identifier= : NIS/NIP (opsional)}';

    protected $description = 'Daftarkan anggota baru beserta nomor kartunya';

    public function handle(): int
    {
        $uid = Member::normalizeUid($this->argument('card_uid'));
        $identifier = $this->option('identifier') ?: null;

        if (! Member::isValidUid($uid)) {
            $this->error("UID \"{$uid}\" tidak valid (10 digit angka atau 8-20 karakter hex).");

            return self::FAILURE;
        }

        if ($existing = Member::where('card_uid', $uid)->first()) {
            $this->error("UID {$uid} sudah dipakai oleh #{$existing->id} {$existing->name}.");

            return self::FAILURE;
        }

        if ($identifier !== null && ($existing = Member::where('identifier', $identifier)->first())) {
            $this->error("Identifier {$identifier} sudah dipakai oleh #{$existing->id} {$existing->name}.");

            return self::FAILURE;
        }

        $member = Member::create([
            'name' => $this->argument('name'),
            'card_uid' => $uid,
            'identifier' => $identifier,
        ]);

        $this->info("Anggota #{$member->id} {$member->name} terdaftar dengan kartu {$member->card_uid}.");

        return self::SUCCESS;
    }
}
