<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
 * indexes and re-asserting the CHECK. On MySQL it compiles to a native
 * `ALTER TABLE ... MODIFY ENUM(...)`.
 *
 * The rebuild copies every existing row into the new table and re-validates
 * it against the new CHECK. Widening (up) can therefore never fail — the new
 * value list is a superset of the old one. Narrowing (down) would fail with
 * "CHECK constraint failed: status" as soon as a COMPLETED row exists, so
 * down() first re-maps COMPLETED → SUCCESS (the pre-5.9 terminal status)
 * before shrinking the constraint. The same remap protects MySQL, where
 * MODIFY ENUM errors on out-of-list values.
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
        // COMPLETED did not exist before Phase 5.9; fold finalized jobs back
        // into SUCCESS so the narrowed CHECK accepts every surviving row.
        DB::table('ocr_jobs')->where('status', 'COMPLETED')->update(['status' => 'SUCCESS']);

        Schema::table('ocr_jobs', function (Blueprint $table) {
            $table->enum('status', ['PENDING', 'SUCCESS', 'LOW_CONFIDENCE', 'FAILED', 'CANCELLED'])->change();
        });
    }
};
