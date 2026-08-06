<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\Penduduks\PendudukResource;
use Filament\Widgets\Widget;

/**
 * Phase 4.5 — quick actions on the dashboard.
 *
 * Four shortcuts to the existing Kartu Keluarga / Penduduk resource routes:
 * create (Tambah) and index (Data) for each. No new resources, pages,
 * migrations, models, controllers, or Livewire components — every link is
 * generated from the existing resource route registrations.
 */
class QuickActionsWidget extends Widget
{
    protected static bool $isLazy = false;

    protected string $view = 'filament.widgets.quick-actions-widget';

    /**
     * @return array{actions: array<int, array{
     *     label: string,
     *     description: string,
     *     icon: string,
     *     url: string,
     * }>}
     */
    protected function getViewData(): array
    {
        return [
            'actions' => [
                [
                    'label' => 'Tambah Kartu Keluarga',
                    'description' => 'Buat kartu keluarga baru',
                    'icon' => 'heroicon-o-plus-circle',
                    'url' => KartuKeluargaResource::getUrl('create'),
                ],
                [
                    'label' => 'Tambah Penduduk',
                    'description' => 'Tambah penduduk baru',
                    'icon' => 'heroicon-o-user-plus',
                    'url' => PendudukResource::getUrl('create'),
                ],
                [
                    'label' => 'Data Kartu Keluarga',
                    'description' => 'Lihat dan kelola kartu keluarga',
                    'icon' => 'heroicon-o-rectangle-stack',
                    'url' => KartuKeluargaResource::getUrl('index'),
                ],
                [
                    'label' => 'Data Penduduk',
                    'description' => 'Lihat dan kelola data penduduk',
                    'icon' => 'heroicon-o-users',
                    'url' => PendudukResource::getUrl('index'),
                ],
            ],
        ];
    }
}
