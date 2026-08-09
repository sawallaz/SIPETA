<?php

namespace App\Filament\Widgets;

use App\Enums\Gender;
use App\Models\Penduduk;
use Filament\Widgets\ChartWidget;

class PendudukPerGenderChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Jenis Kelamin';

    protected ?string $description =
        'Komposisi penduduk berdasarkan jenis kelamin';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = Penduduk::query()
            ->selectRaw('gender, COUNT(*) as total')
            ->groupBy('gender')
            ->pluck('total', 'gender');

        return [
            'labels' => [
                'Laki-laki',
                'Perempuan',
            ],

            'datasets' => [
                [
                    'label' => 'Penduduk',

                    'data' => [
                        (int) (
                            $counts[
                                Gender::LAKI_LAKI->value
                            ] ?? 0
                        ),

                        (int) (
                            $counts[
                                Gender::PEREMPUAN->value
                            ] ?? 0
                        ),
                    ],

                    'backgroundColor' => [
                        '#365f3b',
                        '#8baa43',
                    ],

                    'borderColor' => '#ffffff',
                    'borderWidth' => 3,
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
                    'position' => 'bottom',
                ],
            ],

            'cutout' => '64%',
        ];
    }
}
