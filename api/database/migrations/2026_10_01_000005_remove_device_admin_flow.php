<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Alur "mode admin di alat" (PIN + X-Admin-Token + daftar kartu dari alat) tidak dipakai lagi.
 * Pendaftaran kartu sekarang hanya lewat panel admin. Tidak ada data anggota/absensi yang hilang:
 * yang dihapus hanya tabel token sementara dan kolom penanda "didaftarkan lewat alat".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('admin_sessions');

        if (Schema::hasColumn('members', 'registered_by_device_id')) {
            Schema::table('members', function (Blueprint $table) {
                $table->dropConstrainedForeignId('registered_by_device_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('registered_by_device_id')->nullable()->after('is_active')
                ->constrained('devices')->nullOnDelete();
        });

        Schema::create('admin_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }
};
