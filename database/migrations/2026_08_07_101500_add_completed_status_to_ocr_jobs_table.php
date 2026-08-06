<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the ocr_jobs.status constraint to admit the terminal COMPLETED
 * state (Phase 5.9).
 *
 * Phase 5.2 documented this as the "deliberate future schema change": the
 * Phase 2 constraint (SQLite CHECK / MySQL ENUM) lists only
 * PENDING, SUCCESS, LOW_CONFIDENCE, FAILED, CANCELLED. The finalization
 * sub-phase transitions a fully imported job (Phase 5.7 KK + Phase 5.8
 * Penduduk) to the COMPLETED state, so the value is added to both the PHP
 * enum and this column constraint. Purely additive — existing values, rows
 * and the NOT NULL rule are untouched.
 *
 * On SQLite Laravel's schema builder handles `change()` by rebuilding the
 * table (the grammar has no in-place column alter), preserving the FKs and
 * indexes and re-asserting the widened CHECK. On MySQL it compiles to a
 * native `ALTER TABLE ... MODIFY ENUM(...)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_jobs', function (Blueprint $table) {
            $table->enum('status', ['PENDING', 'SUCCESS', 'LOW_CONFIDENCE', 'FAILED', 'CANCELLED', 'COMPLETED'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('ocr_jobs', function (Blueprint $table) {
            $table->enum('status', ['PENDING', 'SUCCESS', 'LOW_CONFIDENCE', 'FAILED', 'CANCELLED'])->change();
        });
    }
};
