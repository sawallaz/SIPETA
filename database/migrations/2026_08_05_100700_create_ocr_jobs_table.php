<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * OCR attempt log + extracted-data snapshot.
     * Audit/infrastructure only — never the source of truth.
     */
    public function up(): void
    {
        Schema::create('ocr_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kk_id')
                ->nullable()
                ->constrained('kartu_keluarga')
                ->onDelete('SET NULL')
                ->onUpdate('CASCADE');
            $table->char('source_image_hash', 64)->nullable();
            $table->string('source_image_path');
            $table->enum('status', ['PENDING', 'SUCCESS', 'LOW_CONFIDENCE', 'FAILED', 'CANCELLED']);
            $table->decimal('confidence', 5, 2)->nullable();
            $table->longText('raw_text')->nullable();
            $table->longText('corrected_text')->nullable();
            $table->json('extracted_data')->nullable();
            $table->foreignId('operator_id')
                ->nullable()
                ->constrained('users')
                ->onDelete('SET NULL')
                ->onUpdate('CASCADE');
            $table->timestamp('reviewed_at')->nullable();
            $table->enum('outcome', ['SAVED', 'DISCARDED', 'MANUAL'])->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index('source_image_hash');
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_jobs');
    }
};
