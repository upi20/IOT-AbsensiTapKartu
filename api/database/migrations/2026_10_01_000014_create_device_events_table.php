<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Riwayat kejadian per alat (menyala ulang, crash, ganti firmware, update gagal).
 * Hanya 200 kejadian terbaru per alat yang disimpan (lihat DeviceEvent::record()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('message');
            $table->jsonb('details')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['device_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_events');
    }
};
