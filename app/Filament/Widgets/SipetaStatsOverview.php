<?php

namespace App\Filament\Widgets;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Models\AreaUnit;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\Rt;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Phase 4.2 — KPI cards for the admin dashboard.
 *
 * Eleven population statistics, every one derived from existing tables:
 *   - Keluarga: kartu_keluarga count + penduduk.family_relation breakdown
 *     (Total Kepala Keluarga = KEPALA_KELUARGA; Total Anggota Keluarga =
 *     everyone else — the two partition Total Penduduk).
 *   - Penduduk: total + gender breakdown (penduduk.gender).
 *   - Wilayah: rts and area_units counts (RT; RW / Lingkungan).
 *   - Status: penduduk.resident_status breakdown (ACTIVE / PINDAH / MENINGGAL).
 *
 * No charts, no exports, no filters. Values are formatted with Indonesian
 * thousands separators (1.234). Cards are ordered and colored by group;
 * Filament v4 has no native stat grouping, so grouping is conveyed through
 * ordering + color families only.
 */
class SipetaStatsOverview extends StatsOverviewWidget
{
    // KPI cards are cheap count queries; render eagerly so they appear in the
    // initial dashboard HTML (no Livewire lazy hydration needed).
    protected static bool $isLazy = false;

    protected function getStats(): array
    {
        $totalPenduduk = Penduduk::count();

        $lakiLaki = Penduduk::where('gender', Gender::LAKI_LAKI->value)->count();
        $perempuan = Penduduk::where('gender', Gender::PEREMPUAN->value)->count();

        $kepalaKeluarga = Penduduk::where('family_relation', FamilyRelation::KEPALA_KELUARGA->value)->count();

        return [
            // --- Keluarga ---
            Stat::make('Total Kartu Keluarga', $this->format($totalKk = KartuKeluarga::count()))
                ->description('Kartu keluarga terdaftar di sistem')
                ->icon('heroicon-o-home-modern')
                ->color('primary'),

            Stat::make('Total Kepala Keluarga', $this->format($kepalaKeluarga))
                ->description('Penduduk berstatus kepala keluarga')
                ->icon('heroicon-o-user-circle')
                ->color('primary'),

            Stat::make('Total Anggota Keluarga', $this->format($totalPenduduk - $kepalaKeluarga))
                ->description('Penduduk selain kepala keluarga')
                ->icon('heroicon-o-user-group')
                ->color('gray'),

            // --- Penduduk ---
            Stat::make('Total Penduduk', $this->format($totalPenduduk))
                ->description('Seluruh penduduk yang terdaftar')
                ->icon('heroicon-o-users')
                ->color('primary'),

            Stat::make('Laki-laki', $this->format($lakiLaki))
                ->description($this->percentage($lakiLaki, $totalPenduduk))
                ->icon('heroicon-o-user')
                ->color('info'),

            Stat::make('Perempuan', $this->format($perempuan))
                ->description($this->percentage($perempuan, $totalPenduduk))
                ->icon('heroicon-o-user')
                ->color('danger'),

            // --- Wilayah ---
            Stat::make('Total RT', $this->format(Rt::count()))
                ->description('Rukun tetangga terdaftar')
                ->icon('heroicon-o-squares-2x2')
                ->color('gray'),

            Stat::make('Total RW / Lingkungan', $this->format(AreaUnit::count()))
                ->description('RW atau lingkungan terdaftar')
                ->icon('heroicon-o-map-pin')
                ->color('gray'),

            // --- Status ---
            Stat::make('Penduduk Aktif', $this->format(Penduduk::where('resident_status', ResidentStatus::ACTIVE->value)->count()))
                ->description('Berstatus aktif')
                ->icon('heroicon-o-check-circle')
                ->color('success'),

            Stat::make('Penduduk Pindah', $this->format(Penduduk::where('resident_status', ResidentStatus::PINDAH->value)->count()))
                ->description('Berstatus pindah')
                ->icon('heroicon-o-arrow-right-circle')
                ->color('warning'),

            Stat::make('Penduduk Meninggal', $this->format(Penduduk::where('resident_status', ResidentStatus::MENINGGAL->value)->count()))
                ->description('Berstatus meninggal')
                ->icon('heroicon-o-x-circle')
                ->color('gray'),
        ];
    }

    /**
     * Indonesian-style integer formatting: 1234567 -> "1.234.567".
     */
    private function format(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }

    /**
     * Share of total as a whole percentage, e.g. "45% dari total penduduk".
     * Guarded against division by zero (empty database).
     */
    private function percentage(int $part, int $total): string
    {
        $percent = $total > 0 ? (int) round(($part / $total) * 100) : 0;

        return "{$percent}% dari total penduduk";
    }
}
