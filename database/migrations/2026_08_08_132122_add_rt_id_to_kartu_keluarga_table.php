<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kartu_keluarga', function (Blueprint $table) {
            $table->foreignId('rt_id')
                ->nullable()
                ->after('address')
                ->constrained('rts')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
        });

        // Pindahkan wilayah lama dari kepala keluarga/anggota ke KK.
        DB::table('kartu_keluarga')
            ->select('id')
            ->orderBy('id')
            ->get()
            ->each(function ($kk): void {
                $rtId = DB::table('penduduk')
                    ->where('kk_id', $kk->id)
                    ->where('family_relation', 'KEPALA_KELUARGA')
                    ->whereNotNull('rt_id')
                    ->value('rt_id');

                if ($rtId === null) {
                    $rtId = DB::table('penduduk')
                        ->where('kk_id', $kk->id)
                        ->whereNotNull('rt_id')
                        ->orderBy('id')
                        ->value('rt_id');
                }

                if ($rtId !== null) {
                    DB::table('kartu_keluarga')
                        ->where('id', $kk->id)
                        ->update(['rt_id' => $rtId]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('kartu_keluarga', function (Blueprint $table) {
            $table->dropForeign(['rt_id']);
            $table->dropColumn('rt_id');
        });
    }
};
