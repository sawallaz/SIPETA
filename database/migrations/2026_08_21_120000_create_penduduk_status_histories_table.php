<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penduduk_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('penduduk_id')
                ->constrained('penduduk')
                ->onDelete('CASCADE')
                ->onUpdate('CASCADE');
            $table->enum('status', ['ACTIVE', 'PINDAH', 'MENINGGAL']);
            $table->date('recorded_at');
            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('penduduk_id');
            $table->index('status');
            $table->index('recorded_at');
            $table->index(['penduduk_id', 'status', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penduduk_status_histories');
    }
};
