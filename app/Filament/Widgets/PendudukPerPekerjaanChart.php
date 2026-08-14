<?php

namespace App\Filament\Widgets;

use App\Enums\ResidentStatus;
use App\Models\Occupation;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * PHASE UI-1 — horizontal bar chart: active residents per occupation (pekerjaan).
 *
 * Occupation has more than ~6 seeded categories, so per product decisions
 * (docs/PRODUCT_DECISIONS.md §2 D-CHT-02/D-CHT-04) it must be a horizontal
 * bar chart — never a pie. Counts only residents with resident_status =
 * ACTIVE (docs/REQUIREMENTS.md §5.5 "Charts reflect active residents only").
 * Occupations with zero active residents are omitted; sorted by count
 * descending, ties broken by name.
 */
class PendudukPerPekerjaanChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Penduduk per Pekerjaan';

    protected ?string $description = 'Sebaran penduduk aktif menurut pekerjaan';

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
        $occupations = Occupation::withCount([
            'penduduks as active_count' => fn (Builder $query) => $query->where('resident_status', ResidentStatus::ACTIVE->value),
        ])
            ->get()
            ->filter(fn (Occupation $occupation) => $occupation->active_count > 0)
            ->sortBy([
                ['active_count', 'desc'],
                ['name', 'asc'],
            ])
            ->values();

        return [
            'datasets' => [
                [
                    'label' => 'Penduduk aktif',
                    'data' => $occupations->pluck('active_count')->all(),
                    'backgroundColor' => '#4f6f3a',
                ],
            ],
            'labels' => $occupations->pluck('name')->all(),
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
