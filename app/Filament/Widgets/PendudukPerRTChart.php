<?php

namespace App\Filament\Widgets;

use App\Enums\ResidentStatus;
use App\Models\Rt;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 4.3 — bar chart: active residents per RT.
 *
 * Counts only residents with resident_status = ACTIVE (docs/REQUIREMENTS.md
 * §5.5 "Charts reflect active residents only"). Every RT is shown, including
 * RTs with zero active residents, so the chart mirrors the kelurahan's
 * administrative structure (19 RTs across 3 area units per RegionSeeder).
 * RTs are ordered naturally by number ("RT 01" before "RT 10").
 */
class PendudukPerRTChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected ?string $heading = 'Penduduk per RT';

    protected ?string $description = 'Jumlah penduduk aktif di setiap RT';

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
        $rts = Rt::withCount([
            'penduduks as active_count' => fn (Builder $query) => $query->where('resident_status', ResidentStatus::ACTIVE->value),
        ])
            ->get()
            ->sortBy('number', SORT_NATURAL)
            ->values();

        return [
            'datasets' => [
                [
                    'label' => 'Penduduk aktif',
                    'data' => $rts->pluck('active_count')->all(),
                    // Brand color (amber) shared by both bar charts (Phase 4.6).
                    'backgroundColor' => '#f59e0b',
                ],
            ],
            'labels' => $rts->map(fn (Rt $rt) => "RT {$rt->number}")->all(),
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
