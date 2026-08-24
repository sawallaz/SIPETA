<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('penduduk', function (Blueprint $table) {
            $table->date('active_at')->nullable()->after('resident_status');
            $table->index('active_at');
        });
    }

    public function down(): void
    {
        Schema::table('penduduk', function (Blueprint $table) {
            $table->dropIndex(['active_at']);
            $table->dropColumn('active_at');
        });
    }
};
