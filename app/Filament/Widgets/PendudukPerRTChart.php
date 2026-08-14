<?php

namespace App\Filament\Widgets;

use App\Enums\ResidentStatus;
use App\Models\Rt;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;

class PendudukPerRTChart extends ChartWidget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Penduduk per RT';

    protected ?string $description =
        'Jumlah penduduk aktif berdasarkan lingkungan dan RT';

    protected ?string $maxHeight = '300px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $rts = Rt::query()
            ->with('areaUnit')
            ->withCount([
                'penduduks as active_count' => fn (Builder $query) => $query
                    ->where(
                        'resident_status',
                        ResidentStatus::ACTIVE->value,
                    ),
            ])
            ->get()
            ->sort(function (Rt $a, Rt $b): int {
                $areaA = mb_strtolower(
                    $a->areaUnit?->display_label ?? '',
                );

                $areaB = mb_strtolower(
                    $b->areaUnit?->display_label ?? '',
                );

                $areaCompare = strnatcasecmp($areaA, $areaB);

                if ($areaCompare !== 0) {
                    return $areaCompare;
                }

                return strnatcasecmp(
                    (string) $a->number,
                    (string) $b->number,
                );
            })
            ->values();

        return [
            'labels' => $rts
                ->map(
                    fn (Rt $rt): string => sprintf(
                        '%s / RT %s',
                        $rt->areaUnit?->display_label ?? 'Wilayah tidak diketahui',
                        $rt->number,
                    ),
                )
                ->all(),

            'datasets' => [
                [
                    'label' => 'Penduduk aktif',

                    'data' => $rts
                        ->pluck('active_count')
                        ->map(fn ($value): int => (int) $value)
                        ->all(),

                    'backgroundColor' => '#4f6f3a',

                    'borderRadius' => 6,

                    'barThickness' => 22,
                ],
            ],
        ];
    }

    /**
     * Opsi chart dikembalikan sebagai RawJs (bukan array PHP biasa).
     *
     * ChartWidget memanggil `@js($options)` yang pada akhirnya
     * `json_encode` seluruh array. Jika callback tooltip ditulis sebagai
     * string heredoc di dalam array PHP biasa, json_encode akan
     * membungkusnya sebagai string kutipan ("function () {...}") sehingga
     * Chart.js menerima string, bukan fungsi — callback tidak akan pernah
     * dieksekusi. Membungkus SELURUH opsi dalam RawJs menjaga body fungsi
     * tetap berupa JavaScript asli.
     */
    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
{
    responsive: true,
    maintainAspectRatio: false,

    plugins: {
        legend: {
            display: false,
        },

        tooltip: {
            callbacks: {
                title: function (items) {
                    return items[0]?.label ?? '';
                },

                label: function (context) {
                    return 'Penduduk aktif: ' + context.parsed.y + ' orang';
                },
            },
        },
    },

    scales: {
        x: {
            beginAtZero: true,

            ticks: {
                precision: 0,
                autoSkip: false,
                maxRotation: 45,
                minRotation: 45,
            },
        },

        y: {
            beginAtZero: true,

            ticks: {
                precision: 0,
            },
        },
    },
}
JS);
    }
}
