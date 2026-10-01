<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom untuk API standar (doc/spesifikasi-api.md). Hanya menambah kolom, data lama tetap.
 *
 *  - devices.code        : ID alat dari header X-Device-ID, mis. "ABS-1A2B3C".
 *                          Alat API lama (/api/v1) tidak punya code, tetapi punya api_key.
 *  - devices.api_key     : sekarang boleh kosong (alat API standar memakai API key global).
 *  - members.photo_path  : foto anggota (JPEG kecil di disk "public").
 *  - attendances.tap_id  : ID tap dari alat; sama persis saat alat mengirim ulang tap dari antrean,
 *                          jadi unik per alat untuk mencegah catatan ganda.
 *  - attendances.payload : JSON mentah yang dikirim alat saat tap.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('code', 64)->nullable()->unique()->after('id');
            $table->string('firmware', 32)->nullable()->after('last_seen_at');
            $table->string('ip', 45)->nullable()->after('firmware');
            $table->smallInteger('rssi')->nullable()->after('ip');
            $table->string('wifi_ssid', 64)->nullable()->after('rssi');
            $table->jsonb('last_payload')->nullable()->after('wifi_ssid');
            $table->string('api_key', 64)->nullable()->change();
        });

        Schema::table('members', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('is_active');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->string('tap_id', 64)->nullable()->after('device_id');
            $table->jsonb('payload')->nullable()->after('tapped_at');
            $table->unique(['device_id', 'tap_id']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique(['device_id', 'tap_id']);
            $table->dropColumn(['tap_id', 'payload']);
        });

        Schema::table('members', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn(['code', 'firmware', 'ip', 'rssi', 'wifi_ssid', 'last_payload']);
        });
    }
};
