<?php

namespace Tests\Feature\Phase4;

use App\Enums\ResidentStatus;
use App\Filament\Widgets\PendudukPerAgamaChart;
use App\Filament\Widgets\PendudukPerGenderChart;
use App\Filament\Widgets\PendudukPerLingkunganChart;
use App\Filament\Widgets\PendudukPerPekerjaanChart;
use App\Filament\Widgets\PendudukPerPendidikanChart;
use App\Filament\Widgets\PendudukPerRTChart;
use App\Filament\Widgets\PendudukPerStatusChart;
use App\Models\AreaUnit;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\Occupation;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE UI-1 — dashboard charts.
 *
 * Verifies the dashboard renders the production chart set — Gender (doughnut,
 * heading "Jenis Kelamin") and Resident Status (pie, heading "Status
 * Penduduk") as the two primary pies, plus the horizontal-bar distributions
 * (Pekerjaan / Pendidikan / Agama) and vertical-bar distributions (RT /
 * Lingkungan) — and that every chart reflects the data in the database.
 *
 * Note: Gender and Resident Status pies summarise the whole resident list
 * (their purpose is the population's gender/status make-up, not the active
 * subset), whereas the distribution bars count active residents only.
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
            ->assertSee('Jenis Kelamin')
            ->assertSee('Penduduk per Pekerjaan')
            ->assertSee('Penduduk per Lingkungan')
            ->assertSee('Pendidikan Penduduk');
    }

    public function test_gender_and_status_are_pie_charts(): void
    {
        $this->assertSame('doughnut', invade(new PendudukPerGenderChart)->getType());
        $this->assertSame('pie', invade(new PendudukPerStatusChart)->getType());
    }

    public function test_occupation_education_religion_are_horizontal_bars(): void
    {
        foreach (['bar' => [
            PendudukPerPekerjaanChart::class,
            PendudukPerPendidikanChart::class,
            PendudukPerAgamaChart::class,
        ]] as $type => $classes) {
            foreach ($classes as $class) {
                $this->assertSame($type, invade(new $class)->getType());
                $options = invade(new $class)->getOptions();
                $this->assertArrayHasKey('indexAxis', $options, "{$class} must be a horizontal bar (indexAxis)");
                $this->assertSame('y', $options['indexAxis']);
            }
        }
    }

    public function test_gender_pie_counts_all_residents(): void
    {
        $kk = KartuKeluarga::factory()->create();

        // Male: 3 (2 active + 1 moved), Female: 2 (active).
        Penduduk::factory()->create(['kk_id' => $kk->id, 'gender' => 'LAKI_LAKI', 'resident_status' => ResidentStatus::ACTIVE->value]);
        Penduduk::factory()->create(['kk_id' => $kk->id, 'gender' => 'LAKI_LAKI', 'resident_status' => ResidentStatus::ACTIVE->value]);
        Penduduk::factory()->create(['kk_id' => $kk->id, 'gender' => 'LAKI_LAKI', 'resident_status' => ResidentStatus::PINDAH->value]);
        Penduduk::factory()->create(['kk_id' => $kk->id, 'gender' => 'PEREMPUAN', 'resident_status' => ResidentStatus::ACTIVE->value]);
        Penduduk::factory()->create(['kk_id' => $kk->id, 'gender' => 'PEREMPUAN', 'resident_status' => ResidentStatus::MENINGGAL->value]);

        $data = invade(new PendudukPerGenderChart)->getData();
        $this->assertSame(['Laki-laki', 'Perempuan'], $data['labels']);
        $this->assertSame([3, 2], $data['datasets'][0]['data']);
    }

    public function test_status_pie_counts_all_residents(): void
    {
        $kk = KartuKeluarga::factory()->create();

        Penduduk::factory()->create(['kk_id' => $kk->id, 'resident_status' => ResidentStatus::ACTIVE->value]);
        Penduduk::factory()->create(['kk_id' => $kk->id, 'resident_status' => ResidentStatus::ACTIVE->value]);
        Penduduk::factory()->create(['kk_id' => $kk->id, 'resident_status' => ResidentStatus::PINDAH->value]);
        Penduduk::factory()->create(['kk_id' => $kk->id, 'resident_status' => ResidentStatus::MENINGGAL->value]);

        $data = invade(new PendudukPerStatusChart)->getData();
        $this->assertSame(['Aktif', 'Pindah', 'Meninggal'], $data['labels']);
        $this->assertSame([2, 1, 1], $data['datasets'][0]['data']);
    }

    public function test_distribution_bar_charts_match_database_and_count_active_residents_only(): void
    {
        $area1 = AreaUnit::factory()->create(['name' => 'Lingkungan I', 'type' => 'lingkungan']);
        $area2 = AreaUnit::factory()->create(['name' => 'Lingkungan II', 'type' => 'lingkungan']);
        $rt1 = Rt::factory()->create(['area_unit_id' => $area1->id, 'number' => '01']);
        $rt2 = Rt::factory()->create(['area_unit_id' => $area1->id, 'number' => '02']);
        $rt3 = Rt::factory()->create(['area_unit_id' => $area2->id, 'number' => '03']);

        $petani = Occupation::factory()->create(['name' => 'Petani']);
        $pedagang = Occupation::factory()->create(['name' => 'Pedagang']);
        $pns = Occupation::factory()->create(['name' => 'PNS']);

        $sd = Education::factory()->create(['name' => 'SD']);
        $sma = Education::factory()->create(['name' => 'SMA']);

        $islam = Religion::factory()->create(['name' => 'Islam']);
        $katolik = Religion::factory()->create(['name' => 'Katolik']);

        $kk1 = KartuKeluarga::factory()->create(['rt_id' => $rt1->id]);
        $kk2 = KartuKeluarga::factory()->create(['rt_id' => $rt3->id]);

        // KK is the source of truth for a resident's RT (Penduduk::booted()
        // syncs rt_id from the KK on every save), so a resident's RT is fixed
        // by the KK it belongs to — not by a passed rt_id.
        $addPenduduk = static function (int $kkId, array $attrs): void {
            Penduduk::factory()->create(array_merge(['kk_id' => $kkId], $attrs));
        };

        // RT 01 (Lingkungan I): 3 active -> Petani x2, Pedagang x1 (+ 1 PINDAH Petani excluded)
        $addPenduduk($kk1->id, ['occupation_id' => $petani->id, 'education_id' => $sd->id, 'religion_id' => $islam->id, 'resident_status' => ResidentStatus::ACTIVE->value]);
        $addPenduduk($kk1->id, ['occupation_id' => $petani->id, 'education_id' => $sd->id, 'religion_id' => $islam->id, 'resident_status' => ResidentStatus::ACTIVE->value]);
        $addPenduduk($kk1->id, ['occupation_id' => $pedagang->id, 'education_id' => $sd->id, 'religion_id' => $islam->id, 'resident_status' => ResidentStatus::ACTIVE->value]);
        $addPenduduk($kk1->id, ['occupation_id' => $petani->id, 'education_id' => $sma->id, 'religion_id' => $katolik->id, 'resident_status' => ResidentStatus::PINDAH->value]);

        // RT 02: no residents -> 0
        // RT 03 (Lingkungan II): 3 active -> Petani x1, Pedagang x1, PNS x1 (+ 1 MENINGGAL Pedagang excluded)
        $addPenduduk($kk2->id, ['occupation_id' => $petani->id, 'education_id' => $sma->id, 'religion_id' => $katolik->id, 'resident_status' => ResidentStatus::ACTIVE->value]);
        $addPenduduk($kk2->id, ['occupation_id' => $pedagang->id, 'education_id' => $sma->id, 'religion_id' => $katolik->id, 'resident_status' => ResidentStatus::ACTIVE->value]);
        $addPenduduk($kk2->id, ['occupation_id' => $pns->id, 'education_id' => $sma->id, 'religion_id' => $katolik->id, 'resident_status' => ResidentStatus::ACTIVE->value]);
        $addPenduduk($kk2->id, ['occupation_id' => $pedagang->id, 'education_id' => $sd->id, 'religion_id' => $islam->id, 'resident_status' => ResidentStatus::MENINGGAL->value]);

        // Per RT: every RT shown, ordered by area label then number, labelled
        // "{areaUnit.display_label} / RT {number}".
        $rtData = invade(new PendudukPerRTChart)->getData();
        $this->assertSame(['Lingkungan I / RT 01', 'Lingkungan I / RT 02', 'Lingkungan II / RT 03'], $rtData['labels']);
        $this->assertSame([3, 0, 3], $rtData['datasets'][0]['data']);

        // Per Lingkungan: aggregated through RT -> area unit, zero-padded.
        $areaData = invade(new PendudukPerLingkunganChart)->getData();
        $this->assertSame(['Lingkungan I', 'Lingkungan II'], $areaData['labels']);
        $this->assertSame([3, 3], $areaData['datasets'][0]['data']);

        // Per Occupation: active only, count desc, only occupations with >= 1.
        $occupationData = invade(new PendudukPerPekerjaanChart)->getData();
        $this->assertSame(['Petani', 'Pedagang', 'PNS'], $occupationData['labels']);
        $this->assertSame([3, 2, 1], $occupationData['datasets'][0]['data']);

        // Per Education: active only, desc.
        $educationData = invade(new PendudukPerPendidikanChart)->getData();
        $this->assertSame([3, 3], array_values($educationData['datasets'][0]['data']));
        $this->assertCount(2, $educationData['labels']);

        // Per Agama: active only, desc.
        $religionData = invade(new PendudukPerAgamaChart)->getData();
        $this->assertSame([3, 3], array_values($religionData['datasets'][0]['data']));
        $this->assertCount(2, $religionData['labels']);

        // All three horizontal bars use the expected categorical palette.
        foreach ([PendudukPerPekerjaanChart::class, PendudukPerPendidikanChart::class, PendudukPerAgamaChart::class] as $class) {
            $opts = invade(new $class)->getOptions();
            $this->assertSame('y', $opts['indexAxis']);
        }
    }
}
