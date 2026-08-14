<?php

namespace Tests\Feature\Phase4;

use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Filament\Widgets\SipetaStatsOverview;
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
 * Verifies the FOUR population-statistic cards render with their labels and
 * that the values reflect a controlled set of records in the database.
 * This is the production design (see Dashboard.php + SipetaStatsOverview):
 *   1. Kartu Keluarga
 *   2. Penduduk Aktif
 *   3. Belum Menikah
 *   4. Jumlah RT
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
            ->assertSee('Kartu Keluarga')
            ->assertSee('Penduduk Aktif')
            ->assertSee('Belum Menikah')
            ->assertSee('Jumlah RT');
    }

    public function test_dashboard_kpi_values_match_database(): void
    {
        $rt = Rt::factory()->create();
        $kk = KartuKeluarga::factory()->create(['rt_id' => $rt->id]);

        // 3 active residents, all belum menikah.
        Penduduk::factory()->count(3)->create([
            'kk_id' => $kk->id,
            'rt_id' => $rt->id,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'marital_status' => MaritalStatus::BELUM_KAWIN->value,
        ]);

        // 1 deceased resident, already married -> excluded from both
        // "Penduduk Aktif" and "Belum Menikah".
        Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'rt_id' => $rt->id,
            'resident_status' => ResidentStatus::MENINGGAL->value,
            'marital_status' => MaritalStatus::KAWIN->value,
        ]);

        $stats = collect(invade(new SipetaStatsOverview)->getStats())
            ->keyBy(fn (Stat $stat) => $stat->getLabel());

        // 1. Kartu Keluarga
        $this->assertSame('1', $stats['Kartu Keluarga']->getValue());

        // 2. Penduduk Aktif (ACTIVE only)
        $this->assertSame('3', $stats['Penduduk Aktif']->getValue());

        // 3. Belum Menikah (marital_status = BELUM_KAWIN)
        $this->assertSame('3', $stats['Belum Menikah']->getValue());

        // 4. Jumlah RT
        $this->assertSame('1', $stats['Jumlah RT']->getValue());
    }
}
