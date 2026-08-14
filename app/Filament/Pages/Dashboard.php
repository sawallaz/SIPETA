<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PendudukPerGenderChart;
use App\Filament\Widgets\PendudukPerLingkunganChart;
use App\Filament\Widgets\PendudukPerPekerjaanChart;
use App\Filament\Widgets\PendudukPerPendidikanChart;
use App\Filament\Widgets\QuickActionsWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\SipetaStatsOverview;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            SipetaStatsOverview::class,

            QuickActionsWidget::class,

            PendudukPerGenderChart::class,
            PendudukPerPekerjaanChart::class,
            PendudukPerLingkunganChart::class,
            PendudukPerPendidikanChart::class,

            RecentActivityWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return [
            'default' => 1,
            'sm' => 1,
            'md' => 2,
            'lg' => 2,
            'xl' => 2,
            '2xl' => 2,
        ];
    }
}
