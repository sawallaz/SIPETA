<?php

namespace App\Filament\Widgets;

use App\Enums\ResidentStatus;
use App\Models\Education;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

class PendudukPerPendidikanChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Pendidikan Penduduk';

    protected ?string $description =
        'Sebaran pendidikan penduduk aktif';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $educations = Education::query()
            ->withCount([
                'penduduks as active_count' => fn (Builder $query) => $query->where(
                    'resident_status',
                    ResidentStatus::ACTIVE->value
                ),
            ])
            ->get()
            ->filter(
                fn (Education $education) => $education->active_count > 0
            )
            ->sortByDesc('active_count')
            ->values();

        return [
            'labels' => $educations
                ->pluck('name')
                ->all(),

            'datasets' => [
                [
                    'label' => 'Penduduk aktif',

                    'data' => $educations
                        ->pluck('active_count')
                        ->map(fn ($value) => (int) $value)
                        ->all(),

                    'backgroundColor' => '#4f6f3a',

                    'borderRadius' => 6,

                    'barThickness' => 20,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
            'indexAxis' => 'y',

            'responsive' => true,

            'maintainAspectRatio' => false,

            'plugins' => [
                'legend' => [
                    'display' => false,
                ],
            ],

            'scales' => [
                'x' => [
                    'beginAtZero' => true,

                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
