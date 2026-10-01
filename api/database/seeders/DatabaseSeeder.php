<?php

namespace Database\Seeders;

use App\Models\Member;
use Illuminate\Database\Seeder;

/**
 * Data contoh untuk pengembangan: dua anggota. API key dan judul dibuat oleh migrasi.
 * Alat tidak perlu di-seed: alat terdaftar otomatis saat pertama kali menghubungi /api/absensi.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $members = [
            ['name' => 'Budi Santoso', 'identifier' => '1001', 'card_uid' => '0218893066'],
            ['name' => 'Siti Aminah', 'identifier' => '1002', 'card_uid' => '0287454020'],
        ];

        foreach ($members as $data) {
            Member::firstOrCreate(['card_uid' => $data['card_uid']], $data);
        }

        $this->command?->info('Anggota contoh: Budi Santoso (0218893066), Siti Aminah (0287454020).');
    }
}
