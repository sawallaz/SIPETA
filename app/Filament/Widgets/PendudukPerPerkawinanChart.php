<?php

namespace App\Filament\Widgets;

use App\Enums\MaritalStatus;
use App\Models\Penduduk;
use Filament\Widgets\ChartWidget;

class PendudukPerPerkawinanChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Status Perkawinan';

    protected ?string $description = 'Sebaran penduduk berdasarkan status perkawinan';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $counts = Penduduk::query()
            ->selectRaw('marital_status, COUNT(*) as total')
            ->whereNotNull('marital_status')
            ->groupBy('marital_status')
            ->pluck('total', 'marital_status');

        $labels = [
            'Belum Kawin',
            'Kawin',
            'Cerai Hidup',
            'Cerai Mati',
        ];

        $data = [
            (int) ($counts[MaritalStatus::BELUM_KAWIN->value] ?? 0),
            (int) ($counts[MaritalStatus::KAWIN->value] ?? 0),
            (int) ($counts[MaritalStatus::CERAI_HIDUP->value] ?? 0),
            (int) ($counts[MaritalStatus::CERAI_MATI->value] ?? 0),
        ];

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Penduduk',
                    'data' => $data,
                    'backgroundColor' => [
                        '#456B4F',
                        '#6B8E23',
                        '#8AA83B',
                        '#A2B86C',
                    ],
                    'borderColor' => '#ffffff',
                    'borderWidth' => 2,
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
            'cutout' => '60%',
        ];
    }
}
