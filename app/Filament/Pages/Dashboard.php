<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\PendudukPerGenderChart;
use App\Filament\Widgets\PendudukPerLingkunganChart;
use App\Filament\Widgets\PendudukPerPendidikanChart;
use App\Filament\Widgets\PendudukPerRTChart;
use App\Filament\Widgets\QuickActionsWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\SipetaStatsOverview;
use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    public function getWidgets(): array
    {
        return [
            /*
             * ==========================================================
             * 1. KPI
             * ==========================================================
             */
            SipetaStatsOverview::class,

            /*
             * ==========================================================
             * 2. AKSI CEPAT
             * Tepat di bawah KPI
             * ==========================================================
             */
            QuickActionsWidget::class,

            /*
             * ==========================================================
             * 3. GRAFIK UTAMA
             * Maksimal 4 grafik di dashboard utama.
             * ==========================================================
             */
            PendudukPerGenderChart::class,
            PendudukPerRTChart::class,
            PendudukPerLingkunganChart::class,
            PendudukPerPendidikanChart::class,

            /*
             * ==========================================================
             * 4. AKTIVITAS
             * ==========================================================
             */
            RecentActivityWidget::class,
        ];
    }

    /**
     * Dashboard menggunakan 2 kolom pada desktop.
     *
     * Tujuannya:
     * - card lebih lebar
     * - chart tidak terlalu sempit
     * - teks panjang lebih mudah dibaca
     * - mengurangi kesan dashboard "pecah-pecah"
     */
    public function getColumns(): int | array
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
