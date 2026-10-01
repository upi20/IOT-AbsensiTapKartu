<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            // Perangkat yang mendaftarkan kartu (null = dari panel admin / artisan).
            $table->foreignId('registered_by_device_id')->nullable()->after('is_active')
                ->constrained('devices')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('registered_by_device_id');
        });
    }
};
