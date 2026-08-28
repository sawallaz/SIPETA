<?php

namespace Tests\Feature;

use App\Filament\Resources\Rts\Pages\ListRts;
use App\Models\AreaUnit;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\Rt;
use App\Models\User;
use Database\Seeders\SystemReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RtResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SystemReferenceSeeder::class);
    }

    public function test_authenticated_user_can_view_rt_list(): void
    {
        $user = User::factory()->create();
        $rw = AreaUnit::create(['name' => 'RW 01', 'type' => 'rw', 'code' => '01']);
        $rt = Rt::create(['number' => '01', 'area_unit_id' => $rw->id]);

        $this->actingAs($user)
            ->get(ListRts::getUrl())
            ->assertOk()
            ->assertSee('Master RT / RW')
            ->assertSee('RW 01');
    }

    public function test_can_create_new_rt_via_modal(): void
    {
        $user = User::factory()->create();
        $rw = AreaUnit::create(['name' => 'RW 05', 'type' => 'rw', 'code' => '05']);

        Livewire::actingAs($user)
            ->test(ListRts::class)
            ->callAction('create', data: [
                'number' => '05',
                'area_unit_id' => $rw->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('rts', [
            'number' => '05',
            'area_unit_id' => $rw->id,
        ]);
    }

    public function test_can_edit_rt_via_modal(): void
    {
        $user = User::factory()->create();
        $rw1 = AreaUnit::create(['name' => 'RW 01', 'type' => 'rw', 'code' => '01']);
        $rw2 = AreaUnit::create(['name' => 'RW 02', 'type' => 'rw', 'code' => '02']);
        $rt = Rt::create(['number' => '01', 'area_unit_id' => $rw1->id]);

        Livewire::actingAs($user)
            ->test(ListRts::class)
            ->callTableAction('edit', $rt, data: [
                'number' => '02',
                'area_unit_id' => $rw2->id,
            ])
            ->assertHasNoTableActionErrors();

        $rt->refresh();
        $this->assertSame('02', $rt->number);
        $this->assertSame($rw2->id, $rt->area_unit_id);
    }

    public function test_cannot_delete_rt_if_in_use_by_penduduk(): void
    {
        $user = User::factory()->create();
        $rw = AreaUnit::create(['name' => 'RW 01', 'type' => 'rw', 'code' => '01']);
        $rt = Rt::create(['number' => '01', 'area_unit_id' => $rw->id]);
        $kk = KartuKeluarga::create(['kk_number' => '7304010101809999', 'address' => 'Jl. Mawar', 'rt_id' => $rt->id]);

        Penduduk::create([
            'kk_id' => $kk->id,
            'nik' => '7304010101800001',
            'full_name' => 'Budi Santoso',
            'gender' => 'LAKI_LAKI',
            'birth_place' => 'Barru',
            'birth_date' => '1980-01-01',
            'religion_id' => 1,
            'education_id' => 1,
            'occupation_id' => 1,
            'marital_status' => 'KAWIN',
            'family_relation' => 'KEPALA_KELUARGA',
            'blood_type' => 'O',
            'resident_status' => 'ACTIVE',
            'rt_id' => $rt->id,
        ]);

        Livewire::actingAs($user)
            ->test(ListRts::class)
            ->callTableAction('delete', $rt);

        // Assert record is still in database (not deleted)
        $this->assertDatabaseHas('rts', ['id' => $rt->id]);
    }

    public function test_can_delete_unused_rt(): void
    {
        $user = User::factory()->create();
        $rw = AreaUnit::create(['name' => 'RW 09', 'type' => 'rw', 'code' => '09']);
        $rt = Rt::create(['number' => '99', 'area_unit_id' => $rw->id]);

        Livewire::actingAs($user)
            ->test(ListRts::class)
            ->callTableAction('delete', $rt);

        $this->assertDatabaseMissing('rts', ['id' => $rt->id]);
    }
}
