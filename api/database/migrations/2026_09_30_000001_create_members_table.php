<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('identifier', 64)->nullable()->unique(); // NIS / NIP
            $table->string('card_uid', 32)->unique(); // nomor kartu 10 digit, mis. 0218893066 (data lama: hex 4 byte, mis. 0A0B0C0D)
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
