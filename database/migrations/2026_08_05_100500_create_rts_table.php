<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_unit_id')
                ->constrained('area_units')
                ->onDelete('RESTRICT')
                ->onUpdate('CASCADE');
            $table->string('number'); // e.g. "01"
            $table->timestamps();
            $table->unique(['area_unit_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rts');
    }
};
