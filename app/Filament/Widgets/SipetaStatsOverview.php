<?php

namespace App\Filament\Widgets;

use App\Enums\Gender;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 4.1 — placeholder KPI cards for the admin dashboard.
 *
 * Shows only counts derived from existing models (KartuKeluarga, Penduduk).
 * No charts, no analytics, no exports. Statistics/breakdowns are later sub-phases.
 */
class SipetaStatsOverview extends StatsOverviewWidget
{
    // KPI cards are cheap count queries; render eagerly so they appear in the
    // initial dashboard HTML (no Livewire lazy hydration needed).
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $totalPenduduk = Penduduk::count();

        return [
            Stat::make('Total Kartu Keluarga', KartuKeluarga::count())
                ->icon('heroicon-o-home-modern'),

            Stat::make('Total Penduduk', $totalPenduduk)
                ->icon('heroicon-o-users'),

            Stat::make('Laki-laki', Penduduk::where('gender', Gender::LAKI_LAKI->value)->count())
                ->icon('heroicon-o-user'),

            Stat::make('Perempuan', Penduduk::where('gender', Gender::PEREMPUAN->value)->count())
                ->icon('heroicon-o-user'),
        ];
    }
}
