<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\SipetaStatsOverview;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Phase 4.1 — dashboard foundation.
 *
 * Custom dashboard page so the placeholder KPI cards (SipetaStatsOverview)
 * are explicitly mounted. No charts/analytics/exports yet.
 */
class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            SipetaStatsOverview::class,
        ];
    }
}
