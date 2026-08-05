<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('kelurahan_name');
            $table->string('kecamatan_name');
            $table->string('kabupaten_name');
            $table->string('province_name');
            $table->string('logo_path')->nullable();
            $table->string('backup_path');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
