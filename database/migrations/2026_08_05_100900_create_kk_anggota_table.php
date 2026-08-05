<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * KK membership history.
     * A resident may be reassigned to a new KK (new official number);
     * the old KK <-> resident link is preserved here (status KELUAR + end_date)
     * while the active link (status AKTIF) stays in sync with penduduk.kk_id.
     */
    public function up(): void
    {
        Schema::create('kk_anggota', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kk_id')
                ->constrained('kartu_keluarga')
                ->onDelete('RESTRICT')
                ->onUpdate('CASCADE');
            $table->foreignId('penduduk_id')
                ->constrained('penduduk')
                ->onDelete('RESTRICT')
                ->onUpdate('CASCADE');
            $table->enum('family_relation', [
                'KEPALA_KELUARGA', 'ISTRI', 'ANAK', 'MENANTU', 'CUCU',
                'ORANG_TUA', 'MERTUA', 'FAMILI_LAIN', 'LAINNYA',
            ]);
            $table->enum('status', ['AKTIF', 'KELUAR']);
            $table->date('effective_date');
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->index('kk_id');
            $table->index('penduduk_id');
            $table->index('status');
            $table->index('effective_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kk_anggota');
    }
};
