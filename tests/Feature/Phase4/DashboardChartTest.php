<?php

namespace Tests\Feature\Phase4;

use App\Enums\ResidentStatus;
use App\Filament\Widgets\PendudukPerLingkunganChart;
use App\Filament\Widgets\PendudukPerPekerjaanChart;
use App\Filament\Widgets\PendudukPerRTChart;
use App\Models\AreaUnit;
use App\Models\KartuKeluarga;
use App\Models\Occupation;
use App\Models\Penduduk;
use App\Models\Rt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4.3 — dashboard charts.
 *
 * Verifies the three charts (per RT, per Lingkungan, per Pekerjaan) render
 * with their headings on the dashboard and that every chart reflects ONLY
 * active residents (docs/REQUIREMENTS.md §5.5 "Charts reflect active
 * residents only"), matching a controlled set of database records.
 */
class DashboardChartTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_dashboard_shows_all_chart_headings(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Penduduk per RT')
            ->assertSee('Penduduk per Lingkungan')
            ->assertSee('Penduduk per Pekerjaan');
    }

    public function test_chart_data_matches_database_and_counts_active_residents_only(): void
    {
        $area1 = AreaUnit::factory()->create(['name' => 'Lingkungan I']);
        $area2 = AreaUnit::factory()->create(['name' => 'Lingkungan II']);
        $rt1 = Rt::factory()->create(['area_unit_id' => $area1->id, 'number' => '01']);
        $rt2 = Rt::factory()->create(['area_unit_id' => $area1->id, 'number' => '02']);
        $rt3 = Rt::factory()->create(['area_unit_id' => $area2->id, 'number' => '03']);

        $petani = Occupation::factory()->create(['name' => 'Petani']);
        $pedagang = Occupation::factory()->create(['name' => 'Pedagang']);
        $pns = Occupation::factory()->create(['name' => 'PNS']);

        $kk = KartuKeluarga::factory()->create();

        $addPenduduk = static function (int $rtId, int $occupationId, string $status) use ($kk): void {
            Penduduk::factory()->create([
                'kk_id' => $kk->id,
                'rt_id' => $rtId,
                'occupation_id' => $occupationId,
                'resident_status' => $status,
            ]);
        };

        // RT 01 (Lingkungan I): 3 active -> Petani x2, Pedagang x1 (+ 1 PINDAH Petani excluded)
        $addPenduduk($rt1->id, $petani->id, ResidentStatus::ACTIVE->value);
        $addPenduduk($rt1->id, $petani->id, ResidentStatus::ACTIVE->value);
        $addPenduduk($rt1->id, $pedagang->id, ResidentStatus::ACTIVE->value);
        $addPenduduk($rt1->id, $petani->id, ResidentStatus::PINDAH->value);

        // RT 02 (Lingkungan I): no residents -> 0
        // RT 03 (Lingkungan II): 3 active -> Petani x1, Pedagang x1, PNS x1 (+ 1 MENINGGAL Pedagang excluded)
        $addPenduduk($rt3->id, $petani->id, ResidentStatus::ACTIVE->value);
        $addPenduduk($rt3->id, $pedagang->id, ResidentStatus::ACTIVE->value);
        $addPenduduk($rt3->id, $pns->id, ResidentStatus::ACTIVE->value);
        $addPenduduk($rt3->id, $pedagang->id, ResidentStatus::MENINGGAL->value);

        // Per RT: every RT shown, zero-padded, natural number order.
        $rtData = invade(new PendudukPerRTChart)->getData();
        $this->assertSame(['RT 01', 'RT 02', 'RT 03'], $rtData['labels']);
        $this->assertSame([3, 0, 3], $rtData['datasets'][0]['data']);

        // Per Lingkungan: aggregated through RT -> area unit, zero-padded.
        $areaData = invade(new PendudukPerLingkunganChart)->getData();
        $this->assertSame(['Lingkungan I', 'Lingkungan II'], $areaData['labels']);
        $this->assertSame([3, 3], $areaData['datasets'][0]['data']);

        // Per Pekerjaan: active only, count desc, only occupations with >= 1.
        $occupationData = invade(new PendudukPerPekerjaanChart)->getData();
        $this->assertSame(['Petani', 'Pedagang', 'PNS'], $occupationData['labels']);
        $this->assertSame([3, 2, 1], $occupationData['datasets'][0]['data']);
    }
}
