<?php

namespace Tests\Feature\Phase3;

use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\KartuKeluargas\Pages\EditKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\RelationManagers\PenduduksRelationManager;
use App\Filament\Resources\Penduduks\Pages\CreatePenduduk;
use App\Filament\Resources\Penduduks\PendudukResource;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use Livewire\Livewire;

/**
 * Phase 3.4 — KK <-> Penduduk relation inside Filament.
 *
 * Covers relation manager registration, member listing, member count badge
 * and family navigation between the two resources.
 */
class KartuKeluargaPendudukRelationTest extends Phase3ResourceTestCase
{
    public function test_relation_manager_is_registered_on_the_kk_resource(): void
    {
        $this->assertContains(
            PenduduksRelationManager::class,
            KartuKeluargaResource::getRelations(),
        );
    }

    public function test_relation_manager_uses_the_existing_penduduks_relation(): void
    {
        $this->assertSame('penduduks', PenduduksRelationManager::getRelationshipName());
    }

    public function test_relation_manager_lists_only_members_of_the_owner_kk(): void
    {
        $kk = KartuKeluarga::factory()->create();
        $members = Penduduk::factory()->count(2)->create(['kk_id' => $kk->getKey()]);
        $outsider = Penduduk::factory()->create();

        Livewire::test(PenduduksRelationManager::class, [
            'ownerRecord' => $kk,
            'pageClass' => EditKartuKeluarga::class,
        ])
            ->assertOk()
            ->assertCanSeeTableRecords($members)
            ->assertCanNotSeeTableRecords([$outsider]);
    }

    public function test_member_count_badge_reflects_the_number_of_members(): void
    {
        $kk = KartuKeluarga::factory()->create();
        Penduduk::factory()->count(3)->create(['kk_id' => $kk->getKey()]);

        $this->assertSame(
            '3',
            PenduduksRelationManager::getBadge($kk, EditKartuKeluarga::class),
        );
    }

    public function test_member_count_badge_is_zero_for_an_empty_kk(): void
    {
        $kk = KartuKeluarga::factory()->create();

        $this->assertSame(
            '0',
            PenduduksRelationManager::getBadge($kk, EditKartuKeluarga::class),
        );
    }

    public function test_kk_table_member_count_column_matches_relation(): void
    {
        $kk = KartuKeluarga::factory()->create();
        Penduduk::factory()->count(2)->create(['kk_id' => $kk->getKey()]);

        $this->assertSame(2, $kk->penduduks()->count());
    }

    public function test_relation_manager_links_to_the_penduduk_resource(): void
    {
        $this->assertSame(
            PendudukResource::class,
            PenduduksRelationManager::getRelatedResource(),
        );
    }

    public function test_edit_kk_page_renders_the_relation_manager(): void
    {
        $kk = KartuKeluarga::factory()->create();
        Penduduk::factory()->create(['kk_id' => $kk->getKey()]);

        $this->get(KartuKeluargaResource::getUrl('edit', ['record' => $kk]))
            ->assertOk()
            ->assertSee(PenduduksRelationManager::class, escape: false);

        $this->assertSame(
            'Anggota Keluarga',
            PenduduksRelationManager::getTitle($kk, EditKartuKeluarga::class),
        );
    }

    public function test_add_member_action_links_to_penduduk_create_prefilled_with_the_kk(): void
    {
        $kk = KartuKeluarga::factory()->create();

        $component = Livewire::test(PenduduksRelationManager::class, [
            'ownerRecord' => $kk,
            'pageClass' => EditKartuKeluarga::class,
        ]);

        $createAction = $component->instance()->getTable()->getHeaderActions()[0];

        $this->assertStringContainsString(
            'kk_id='.$kk->getKey(),
            $createAction->getUrl(),
        );
    }

    public function test_create_penduduk_page_preselects_the_kk_from_the_query_string(): void
    {
        $kk = KartuKeluarga::factory()->create();

        $this->get(PendudukResource::getUrl('create', ['kk_id' => $kk->getKey()]))
            ->assertOk();

        Livewire::withQueryParams(['kk_id' => $kk->getKey()])
            ->test(CreatePenduduk::class)
            ->assertFormSet(['kk_id' => $kk->getKey()]);
    }

    public function test_penduduk_table_links_back_to_its_kartu_keluarga(): void
    {
        $kk = KartuKeluarga::factory()->create();
        Penduduk::factory()->create(['kk_id' => $kk->getKey()]);

        $expected = KartuKeluargaResource::getUrl('edit', ['record' => $kk->getKey()]);

        $this->get(PendudukResource::getUrl('index'))
            ->assertOk()
            ->assertSee($expected, escape: false);
    }
}
