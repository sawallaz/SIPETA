<?php

namespace App\Filament\Widgets;

use App\Enums\ResidentStatus;
use App\Models\Rt;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

class PendudukPerRTChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Penduduk per RT';

    protected ?string $description =
        'Jumlah penduduk aktif berdasarkan RT';

    protected ?string $maxHeight = '300px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rts = Rt::query()
            ->withCount([
                'penduduks as active_count' =>
                    fn (Builder $query) =>
                        $query->where(
                            'resident_status',
                            ResidentStatus::ACTIVE->value
                        ),
            ])
            ->get()
            ->sortBy('number', SORT_NATURAL)
            ->values();

        return [
            'labels' => $rts
                ->map(fn (Rt $rt) => "RT {$rt->number}")
                ->all(),

            'datasets' => [
                [
                    'label' => 'Penduduk aktif',

                    'data' => $rts
                        ->pluck('active_count')
                        ->map(fn ($value) => (int) $value)
                        ->all(),

                    'backgroundColor' => '#4f6f3a',

                    'borderRadius' => 6,

                    'barThickness' => 22,
                ],
            ],
        ];
    }

    protected function getOptions(): array
    {
        return [
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

                'y' => [
                    'ticks' => [
                        'autoSkip' => false,
                    ],
                ],
            ],
        ];
    }
}
