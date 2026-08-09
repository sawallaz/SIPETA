<?php

namespace App\Filament\Widgets;

use App\Enums\ResidentStatus;
use App\Models\AreaUnit;
use App\Models\Penduduk;
use Filament\Widgets\ChartWidget;

class PendudukPerLingkunganChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Penduduk per Lingkungan';

    protected ?string $description =
        'Jumlah penduduk aktif berdasarkan lingkungan';

    protected ?string $maxHeight = '300px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $counts = Penduduk::query()
            ->where(
                'resident_status',
                ResidentStatus::ACTIVE->value
            )
            ->join(
                'rts',
                'penduduk.rt_id',
                '=',
                'rts.id'
            )
            ->join(
                'area_units',
                'rts.area_unit_id',
                '=',
                'area_units.id'
            )
            ->selectRaw(
                'area_units.id as area_unit_id, COUNT(*) as total'
            )
            ->groupBy('area_units.id')
            ->pluck('total', 'area_unit_id');

        $areas = AreaUnit::query()
            ->orderBy('name')
            ->get();

        return [
            'labels' => $areas
                ->map(fn (AreaUnit $area) => $area->name)
                ->all(),

            'datasets' => [
                [
                    'label' => 'Penduduk aktif',

                    'data' => $areas
                        ->map(
                            fn (AreaUnit $area) =>
                                (int) (
                                    $counts[$area->id] ?? 0
                                )
                        )
                        ->all(),

                    'backgroundColor' => '#6b8237',

                    'borderRadius' => 6,

                    'barThickness' => 24,
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
