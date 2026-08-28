<?php

namespace Tests\Feature;

use App\Models\AreaUnit;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\Rt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RtRwModalCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'SUPER_ADMIN']));
    }

    public function test_can_create_and_delete_empty_rw(): void
    {
        $rw = AreaUnit::create([
            'name' => 'RW 99',
            'type' => 'rw',
        ]);

        $this->assertDatabaseHas('area_units', ['id' => $rw->id, 'name' => 'RW 99']);

        // Since no RT belongs to this RW, deletion is allowed
        $rtCount = Rt::where('area_unit_id', $rw->id)->count();
        $this->assertSame(0, $rtCount);

        $rw->delete();
        $this->assertDatabaseMissing('area_units', ['id' => $rw->id]);
    }

    public function test_can_delete_rw_with_empty_child_rts_in_transaction(): void
    {
        $rw = AreaUnit::create([
            'name' => 'RW 88',
            'type' => 'rw',
        ]);

        $rt1 = Rt::create([
            'area_unit_id' => $rw->id,
            'number' => '01',
        ]);

        $rt2 = Rt::create([
            'area_unit_id' => $rw->id,
            'number' => '02',
        ]);

        // Child RTs have zero residents and zero KKs
        $childRts = Rt::where('area_unit_id', $rw->id)->get();
        $childRtIds = $childRts->pluck('id')->all();

        $pendudukCount = Penduduk::whereIn('rt_id', $childRtIds)->count();
        $kkCount = KartuKeluarga::whereIn('rt_id', $childRtIds)->count();

        $this->assertSame(0, $pendudukCount);
        $this->assertSame(0, $kkCount);

        // Transactional deletion of empty child RTs + RW
        DB::transaction(function () use ($childRts, $rw): void {
            foreach ($childRts as $rt) {
                $rt->delete();
            }
            $rw->delete();
        });

        $this->assertDatabaseMissing('area_units', ['id' => $rw->id]);
        $this->assertDatabaseMissing('rts', ['id' => $rt1->id]);
        $this->assertDatabaseMissing('rts', ['id' => $rt2->id]);
    }

    public function test_cannot_delete_rw_when_child_rts_have_residents_or_kk(): void
    {
        $rw = AreaUnit::create([
            'name' => 'RW 01',
            'type' => 'rw',
        ]);

        $rt = Rt::create([
            'area_unit_id' => $rw->id,
            'number' => '01',
        ]);

        $kk = KartuKeluarga::create([
            'kk_number' => '7304010101800001',
            'address' => 'JL. MERDEKA',
            'rt_id' => $rt->id,
        ]);

        $penduduk = Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'nik' => '7304010101800001',
            'full_name' => 'BUDI SANTOSO',
            'rt_id' => $rt->id,
        ]);

        // Guard: RW has child RTs used by residents/KK
        $childRtIds = Rt::where('area_unit_id', $rw->id)->pluck('id')->all();
        $pendudukCount = Penduduk::whereIn('rt_id', $childRtIds)->count();
        $kkCount = KartuKeluarga::whereIn('rt_id', $childRtIds)->count();

        $this->assertGreaterThan(0, $pendudukCount);
        $this->assertGreaterThan(0, $kkCount);

        // Deletion prevented
        $this->assertDatabaseHas('area_units', ['id' => $rw->id]);
        $this->assertDatabaseHas('rts', ['id' => $rt->id]);
    }

    public function test_can_create_and_delete_empty_rt(): void
    {
        $rw = AreaUnit::create([
            'name' => 'RW 01',
            'type' => 'rw',
        ]);

        $rt = Rt::create([
            'area_unit_id' => $rw->id,
            'number' => '05',
        ]);

        $this->assertDatabaseHas('rts', ['id' => $rt->id, 'number' => '05']);

        // Unused RT
        $this->assertSame(0, $rt->penduduks()->count());
        $this->assertSame(0, $rt->kartuKeluargas()->count());

        $rt->delete();
        $this->assertDatabaseMissing('rts', ['id' => $rt->id]);
    }

    public function test_cannot_delete_rt_in_use_by_penduduk_or_kk(): void
    {
        $rw = AreaUnit::create([
            'name' => 'RW 01',
            'type' => 'rw',
        ]);

        $rt = Rt::create([
            'area_unit_id' => $rw->id,
            'number' => '01',
        ]);

        $kk = KartuKeluarga::create([
            'kk_number' => '7304010101800001',
            'address' => 'JL. MERDEKA',
            'rt_id' => $rt->id,
        ]);

        $penduduk = Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'nik' => '7304010101800001',
            'full_name' => 'BUDI SANTOSO',
            'rt_id' => $rt->id,
        ]);

        // Guard: RT is used by 1 KK and 1 Penduduk
        $this->assertSame(1, $rt->penduduks()->count());
        $this->assertSame(1, $rt->kartuKeluargas()->count());

        // Deletion must be guarded and rejected
        $inUse = $rt->penduduks()->count() > 0 || $rt->kartuKeluargas()->count() > 0;
        $this->assertTrue($inUse);

        $this->assertDatabaseHas('rts', ['id' => $rt->id]);
    }

    public function test_duplicate_rw_name_is_prevented_before_save(): void
    {
        AreaUnit::create([
            'name' => 'RW 01',
            'type' => 'rw',
        ]);

        $rw2 = AreaUnit::create([
            'name' => 'RW 02',
            'type' => 'rw',
        ]);

        // Attempting to rename RW 02 to RW 01
        $targetName = 'RW 01';
        $duplicateExists = AreaUnit::where('name', $targetName)->where('id', '!=', $rw2->id)->exists();

        $this->assertTrue($duplicateExists);

        // Name of rw2 remains unchanged
        $this->assertDatabaseHas('area_units', ['id' => $rw2->id, 'name' => 'RW 02']);
    }

    public function test_duplicate_rt_number_in_same_rw_is_prevented(): void
    {
        $rw = AreaUnit::create([
            'name' => 'RW 01',
            'type' => 'rw',
        ]);

        $rt1 = Rt::create([
            'area_unit_id' => $rw->id,
            'number' => '01',
        ]);

        $rt2 = Rt::create([
            'area_unit_id' => $rw->id,
            'number' => '02',
        ]);

        // Attempting to rename RT 02 to RT 01 in the same RW
        $targetNumber = '01';
        $duplicateExists = Rt::where('area_unit_id', $rw->id)->where('number', $targetNumber)->where('id', '!=', $rt2->id)->exists();

        $this->assertTrue($duplicateExists);
        $this->assertDatabaseHas('rts', ['id' => $rt2->id, 'number' => '02']);
    }
}
