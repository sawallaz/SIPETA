<?php

namespace App\Filament\Widgets;

use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\Rt;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Filament\Support\Icons\Heroicon;

class SipetaStatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $totalKk = KartuKeluarga::query()->count();

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
