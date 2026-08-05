<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versioned KK photo archive.
     * Full file metadata per approved decision #2.
     * Exactly one row per kk_id has is_active = true (enforced in Service layer).
     */
    public function up(): void
    {
        Schema::create('kk_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kk_id')
                ->constrained('kartu_keluarga')
                ->onDelete('RESTRICT')
                ->onUpdate('CASCADE');
            $table->string('original_filename');
            $table->string('stored_filename');
            $table->string('thumbnail_filename')->nullable();
            $table->string('mime_type');
            $table->bigInteger('file_size');
            $table->char('sha256_hash', 64);
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->enum('photo_type', ['KK_PHOTO', 'RESIDENT_PHOTO'])->default('KK_PHOTO');
            $table->boolean('is_active')->default(true);
            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('SET NULL')
                ->onUpdate('CASCADE');
            $table->timestamp('uploaded_at');
            $table->foreignId('ocr_job_id')
                ->nullable()
                ->constrained('ocr_jobs')
                ->onDelete('SET NULL')
                ->onUpdate('CASCADE');
            $table->timestamps();
            $table->index(['kk_id', 'is_active']);
            $table->index('sha256_hash');
            $table->index('ocr_job_id');
            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kk_photos');
    }
};
