<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jadwal mode pilih per alat (firmware 1.11.0+):
 *  - schedule_check_in, schedule_check_out: jam DATANG & PULANG dipilih otomatis ("HH:MM"), dikirim lewat
 *    config.tap_schedule. Keduanya null = tanpa jadwal (dikirim sebagai null agar alat mematikan jadwalnya).
 *  - reported_tap_schedule: jadwal yang sedang dipakai alat, dari heartbeat raw.tap_schedule ("HH:MM-HH:MM").
 *    Null = tanpa jadwal / firmware lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('schedule_check_in', 5)->nullable();
            $table->string('schedule_check_out', 5)->nullable();
            $table->string('reported_tap_schedule', 11)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['schedule_check_in', 'schedule_check_out', 'reported_tap_schedule']);
        });
    }
};
