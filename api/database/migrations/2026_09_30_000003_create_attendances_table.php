<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('card_uid', 32);
            $table->string('type', 16)->nullable();   // check_in | check_out | null
            $table->string('status', 16);             // success | unknown_card | inactive | duplicate
            $table->timestamp('tapped_at');
            $table->timestamps();

            $table->index(['member_id', 'tapped_at']);
            $table->index('card_uid');
            $table->index(['status', 'tapped_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
