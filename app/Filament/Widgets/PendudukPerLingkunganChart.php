<?php

namespace App\Filament\Widgets;

use App\Enums\ResidentStatus;
use App\Models\AreaUnit;
use App\Models\Penduduk;
use Filament\Widgets\ChartWidget;

/**
 * Phase 4.3 — bar chart: active residents per Lingkungan / RW.
 *
 * Counts only residents with resident_status = ACTIVE (docs/REQUIREMENTS.md
 * §5.5 "Charts reflect active residents only"). Every area unit is shown,
 * including units with zero active residents, so the chart mirrors the
 * kelurahan's administrative structure. Residents are attributed through
 * their RT (penduduk.rt_id -> rts.area_unit_id) in one aggregate query.
 */
class PendudukPerLingkunganChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Penduduk per Lingkungan';

    protected ?string $description = 'Jumlah penduduk aktif di setiap RW / lingkungan';

    protected ?string $emptyStateHeading = 'Belum ada data penduduk';

    protected ?string $emptyStateDescription = 'Grafik akan muncul setelah data penduduk aktif tersedia.';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $counts = Penduduk::query()
            ->where('resident_status', ResidentStatus::ACTIVE->value)
            ->join('rts', 'penduduk.rt_id', '=', 'rts.id')
            ->join('area_units', 'rts.area_unit_id', '=', 'area_units.id')
            ->selectRaw('area_units.id as area_unit_id, COUNT(*) as total')
            ->groupBy('area_units.id')
            ->pluck('total', 'area_unit_id');

        $areas = AreaUnit::all()->sortBy('name')->values();

        return [
            'datasets' => [
                [
                    'label' => 'Penduduk aktif',
                    'data' => $areas->map(fn (AreaUnit $area) => (int) ($counts[$area->id] ?? 0))->all(),
                    // Brand color (amber) shared by both bar charts (Phase 4.6).
                    'backgroundColor' => '#f59e0b',
                ],
            ],
            'labels' => $areas->map(fn (AreaUnit $area) => $area->name)->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }
}
