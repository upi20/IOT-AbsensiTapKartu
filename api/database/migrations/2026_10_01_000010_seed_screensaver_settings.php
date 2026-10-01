<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Nilai awal screensaver: lama tiap pengumuman 3 detik, muncul setelah alat diam 30 detik.
 * Tidak menimpa nilai yang sudah ada.
 */
return new class extends Migration
{
    public function up(): void
    {
        $defaults = [
            'screensaver_interval' => '3',
            'screensaver_idle' => '30',
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
    }

    public function down(): void
    {
        DB::table('settings')->whereIn('key', ['screensaver_interval', 'screensaver_idle'])->delete();
    }
};
