<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Nilai awal pengaturan: API key acak (24 karakter) dan judul layar alat.
 * Tidak menimpa nilai yang sudah ada. PIN alat sengaja tidak diisi (alat memakai PIN-nya sendiri).
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'api_key' => Str::random(24),
            'title' => 'Absensi RFID',
        ];

        foreach ($defaults as $key => $value) {
            if (! DB::table('settings')->where('key', $key)->exists()) {
                DB::table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Sisa rancangan lama (hash PIN mode admin alat), tidak dipakai lagi.
        DB::table('settings')->where('key', 'device_admin_pin')->delete();
    }

    public function down(): void
    {
        // Sengaja kosong: API key yang sudah terpasang di alat tidak dihapus.
    }
};
