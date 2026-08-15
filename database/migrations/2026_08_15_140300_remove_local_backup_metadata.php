<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('settings', 'backup_path')) {
            Schema::table('settings', function (Blueprint $table): void {
                $table->dropColumn('backup_path');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('settings', 'backup_path')) {
            Schema::table('settings', function (Blueprint $table): void {
                $table->string('backup_path')->nullable();
            });
        }
    }
};
