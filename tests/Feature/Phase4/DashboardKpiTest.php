<?php

namespace Tests\Feature\Phase4;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Filament\Widgets\SipetaStatsOverview;
use App\Models\AreaUnit;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\Rt;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4.2 — dashboard KPI enhancement.
 *
 * Verifies the eleven population-statistic cards render with their labels and
 * that the values reflect a controlled set of records in the database.
 */
class DashboardKpiTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_dashboard_shows_all_kpi_card_labels(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Total Kartu Keluarga')
            ->assertSee('Total Kepala Keluarga')
            ->assertSee('Total Anggota Keluarga')
            ->assertSee('Total Penduduk')
            ->assertSee('Laki-laki')
            ->assertSee('Perempuan')
            ->assertSee('Total RT')
            ->assertSee('Total RW / Lingkungan')
            ->assertSee('Penduduk Aktif')
            ->assertSee('Penduduk Pindah')
            ->assertSee('Penduduk Meninggal');
    }

    public function test_dashboard_kpi_values_match_database(): void
    {
        $kk = KartuKeluarga::factory()->create();
        $area = AreaUnit::factory()->create();
        $rt = Rt::factory()->create(['area_unit_id' => $area->id]);

        $addPenduduk = static function (string $gender, string $familyRelation, string $residentStatus) use ($kk, $rt): void {
            Penduduk::factory()->create([
                'kk_id' => $kk->id,
                'rt_id' => $rt->id,
                'gender' => $gender,
                'family_relation' => $familyRelation,
                'resident_status' => $residentStatus,
            ]);
        };

        $addPenduduk(Gender::LAKI_LAKI->value, FamilyRelation::KEPALA_KELUARGA->value, ResidentStatus::ACTIVE->value);
        $addPenduduk(Gender::PEREMPUAN->value, FamilyRelation::ISTRI->value, ResidentStatus::ACTIVE->value);
        $addPenduduk(Gender::PEREMPUAN->value, FamilyRelation::ANAK->value, ResidentStatus::PINDAH->value);
        $addPenduduk(Gender::LAKI_LAKI->value, FamilyRelation::ANAK->value, ResidentStatus::MENINGGAL->value);

        $stats = collect(invade(new SipetaStatsOverview)->getStats())
            ->keyBy(fn (Stat $stat) => $stat->getLabel());

        // Kartu Keluarga
        $this->assertSame('1', $stats['Total Kartu Keluarga']->getValue());

        // Penduduk role breakdown (Kepala + Anggota partition the total)
        $this->assertSame('1', $stats['Total Kepala Keluarga']->getValue());
        $this->assertSame('3', $stats['Total Anggota Keluarga']->getValue());

        // Penduduk totals and gender
        $this->assertSame('4', $stats['Total Penduduk']->getValue());
        $this->assertSame('2', $stats['Laki-laki']->getValue());
        $this->assertSame('2', $stats['Perempuan']->getValue());
        $this->assertSame('50% dari total penduduk', $stats['Laki-laki']->getDescription());
        $this->assertSame('50% dari total penduduk', $stats['Perempuan']->getDescription());

        // Wilayah
        $this->assertSame('1', $stats['Total RT']->getValue());
        $this->assertSame('1', $stats['Total RW / Lingkungan']->getValue());

        // Status breakdown
        $this->assertSame('2', $stats['Penduduk Aktif']->getValue());
        $this->assertSame('1', $stats['Penduduk Pindah']->getValue());
        $this->assertSame('1', $stats['Penduduk Meninggal']->getValue());
    }
}
