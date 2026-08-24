<?php

namespace Tests\Feature\Phase3;

use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\KartuKeluargas\Pages\CreateKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\Pages\EditKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\Pages\ListKartuKeluargas;
use App\Filament\Resources\Penduduks\Pages\CreatePenduduk;
use App\Filament\Resources\Penduduks\Pages\EditPenduduk;
use App\Filament\Resources\Penduduks\Pages\ListPenduduks;
use App\Filament\Resources\Penduduks\PendudukResource;
use App\Models\AreaUnit;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\Rt;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Support\Facades\Config;
use Livewire\Livewire;

/**
 * Phase 3.5 — final Phase 3 polish.
 *
 * Verifies navigation, labels, authorization, page titles and notifications
 * across both resources. No redesign — these assert the shipped configuration.
 */
class Phase3PolishTest extends Phase3ResourceTestCase
{
    public function test_both_resources_share_the_kependudukan_navigation_group(): void
    {
        $this->assertSame('Kependudukan', KartuKeluargaResource::getNavigationGroup());
        $this->assertSame('Kependudukan', PendudukResource::getNavigationGroup());
    }

    public function test_navigation_order_puts_kartu_keluarga_before_penduduk(): void
    {
        $this->assertLessThan(
            PendudukResource::getNavigationSort(),
            KartuKeluargaResource::getNavigationSort(),
        );
    }

    public function test_resources_declare_navigation_icons(): void
    {
        $this->assertNotNull(KartuKeluargaResource::getNavigationIcon());
        $this->assertNotNull(PendudukResource::getNavigationIcon());
    }

    public function test_model_labels_are_indonesian_and_not_pluralised(): void
    {
        $this->assertSame('Kartu Keluarga', KartuKeluargaResource::getModelLabel());
        $this->assertSame('Kartu Keluarga', KartuKeluargaResource::getPluralModelLabel());
        $this->assertSame('Penduduk', PendudukResource::getModelLabel());
        $this->assertSame('Penduduk', PendudukResource::getPluralModelLabel());
    }

    public function test_page_titles_are_indonesian(): void
    {
        $kk = KartuKeluarga::factory()->create();
        $penduduk = Penduduk::factory()->create();

        $this->assertSame('Kartu Keluarga', Livewire::test(ListKartuKeluargas::class)->instance()->getTitle());
        $this->assertSame('Tambah Kartu Keluarga', Livewire::test(CreateKartuKeluarga::class)->instance()->getTitle());
        $this->assertSame('Ubah Kartu Keluarga', Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])->instance()->getTitle());

        $this->assertSame('Penduduk', Livewire::test(ListPenduduks::class)->instance()->getTitle());
        $this->assertSame('Tambah Penduduk', Livewire::test(CreatePenduduk::class)->instance()->getTitle());
        $this->assertSame('Ubah Data Penduduk', Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])->instance()->getTitle());
    }

    public function test_create_sends_an_indonesian_success_notification(): void
    {
        Livewire::test(CreateKartuKeluarga::class)
            ->fillForm([
                'kk_number' => '7371010101010555',
                'address' => 'Jl. Notifikasi',
                'area_unit_id' => AreaUnit::factory()->create()->id,
                'rt_id' => Rt::factory()->create()->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Kartu Keluarga berhasil disimpan');
    }

    public function test_edit_sends_an_indonesian_success_notification(): void
    {
        $kk = KartuKeluarga::factory()->create();

        Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])
            ->fillForm(['address' => 'Jl. Notifikasi Ubah'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Perubahan Kartu Keluarga berhasil disimpan');
    }

    public function test_tables_declare_indonesian_empty_states(): void
    {
        $this->assertSame(
            'Belum ada data Kartu Keluarga',
            Livewire::test(ListKartuKeluargas::class)->instance()->getTable()->getEmptyStateHeading(),
        );

        $this->assertSame(
            'Belum ada data penduduk',
            Livewire::test(ListPenduduks::class)->instance()->getTable()->getEmptyStateHeading(),
        );
    }

    public function test_user_implements_filament_user_contract(): void
    {
        $this->assertInstanceOf(FilamentUser::class, $this->admin);
        $this->assertTrue($this->admin->canAccessPanel(Filament::getPanel('admin')));
    }

    /**
     * Regression: before Phase 3.5 the panel was only reachable because
     * `app.env` happened to be `local`. With FilamentUser implemented, a
     * non-local environment must still admit the operator.
     */
    public function test_panel_is_reachable_outside_the_local_environment(): void
    {
        Config::set('app.env', 'production');

        $this->actingAs(User::factory()->create())
            ->get(KartuKeluargaResource::getUrl('index'))
            ->assertOk();
    }

    public function test_guests_are_redirected_away_from_the_panel(): void
    {
        auth()->logout();

        $this->get(KartuKeluargaResource::getUrl('index'))
            ->assertRedirect(Filament::getLoginUrl());
    }

    public function test_policies_are_discovered_for_both_models(): void
    {
        $this->assertTrue($this->admin->can('viewAny', KartuKeluarga::class));
        $this->assertTrue($this->admin->can('viewAny', Penduduk::class));
        $this->assertTrue($this->admin->can('create', KartuKeluarga::class));
        $this->assertTrue($this->admin->can('create', Penduduk::class));
    }
}
