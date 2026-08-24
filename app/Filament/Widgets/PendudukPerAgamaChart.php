<?php

namespace App\Filament\Widgets;

use App\Enums\ResidentStatus;
use App\Models\Religion;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * PHASE UI-1 — horizontal bar chart: active residents per religion (agama).
 *
 * Religion is a lookup taxonomy (seeded across the permitted span), so per
 * product decisions (docs/PRODUCT_DECISIONS.md §2 D-CHT-02) it must be a
 * horizontal bar chart — never a pie once the category count grows. Counts
 * only ACTIVE residents (docs/REQUIREMENTS.md §5.5); categories with zero
 * active residents are omitted; sorted by count descending, name asc.
 */
class PendudukPerAgamaChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Penduduk per Agama';

    protected ?string $description = 'Sebaran penduduk aktif menurut agama';

    protected ?string $emptyStateHeading = 'Belum ada data penduduk';

    protected ?string $emptyStateDescription = 'Grafik akan muncul setelah data penduduk aktif tersedia.';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'bar';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $religions = Religion::withCount([
            'penduduks as active_count' => fn (Builder $query) => $query->where('resident_status', ResidentStatus::ACTIVE->value),
        ])
            ->get()
            ->filter(fn (Religion $religion) => $religion->active_count > 0)
            ->sortBy([
                ['active_count', 'desc'],
                ['name', 'asc'],
            ])
            ->values();

        $palette = [
            '#10b981', '#8b5cf6', '#f97316', '#0ea5e9', '#ec4899',
            '#84cc16', '#6366f1', '#14b8a6', '#eab308', '#f59e0b', '#64748b',
        ];

        return [
            'datasets' => [
                [
                    'label' => 'Penduduk aktif',
                    'data' => $religions->pluck('active_count')->all(),
                    'backgroundColor' => array_slice($palette, 0, $religions->count()),
                ],
            ],
            'labels' => $religions->pluck('name')->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',
            'plugins' => [
                'legend' => ['display' => false],
            ],
            'scales' => [
                'x' => [
                    'beginAtZero' => true,
                    'ticks' => ['precision' => 0],
                ],
            ],
        ];
    }
}
