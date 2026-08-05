<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only backup history. No updated_at by design.
     */
    public function up(): void
    {
        Schema::create('backup_logs', function (Blueprint $table) {
            $table->id();
            $table->string('filename')->unique();
            $table->enum('backup_type', ['MANUAL', 'SCHEDULED'])->default('MANUAL');
            $table->enum('backup_status', ['SUCCESS', 'FAILED']);
            $table->bigInteger('backup_size');
            $table->foreignId('operator_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('SET NULL')
                ->onUpdate('CASCADE');
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_logs');
    }
};
