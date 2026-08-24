<?php

namespace Tests\Feature\Phase4;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\PendudukPerAgamaChart;
use App\Filament\Widgets\PendudukPerGenderChart;
use App\Filament\Widgets\PendudukPerKelompokUmurChart;
use App\Filament\Widgets\PendudukPerLingkunganChart;
use App\Filament\Widgets\PendudukPerPekerjaanChart;
use App\Filament\Widgets\PendudukPerPendidikanChart;
use App\Filament\Widgets\PendudukPerPerkawinanChart;
use App\Filament\Widgets\PendudukPerRTChart;
use App\Filament\Widgets\PendudukPerStatusChart;
use App\Filament\Widgets\QuickActionsWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\SipetaStatsOverview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PHASE UI-1 — dashboard redesign visual structure.
 */
class DashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_dashboard_orders_widgets_product_first(): void
    {
        $this->assertSame(
            [
                SipetaStatsOverview::class,
                QuickActionsWidget::class,
                PendudukPerGenderChart::class,
                PendudukPerKelompokUmurChart::class,
                PendudukPerStatusChart::class,
                PendudukPerPerkawinanChart::class,
                PendudukPerPendidikanChart::class,
                PendudukPerPekerjaanChart::class,
                PendudukPerAgamaChart::class,
                PendudukPerRTChart::class,
                PendudukPerLingkunganChart::class,
                RecentActivityWidget::class,
            ],
            invade(new Dashboard)->getWidgets(),
        );
    }

    public function test_primary_columns_and_recent_activity_span_the_full_width(): void
    {
        foreach ([
            SipetaStatsOverview::class,
            QuickActionsWidget::class,
            RecentActivityWidget::class,
        ] as $widgetClass) {
            $this->assertSame(
                'full',
                (new $widgetClass)->getColumnSpan(),
                "{$widgetClass} should span the full dashboard width",
            );
        }
    }

    public function test_chart_widgets_use_compact_column_spans(): void
    {
        foreach ([
            PendudukPerGenderChart::class,
            PendudukPerKelompokUmurChart::class,
            PendudukPerStatusChart::class,
            PendudukPerPerkawinanChart::class,
            PendudukPerPekerjaanChart::class,
            PendudukPerPendidikanChart::class,
            PendudukPerAgamaChart::class,
            PendudukPerRTChart::class,
            PendudukPerLingkunganChart::class,
        ] as $widgetClass) {
            $this->assertNotSame(
                'full',
                (new $widgetClass)->getColumnSpan(),
                "{$widgetClass} must use a compact column span",
            );
        }
    }

    public function test_dashboard_renders_after_redesign(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Akses Cepat')
            ->assertSee('Aktivitas Terbaru')
            ->assertSee('Jenis Kelamin');
    }
}
