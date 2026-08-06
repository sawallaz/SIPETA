<?php

namespace App\Filament\Widgets;

use App\Enums\ResidentStatus;
use App\Models\Occupation;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase 4.3 — doughnut chart: active residents per occupation (pekerjaan).
 *
 * Counts only residents with resident_status = ACTIVE (docs/REQUIREMENTS.md
 * §5.5 "Charts reflect active residents only"). Occupations come from the
 * evolving `occupations` lookup table (12 rows seeded); only occupations
 * with at least one active resident are shown, sorted by count descending
 * (largest share first) with ties broken by name — empty occupations are
 * omitted so the doughnut stays readable.
 */
class PendudukPerPekerjaanChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected ?string $heading = 'Penduduk per Pekerjaan';

    protected ?string $emptyStateHeading = 'Belum ada data penduduk';

    protected ?string $emptyStateDescription = 'Grafik akan muncul setelah data penduduk aktif tersedia.';

    protected function getType(): string
    {
        return 'doughnut';
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
            'plugins' => [
                'legend' => [
                    'position' => 'right',
                ],
            ],
        ];
    }
}
