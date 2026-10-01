<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin web: login memakai username ATAU email.
 * Password boleh kosong (null) = akun belum bisa dipakai login sampai password diatur
 * lewat `php artisan absensi:admin-password <username>`.
 *
 * Aman dijalankan di database yang sudah berisi data (hanya menambah / melonggarkan).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('username', 50)->nullable()->unique()->after('name');
            });
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });

        // Akun admin awal TANPA password (tidak bisa login sampai password diatur).
        $exists = DB::table('users')
            ->where('username', 'admin')
            ->orWhere('email', 'admin@example.com')
            ->exists();

        if (! $exists) {
            DB::table('users')->insert([
                'name' => 'Administrator',
                'username' => 'admin',
                'email' => 'admin@example.com',
                'password' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Sengaja tidak menghapus akun admin atau mengembalikan kolom password ke NOT NULL
        // (bisa gagal / menghapus data). Hanya kolom username yang dilepas.
        if (Schema::hasColumn('users', 'username')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropUnique(['username']);
                $table->dropColumn('username');
            });
        }
    }
};
