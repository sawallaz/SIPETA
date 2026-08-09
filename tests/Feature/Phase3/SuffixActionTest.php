<?php

namespace Tests\Feature\Phase3;

use App\Filament\Resources\KartuKeluargas\Pages\CreateKartuKeluarga;
use App\Filament\Resources\Penduduks\Pages\CreatePenduduk;
use Livewire\Livewire;

/**
 * The "+" (add) suffix actions for RW / Lingkungan and RT now live on the
 * KARTU KELUARGA form, NOT on the Penduduk form. Wilayah is owned by the KK
 * (ADR-004); a Penduduk inherits it and must never pick RT itself.
 */
class SuffixActionTest extends Phase3ResourceTestCase
{
    public function test_area_and_rt_suffix_plus_buttons_render_on_kk_form(): void
    {
        Livewire::test(CreateKartuKeluarga::class)
            ->assertFormFieldExists(
                'area_unit_id',
                fn ($field): bool => collect($field->getSuffixActions())
                    ->contains(fn ($a) => $a->getName() === 'addAreaUnit')
            )
            ->assertFormFieldExists(
                'rt_id',
                fn ($field): bool => collect($field->getSuffixActions())
                    ->contains(fn ($a) => $a->getName() === 'addRt')
            );
    }

    public function test_penduduk_form_has_no_area_or_rt_fields(): void
    {
        Livewire::test(CreatePenduduk::class)
            ->assertFormFieldDoesNotExist('area_unit_id')
            ->assertFormFieldDoesNotExist('rt_id');
    }
}
