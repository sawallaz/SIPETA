<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PendudukPerLingkunganChart;
use App\Filament\Widgets\PendudukPerPekerjaanChart;
use App\Filament\Widgets\PendudukPerRTChart;
use App\Filament\Widgets\SipetaStatsOverview;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Phase 4.1 — dashboard foundation.
 *
 * Custom dashboard page mounting the KPI cards (SipetaStatsOverview) and,
 * since Phase 4.3, the three distribution charts (per RT, per Lingkungan,
 * per Pekerjaan). Charts reflect active residents only per
 * docs/REQUIREMENTS.md §5.5.
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            SipetaStatsOverview::class,
            PendudukPerRTChart::class,
            PendudukPerLingkunganChart::class,
            PendudukPerPekerjaanChart::class,
        ];
    }
}
