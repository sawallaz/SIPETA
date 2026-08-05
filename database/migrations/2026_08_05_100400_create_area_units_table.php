<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Flexible Area Level 1 (Lingkungan OR RW, per local government).
     * The `type` column carries the local admin label so the same schema
     * serves any kelurahan without structural change.
     */
    public function up(): void
    {
        Schema::create('area_units', function (Blueprint $table) {
            $table->id();
            $table->string('name');        // display label, e.g. "Lingkungan I" / "RW 01"
            $table->string('type')->nullable(); // config hint: lingkungan | rw
            $table->string('code')->nullable();  // short code, e.g. I | 01
            $table->timestamps();
            $table->unique('name');
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('area_units');
    }
};
