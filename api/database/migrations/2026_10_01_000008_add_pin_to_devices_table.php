<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PIN menu Pengaturan kini diatur per alat (bukan satu PIN untuk semua alat).
 * PIN global lama (settings.device_pin) disalin ke alat yang sudah ada, lalu dihapus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('pin', 8)->nullable()->after('name');
        });

        $globalPin = DB::table('settings')->where('key', 'device_pin')->value('value');

        if ($globalPin) {
            DB::table('devices')->whereNotNull('code')->update(['pin' => $globalPin]);
        }

        DB::table('settings')->where('key', 'device_pin')->delete();
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('pin');
        });
    }
};
