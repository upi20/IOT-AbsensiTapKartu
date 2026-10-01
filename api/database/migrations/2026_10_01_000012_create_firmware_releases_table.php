<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File firmware (.bin) untuk update jarak jauh (OTA). File disimpan di disk "local"
 * (storage/app/private/firmware), diunduh alat lewat GET /api/absensi/firmware/{id}.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firmware_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 32)->unique();
            $table->string('path');
            $table->unsignedInteger('size');
            $table->string('md5', 32);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firmware_releases');
    }
};
