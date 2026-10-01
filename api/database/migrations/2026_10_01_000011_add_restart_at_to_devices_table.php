<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jam restart harian per alat ("HH:MM", jam lokal alat), dikirim lewat config.restart_at.
 * Alat yang sudah ada mendapat bawaan 03:00. Null = alat tidak restart otomatis.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('restart_at', 5)->nullable()->default('03:00')->after('pin');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('restart_at');
        });
    }
};
