<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\Penduduks\PendudukResource;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * Phase 4.4 — recent activity on the dashboard.
 *
 * Merges the 5 newest Kartu Keluarga with the 5 newest Penduduk into one
 * chronological list (newest first). Each row links to the record's Filament
 * edit page via the existing resource routes.
 *
 * Read-only: data comes from the existing `kartu_keluarga` and `penduduk`
 * tables. No observers, no audit log implementation, no new tables,
 * migrations, or seeders.
 */
class RecentActivityWidget extends Widget
{
    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected string $view = 'filament.widgets.recent-activity-widget';

    /**
     * @return array{activities: Collection<int, array{
     *     icon: string,
     *     title: string,
     *     subtitle: string,
     *     created_at: Illuminate\Support\Carbon,
     *     url: string,
     * }>}
     */
    protected function getViewData(): array
    {
        $activities = collect()
            ->concat(
                KartuKeluarga::latest()->limit(5)->get()->map(
                    fn (KartuKeluarga $kk): array => [
                        'icon' => 'heroicon-o-home-modern',
                        'title' => "KK {$kk->kk_number}",
                        'subtitle' => $kk->address,
                        'created_at' => $kk->created_at,
                        'url' => KartuKeluargaResource::getUrl('edit', ['record' => $kk]),
                    ],
                ),
            )
            ->concat(
                Penduduk::latest()->limit(5)->get()->map(
                    fn (Penduduk $penduduk): array => [
                        'icon' => 'heroicon-o-user',
                        'title' => $penduduk->full_name,
                        'subtitle' => "NIK {$penduduk->nik}",
                        'created_at' => $penduduk->created_at,
                        'url' => PendudukResource::getUrl('edit', ['record' => $penduduk]),
                    ],
                ),
            )
            ->sortByDesc(fn (array $activity): int => $activity['created_at']?->timestamp ?? 0)
            ->values();

        return [
            'activities' => $activities,
        ];
    }
}
