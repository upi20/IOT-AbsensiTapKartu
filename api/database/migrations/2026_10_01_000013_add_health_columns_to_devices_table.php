<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kesehatan alat dari heartbeat terakhir (raw.uptime_s, reset_reason, rfid_ok, queue, free_heap,
 * min_free_heap, error, ota_failed) dan target update firmware (firmware_release_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->timestamp('heartbeat_at')->nullable();
            $table->unsignedBigInteger('uptime_s')->nullable();
            $table->string('reset_reason', 16)->nullable();
            $table->boolean('rfid_ok')->nullable();
            $table->unsignedInteger('queue')->nullable();
            $table->unsignedInteger('free_heap')->nullable();
            $table->unsignedInteger('min_free_heap')->nullable();
            $table->string('error_code', 8)->nullable();
            $table->string('ota_failed', 32)->nullable();
            $table->foreignId('firmware_release_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('firmware_release_id');
            $table->dropColumn([
                'heartbeat_at', 'uptime_s', 'reset_reason', 'rfid_ok', 'queue',
                'free_heap', 'min_free_heap', 'error_code', 'ota_failed',
            ]);
        });
    }
};
