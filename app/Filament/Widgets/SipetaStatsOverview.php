<?php

namespace App\Filament\Widgets;

use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\Rt;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\View\View;

class SipetaStatsOverview extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    /**
     * Render via a local view.
     *
     * The vendor `stats-overview-widget` blade calls `$this->getColumns()` in
     * a top-level `@php` block. When the widget is rendered inside the full
     * dashboard page (batched partial include) `$this` is not bound in that
     * include scope, so the cards collapse to an empty widget and the KPI
     * labels never reach the DOM. Rendering through a local view keeps `$this`
     * bound (render() is always invoked by Livewire) and reuses the Filament
     * stats markup, so the four production cards render correctly everywhere.
     */
    public function render(): View
    {
        return view('filament.widgets.sipeta-stats-overview', [
            'stats' => $this->getCachedStats(),
        ]);
    }

    protected function getStats(): array
    {
        $totalKk = KartuKeluarga::query()->active()->count();

        $pendudukAktif = Penduduk::query()
            ->where('resident_status', ResidentStatus::ACTIVE->value)
            ->count();

        $belumMenikah = Penduduk::query()
            ->where('marital_status', MaritalStatus::BELUM_KAWIN->value)
            ->count();

        $jumlahRt = Rt::query()->count();

        return [
            Stat::make('Kartu Keluarga', number_format($totalKk))
                ->description('Total KK terdaftar')
                ->descriptionIcon(Heroicon::OutlinedHome)
                ->color('primary'),

            Stat::make('Penduduk Aktif', number_format($pendudukAktif))
                ->description('Penduduk berstatus aktif')
                ->descriptionIcon(Heroicon::OutlinedUsers)
                ->color('success'),

            Stat::make('Belum Menikah', number_format($belumMenikah))
                ->description('Penduduk belum kawin')
                ->descriptionIcon(Heroicon::OutlinedHeart)
                ->color('warning'),

            Stat::make('Jumlah RT', number_format($jumlahRt))
                ->description('RT terdaftar')
                ->descriptionIcon(Heroicon::OutlinedMapPin)
                ->color('info'),
        ];
    }
}
