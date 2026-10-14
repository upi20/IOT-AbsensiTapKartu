<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Daya alat dari modul UPS (firmware 1.11.2+), diisi dari heartbeat raw.power:
 *  - power_source: "mains" (listrik PLN ada, baterai dicas), "battery" (listrik padam, alat memakai baterai),
 *    "none" (alat melapor raw.power null = pemantau baterai tidak terpasang). Null = belum dilaporkan / firmware lama.
 *  - battery_mv, battery_pct: tegangan baterai (mV) dan perkiraan isi (0–100 %).
 *  - battery_low: alat melapor baterai lemah (memakai baterai dan di bawah 3,4 V).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('power_source', 8)->nullable();
            $table->smallInteger('battery_mv')->nullable();
            $table->smallInteger('battery_pct')->nullable();
            $table->boolean('battery_low')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['power_source', 'battery_mv', 'battery_pct', 'battery_low']);
        });
    }
};
