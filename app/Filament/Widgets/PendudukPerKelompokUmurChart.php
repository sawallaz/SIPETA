<?php

namespace App\Filament\Widgets;

use App\Enums\ResidentStatus;
use App\Models\Penduduk;
use Carbon\Carbon;
use Filament\Widgets\ChartWidget;

class PendudukPerKelompokUmurChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Kelompok Umur';

    protected ?string $description = 'Sebaran penduduk aktif berdasarkan rentang usia';

    protected ?string $maxHeight = '280px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $penduduks = Penduduk::query()
            ->where('resident_status', ResidentStatus::ACTIVE->value)
            ->whereNotNull('birth_date')
            ->get(['birth_date']);

        $groups = [
            '0–5' => 0,
            '6–12' => 0,
            '13–17' => 0,
            '18–25' => 0,
            '26–35' => 0,
            '36–45' => 0,
            '46–55' => 0,
            '56–65' => 0,
            '66+' => 0,
        ];

        $now = Carbon::now();

        foreach ($penduduks as $p) {
            $age = $p->birth_date?->age;
            if ($age === null) {
                continue;
            }

            if ($age <= 5) {
                $groups['0–5']++;
            } elseif ($age <= 12) {
                $groups['6–12']++;
            } elseif ($age <= 17) {
                $groups['13–17']++;
            } elseif ($age <= 25) {
                $groups['18–25']++;
            } elseif ($age <= 35) {
                $groups['26–35']++;
            } elseif ($age <= 45) {
                $groups['36–45']++;
            } elseif ($age <= 55) {
                $groups['46–55']++;
            } elseif ($age <= 65) {
                $groups['56–65']++;
            } else {
                $groups['66+']++;
            }
        }

        return [
            'labels' => array_keys($groups),
            'datasets' => [
                [
                    'label' => 'Jumlah Penduduk',
                    'data' => array_values($groups),
                    'backgroundColor' => '#456B4F',
                    'borderRadius' => 4,
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
                'y' => [
                    'beginAtZero' => true,
                    'ticks' => [
                        'precision' => 0,
                    ],
                ],
            ],
        ];
    }
}
