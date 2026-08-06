<?php

namespace Tests\Feature\Phase4;

use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\PendudukPerLingkunganChart;
use App\Filament\Widgets\PendudukPerPekerjaanChart;
use App\Filament\Widgets\PendudukPerRTChart;
use App\Filament\Widgets\QuickActionsWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\SipetaStatsOverview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 4.6 — dashboard polish (visual structure).
 *
 * Locks in the polished dashboard layout: operator-first widget ordering
 * (Quick Actions on top, Recent Activity last) and every widget spanning
 * the full dashboard width instead of the Filament default half-width.
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

    public function test_dashboard_orders_widgets_operator_first(): void
    {
        $this->assertSame(
            [
                QuickActionsWidget::class,
                SipetaStatsOverview::class,
                PendudukPerRTChart::class,
                PendudukPerLingkunganChart::class,
                PendudukPerPekerjaanChart::class,
                RecentActivityWidget::class,
            ],
            invade(new Dashboard)->getWidgets(),
        );
    }

    public function test_every_dashboard_widget_spans_the_full_width(): void
    {
        foreach (invade(new Dashboard)->getWidgets() as $widgetClass) {
            $widget = new $widgetClass;

            $this->assertSame(
                'full',
                $widget->getColumnSpan(),
                "{$widgetClass} should span the full dashboard width after Phase 4.6 polish",
            );
        }
    }

    public function test_dashboard_renders_after_layout_polish(): void
    {
        $this->get('/admin')
            ->assertOk()
            ->assertSee('Aksi Cepat')          // quick actions (now first)
            ->assertSee('Aktivitas Terbaru');   // recent activity (now last)
    }
}
