<?php

namespace Tests\Feature\Phase3;

use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\KartuKeluargas\Pages\CreateKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\Pages\EditKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\Pages\ListKartuKeluargas;
use App\Models\KartuKeluarga;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Phase 3.2.4 — KartuKeluarga Resource feature tests.
 *
 * Covers page rendering (which is what surfaced the wrong-namespace and
 * wrong-table defects), form validation, CRUD, search, sort and bulk delete.
 */
class KartuKeluargaResourceTest extends Phase3ResourceTestCase
{
    public function test_list_page_renders(): void
    {
        $records = KartuKeluarga::factory()->count(3)->create();

        Livewire::test(ListKartuKeluargas::class)
            ->assertOk()
            ->assertCanSeeTableRecords($records);
    }

    public function test_create_page_renders(): void
    {
        Livewire::test(CreateKartuKeluarga::class)
            ->assertOk()
            ->assertFormFieldExists('kk_number')
            ->assertFormFieldExists('address')
            ->assertFormFieldExists('postal_code')
            ->assertFormFieldExists('notes');
    }

    public function test_edit_page_renders_with_record_data(): void
    {
        $kk = KartuKeluarga::factory()->create();

        Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])
            ->assertOk()
            ->assertFormSet([
                'kk_number' => $kk->kk_number,
                'address' => $kk->address,
            ]);
    }

    public function test_can_create_kartu_keluarga(): void
    {
        Livewire::test(CreateKartuKeluarga::class)
            ->fillForm([
                'kk_number' => '7371010101010001',
                'address' => 'Jl. Poros Tanete No. 1',
                'postal_code' => '90811',
                'notes' => 'Data uji.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('kartu_keluarga', [
            'kk_number' => '7371010101010001',
            'address' => 'Jl. Poros Tanete No. 1',
        ]);
    }

    public function test_can_edit_kartu_keluarga(): void
    {
        $kk = KartuKeluarga::factory()->create();

        Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])
            ->fillForm(['address' => 'Jl. Baru No. 9'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Jl. Baru No. 9', $kk->refresh()->address);
    }

    public function test_required_fields_are_validated(): void
    {
        Livewire::test(CreateKartuKeluarga::class)
            ->fillForm([
                'kk_number' => null,
                'address' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'kk_number' => 'required',
                'address' => 'required',
            ]);
    }

    public function test_kk_number_must_be_sixteen_digits(): void
    {
        Livewire::test(CreateKartuKeluarga::class)
            ->fillForm([
                'kk_number' => '123',
                'address' => 'Jl. Uji',
            ])
            ->call('create')
            ->assertHasFormErrors(['kk_number']);
    }

    /**
     * Regression: the unique rule previously pointed at a non-existent
     * `kartu_keluargas` table. The real table is `kartu_keluarga`.
     */
    public function test_kk_number_must_be_unique_against_the_real_table(): void
    {
        $existing = KartuKeluarga::factory()->create(['kk_number' => '7371010101010002']);

        Livewire::test(CreateKartuKeluarga::class)
            ->fillForm([
                'kk_number' => $existing->kk_number,
                'address' => 'Jl. Uji',
            ])
            ->call('create')
            ->assertHasFormErrors(['kk_number' => 'unique']);

        $this->assertSame(1, KartuKeluarga::where('kk_number', '7371010101010002')->count());
    }

    public function test_editing_a_record_ignores_its_own_kk_number_for_uniqueness(): void
    {
        $kk = KartuKeluarga::factory()->create();

        Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])
            ->fillForm([
                'kk_number' => $kk->kk_number,
                'address' => 'Jl. Tetap Sama',
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_table_can_search_by_kk_number(): void
    {
        $match = KartuKeluarga::factory()->create(['kk_number' => '7371010101010003']);
        $other = KartuKeluarga::factory()->create(['kk_number' => '7371010101010004']);

        Livewire::test(ListKartuKeluargas::class)
            ->searchTable($match->kk_number)
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_table_can_sort_by_kk_number(): void
    {
        $first = KartuKeluarga::factory()->create(['kk_number' => '1111111111111111']);
        $second = KartuKeluarga::factory()->create(['kk_number' => '9999999999999999']);

        Livewire::test(ListKartuKeluargas::class)
            ->sortTable('kk_number')
            ->assertCanSeeTableRecords([$first, $second], inOrder: true);
    }

    public function test_can_delete_via_bulk_action(): void
    {
        $records = KartuKeluarga::factory()->count(2)->create();

        Livewire::test(ListKartuKeluargas::class)
            ->selectTableRecords($records->pluck('id')->all())
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertHasNoErrors();

        foreach ($records as $record) {
            $this->assertDatabaseMissing('kartu_keluarga', ['id' => $record->id]);
        }
    }

    public function test_resource_is_registered_in_the_kependudukan_navigation_group(): void
    {
        $this->assertSame('Kependudukan', KartuKeluargaResource::getNavigationGroup());
        $this->assertSame('Kartu Keluarga', KartuKeluargaResource::getNavigationLabel());
    }
}
