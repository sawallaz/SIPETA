<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penduduk_documents', function (Blueprint $table) {
            $table->id();

            $table->foreignId('penduduk_id')
                ->constrained('penduduk')
                ->onDelete('RESTRICT')
                ->onUpdate('CASCADE');

            $table->enum('document_type', [
                'KTP',
                'AKTA_KELAHIRAN',
            ]);

            $table->string('original_filename')->nullable();

            $table->string('stored_filename');

            $table->string('mime_type');

            $table->unsignedBigInteger('file_size');

            $table->char('sha256_hash', 64);

            $table->string('storage_disk');

            $table->string('storage_path');

            /*
             * Jika dokumen diganti:
             * dokumen lama tidak langsung dihapus.
             * is_active=false menjadi histori.
             */
            $table->boolean('is_active')->default(true);

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->onDelete('SET NULL')
                ->onUpdate('CASCADE');

            $table->timestamp('uploaded_at');

            $table->timestamps();

            $table->index([
                'penduduk_id',
                'document_type',
                'is_active',
            ]);

            $table->index('sha256_hash');

            $table->index('uploaded_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penduduk_documents');
    }
};
