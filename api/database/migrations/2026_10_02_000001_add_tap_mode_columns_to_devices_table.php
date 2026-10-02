<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mode absen per alat (firmware 1.6.0+):
 *  - tap_mode: pengaturan dari server, dikirim lewat config.tap_mode ("auto" | "select"). Null = ikuti pengaturan di alat.
 *  - reported_tap_mode: mode yang sedang dipakai alat, dari heartbeat raw.tap_mode.
 *  - tap_select: pilihan saat ini di mode "select" ("check_in" | "check_out"), dari heartbeat raw.tap_select.
 *    Null = belum memilih / mode auto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('tap_mode', 8)->nullable();
            $table->string('reported_tap_mode', 8)->nullable();
            $table->string('tap_select', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['tap_mode', 'reported_tap_mode', 'tap_select']);
        });
    }
};
