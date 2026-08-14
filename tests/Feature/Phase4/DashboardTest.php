<?php

namespace Tests\Feature\Phase4;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4.1 — dashboard foundation smoke test.
 *
 * Verifies the dashboard page renders and that the four production KPI cards
 * (Kartu Keluarga, Penduduk Aktif, Belum Menikah, Jumlah RT) appear with
 * their labels. No statistics/charts yet.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_dashboard_page_renders(): void
    {
        $this->get('/admin')
            ->assertOk();
    }

    public function test_dashboard_shows_kpi_card_labels(): void
    {
        $this->get('/admin')
            ->assertSee('Kartu Keluarga')
            ->assertSee('Penduduk Aktif')
            ->assertSee('Belum Menikah')
            ->assertSee('Jumlah RT');
    }
}
