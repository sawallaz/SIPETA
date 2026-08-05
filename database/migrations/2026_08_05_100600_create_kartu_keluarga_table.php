<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kartu_keluarga', function (Blueprint $table) {
            $table->id();
            $table->string('kk_number', 16)->unique();
            $table->string('address');
            $table->string('postal_code')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kartu_keluarga');
    }
};
