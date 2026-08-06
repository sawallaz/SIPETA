<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PendudukPerLingkunganChart;
use App\Filament\Widgets\PendudukPerPekerjaanChart;
use App\Filament\Widgets\PendudukPerRTChart;
use App\Filament\Widgets\QuickActionsWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\SipetaStatsOverview;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Phase 4.1 — dashboard foundation.
 *
 * Custom dashboard page mounting the KPI cards (SipetaStatsOverview) and,
 * since Phase 4.3, the three distribution charts (per RT, per Lingkungan,
 * per Pekerjaan), since Phase 4.4 the recent-activity list, and since
 * Phase 4.5 the quick-actions shortcuts. Charts reflect active residents
 * only per docs/REQUIREMENTS.md §5.5.
 *
 * Phase 4.6 (polish) — widgets are ordered operator-first: Quick Actions
 * on top (most frequent workflows: Tambah / Data KK & Penduduk), then KPI
 * cards, then the three charts, and Recent Activity last (reference feed).
 * Every widget spans the full dashboard width (`columnSpan = 'full'`),
 * giving the KPI cards, charts, and action cards room to breathe.
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            QuickActionsWidget::class,
            SipetaStatsOverview::class,
            PendudukPerRTChart::class,
            PendudukPerLingkunganChart::class,
            PendudukPerPekerjaanChart::class,
            RecentActivityWidget::class,
        ];
    }
}
