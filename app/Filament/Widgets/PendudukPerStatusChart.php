<?php

namespace App\Filament\Widgets;

use App\Enums\ResidentStatus;
use App\Models\Penduduk;
use Filament\Widgets\ChartWidget;

/**
 * PHASE UI-1 — pie chart: resident status (Aktif / Pindah / Meninggal).
 *
 * Pie/doughnut is permitted for Gender and Resident Status only
 * (docs/PRODUCT_DECISIONS.md §2 D-CHT-01). Three categories -> pie valid.
 */
class PendudukPerStatusChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Status Penduduk';

    protected ?string $description = 'Sebaran penduduk menurut status keanggotaan';

    protected ?string $emptyStateHeading = 'Belum ada data penduduk';

    protected ?string $emptyStateDescription = 'Grafik akan muncul setelah data penduduk tersedia.';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'pie';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $counts = Penduduk::query()
            ->selectRaw('resident_status, COUNT(*) as total')
            ->groupBy('resident_status')
            ->pluck('total', 'resident_status');

        $labels = array_map(
            fn (ResidentStatus $status): string => match ($status) {
                ResidentStatus::ACTIVE => 'Aktif',
                ResidentStatus::PINDAH => 'Pindah',
                ResidentStatus::MENINGGAL => 'Meninggal',
            },
            ResidentStatus::cases(),
        );

        return [
            'datasets' => [
                [
                    'label' => 'Penduduk',
                    'data' => array_map(
                        fn (ResidentStatus $case): int => (int) ($counts[$case->value] ?? 0),
                        ResidentStatus::cases(),
                    ),
                    'backgroundColor' => ['#10b981', '#f59e0b', '#ef4444'],
                    'borderColor' => ['#ffffff', '#ffffff', '#ffffff'],
                ],
            ],
            'labels' => $labels,
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
