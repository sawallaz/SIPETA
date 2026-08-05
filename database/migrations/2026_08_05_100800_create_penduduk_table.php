<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('penduduk', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kk_id')
                ->constrained('kartu_keluarga')
                ->onDelete('RESTRICT')
                ->onUpdate('CASCADE');
            $table->string('nik', 16)->unique();
            $table->string('full_name');
            $table->enum('gender', ['LAKI_LAKI', 'PEREMPUAN']);
            $table->string('birth_place');
            $table->date('birth_date'); // age is NEVER stored
            $table->foreignId('religion_id')
                ->constrained('religions')
                ->onDelete('RESTRICT')
                ->onUpdate('CASCADE');
            $table->foreignId('education_id')
                ->constrained('educations')
                ->onDelete('RESTRICT')
                ->onUpdate('CASCADE');
            $table->foreignId('occupation_id')
                ->constrained('occupations')
                ->onDelete('RESTRICT')
                ->onUpdate('CASCADE');
            $table->enum('marital_status', ['BELUM_KAWIN', 'KAWIN', 'CERAI_HIDUP', 'CERAI_MATI']);
            $table->enum('family_relation', [
                'KEPALA_KELUARGA', 'ISTRI', 'ANAK', 'MENANTU', 'CUCU',
                'ORANG_TUA', 'MERTUA', 'FAMILI_LAIN', 'LAINNYA',
            ]);
            $table->enum('blood_type', ['A', 'B', 'AB', 'O', 'TIDAK_DIKETAHUI']);
            $table->enum('resident_status', ['ACTIVE', 'PINDAH', 'MENINGGAL'])->default('ACTIVE');
            $table->foreignId('rt_id')
                ->constrained('rts')
                ->onDelete('RESTRICT')
                ->onUpdate('CASCADE');
            $table->date('moved_at')->nullable();
            $table->string('moved_destination')->nullable();
            $table->text('moved_note')->nullable();
            $table->date('deceased_at')->nullable();
            $table->text('deceased_note')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('full_name');
            $table->index('resident_status');
            $table->index('gender');
            $table->index('birth_date');
            $table->index('rt_id');
            $table->index('religion_id');
            $table->index('education_id');
            $table->index('occupation_id');
            $table->index(['kk_id', 'resident_status']);
            $table->index('blood_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('penduduk');
    }
};
