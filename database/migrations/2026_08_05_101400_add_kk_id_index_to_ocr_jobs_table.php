<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit fix (PHASE2-AUDIT §11): add an explicit INDEX on ocr_jobs.kk_id.
     * The FK auto-creates an index on MySQL, but the explicit index keeps the
     * schema DB-agnostic and documented. New migration; 13 releases untouched.
     */
    public function up(): void
    {
        Schema::table('ocr_jobs', function (Blueprint $table) {
            $table->index('kk_id', 'ocr_jobs_kk_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('ocr_jobs', function (Blueprint $table) {
            $table->dropIndex('ocr_jobs_kk_id_index');
        });
    }
};
