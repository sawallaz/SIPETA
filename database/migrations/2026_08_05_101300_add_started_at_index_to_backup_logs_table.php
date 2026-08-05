<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Audit fix (PHASE2-AUDIT §11): add the missing INDEX on
     * backup_logs.started_at that docs/PHASE2-ARCHITECTURE.md §7 prescribes.
     * Added as a NEW migration so the 13 released domain migrations are untouched.
     */
    public function up(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->index('started_at', 'backup_logs_started_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('backup_logs', function (Blueprint $table) {
            $table->dropIndex('backup_logs_started_at_index');
        });
    }
};
