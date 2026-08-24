<?php

namespace App\Filament\Pages;

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
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            // 1. KPI
            SipetaStatsOverview::class,

            // 2. Akses Cepat (wajib tepat di bawah KPI)
            QuickActionsWidget::class,

            // 3. Statistik Demografi
            PendudukPerGenderChart::class,
            PendudukPerKelompokUmurChart::class,
            PendudukPerStatusChart::class,
            PendudukPerPerkawinanChart::class,

            // 4. Statistik Sosial
            PendudukPerPendidikanChart::class,
            PendudukPerPekerjaanChart::class,
            PendudukPerAgamaChart::class,

            // 5. Statistik Wilayah
            PendudukPerRTChart::class,
            PendudukPerLingkunganChart::class,

            // 6. Aktivitas Terbaru
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
