<?php

namespace Tests\Feature\Phase3;

use App\Enums\FamilyRelation;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\KartuKeluargas\Pages\CreateKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\Pages\EditKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\Pages\ListKartuKeluargas;
use App\Models\AreaUnit;
use App\Models\KartuKeluarga;
use App\Models\Penduduk;
use App\Models\Rt;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
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
                'area_unit_id' => AreaUnit::factory()->create()->id,
                'rt_id' => Rt::factory()->create()->id,
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
        $kk = KartuKeluarga::factory()->create([
            'rt_id' => Rt::factory()->create()->id,
        ]);

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
        $kk = KartuKeluarga::factory()->create([
            'rt_id' => Rt::factory()->create()->id,
        ]);

        Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])
            ->fillForm([
                'kk_number' => $kk->kk_number,
                'address' => 'Jl. Tetap Sama',
            ])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    /**
     * Modal duplicate KK.
     *
     * Alert card lama dihapus; penggantinya adalah modal overlay yang
     * dikendalikan properti Livewire $duplicateKk (trait
     * ChecksDuplicateKkNumber). Mengetik nomor KK 16 digit yang sudah ada
     * memicu checkDuplicateKk() lewat afterStateUpdated() sehingga modal
     * berisi nomor, kepala keluarga, wilayah, dan URL edit KK lama.
     */
    public function test_duplicate_kk_modal_state_is_filled_for_existing_number(): void
    {
        $existing = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010007',
            'rt_id' => Rt::factory()->create()->id,
        ]);

        $component = Livewire::test(CreateKartuKeluarga::class)
            ->set('data.kk_number', $existing->kk_number);

        $duplicate = $component->get('duplicateKk');

        $this->assertSame($existing->kk_number, $duplicate['number']);
        $this->assertSame($existing->getKey(), $duplicate['id']);
        $this->assertSame(
            route('filament.admin.resources.kartu-keluargas.edit', ['record' => $existing->getKey()]),
            $duplicate['edit_url']
        );

        // Markup modal (bukan card inline) ikut dirender pada halaman.
        $this->assertStringContainsString('kk-dup-modal-overlay', $component->html());
    }

    /**
     * Nomor KK 16 digit yang benar-benar baru tidak memunculkan modal.
     */
    public function test_no_duplicate_kk_modal_for_new_number(): void
    {
        $component = Livewire::test(CreateKartuKeluarga::class)
            ->set('data.kk_number', '7371010101010099');

        $this->assertSame([], $component->get('duplicateKk'));
    }

    /**
     * Mengedit KK tidak menandai nomornya sendiri sebagai duplikat.
     *
     * checkDuplicateKk() memakai whereKeyNot($record->getKey()) sehingga
     * record yang sedang dibuka dikecualikan dari pencarian.
     */
    public function test_edit_own_kk_number_does_not_open_duplicate_modal(): void
    {
        $kk = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010011',
            'rt_id' => Rt::factory()->create()->id,
        ]);

        $component = Livewire::test(EditKartuKeluarga::class, ['record' => $kk->getKey()])
            ->set('data.kk_number', $kk->kk_number);

        $this->assertSame([], $component->get('duplicateKk'));
    }

    /**
     * Tombol "Tutup" pada modal mengosongkan state duplikat.
     */
    public function test_close_duplicate_kk_clears_the_modal_state(): void
    {
        $existing = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010013',
            'rt_id' => Rt::factory()->create()->id,
        ]);

        Livewire::test(CreateKartuKeluarga::class)
            ->set('data.kk_number', $existing->kk_number)
            ->assertSet('duplicateKk.number', $existing->kk_number)
            ->call('closeDuplicateKk')
            ->assertSet('duplicateKk', []);
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

    public function test_table_can_sort_by_jumlah_anggota_count(): void
    {
        // C-1 regression: "Jumlah Anggota" is sourced from penduduk.kk_id (the
        // `penduduks` HasMany), NOT the kk_anggota history pivot. Sorting must
        // use Laravel's real withCount alias `penduduks_count`; the camelCase
        // alias `kkAnggotas_count` threw "Unknown column", and counting the
        // pivot reported 0 for every household because not every write path
        // populates it.
        $empty = KartuKeluarga::factory()->create(['kk_number' => '1111111111111111']);
        $full = KartuKeluarga::factory()->create(['kk_number' => '2222222222222222']);

        Penduduk::factory()->create(['kk_id' => $full->id]);
        Penduduk::factory()->create(['kk_id' => $full->id]);

        // Ascending sort: the KK with 0 anggota (empty) precedes the KK with 2.
        Livewire::test(ListKartuKeluargas::class)
            ->sortTable('penduduks_count')
            ->assertCanSeeTableRecords([$empty, $full], inOrder: true)
            ->assertHasNoErrors();
    }

    public function test_jumlah_anggota_counts_penduduk_not_pivot(): void
    {
        // The pivot is deliberately left empty here: the displayed count must
        // still be 2, proving penduduk.kk_id is the single source of truth.
        $kk = KartuKeluarga::factory()->create();
        Penduduk::factory()->count(2)->create(['kk_id' => $kk->id]);

        $this->assertSame(0, $kk->kkAnggotas()->count());
        $this->assertSame(2, $kk->jumlah_anggota);

        Livewire::test(ListKartuKeluargas::class)
            ->assertTableColumnStateSet('penduduks_count', 2, $kk);
    }

    /**
     * Regression: SQLSTATE[42S22] Unknown column 'kepala_keluarga' in 'WHERE'.
     *
     * The "Kepala Keluarga" column is a virtual ->state() column; there is no
     * `kartu_keluarga`.`kepala_keluarga` column. A bare ->searchable() made
     * Filament guess the column name from the column key and emit
     * `kepala_keluarga` LIKE ? inside the search WHERE group, so ANY search
     * term — even a single "m" — blew up the list page on MySQL.
     *
     * This asserts on the GENERATED SQL, not merely on the absence of an
     * exception: the test connection is SQLite, which silently treats the
     * unknown double-quoted identifier as a string literal and therefore does
     * NOT throw. Only MySQL does. Asserting the SQL makes the guard portable.
     */
    public function test_table_search_does_not_query_a_kepala_keluarga_column(): void
    {
        KartuKeluarga::factory()->create(['kk_number' => '7371010101010011']);

        $statements = [];

        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        Livewire::test(ListKartuKeluargas::class)
            ->searchTable('m')
            ->assertOk()
            ->assertHasNoErrors();

        $searchStatements = array_filter(
            $statements,
            fn (string $sql): bool => str_contains($sql, 'kartu_keluarga') && str_contains($sql, 'like')
        );

        $this->assertNotEmpty($searchStatements, 'Expected the search to hit the kartu_keluarga table.');

        foreach ($searchStatements as $sql) {
            $this->assertStringNotContainsString(
                'kepala_keluarga',
                $sql,
                'Search must not reference a non-existent kartu_keluarga.kepala_keluarga column.'
            );
        }

        $this->assertTrue(
            (bool) array_filter($searchStatements, fn (string $sql): bool => str_contains($sql, 'penduduk')),
            'Kepala Keluarga search must resolve through the penduduk relation.'
        );
    }

    public function test_table_can_search_by_kepala_keluarga_full_name(): void
    {
        $match = KartuKeluarga::factory()->create(['kk_number' => '7371010101010011']);
        $other = KartuKeluarga::factory()->create(['kk_number' => '7371010101010012']);

        Penduduk::factory()->create([
            'kk_id' => $match->id,
            'full_name' => 'FIRMAN HIDAYAT',
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
        ]);

        Penduduk::factory()->create([
            'kk_id' => $other->id,
            'full_name' => 'SITI AMINAH',
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
        ]);

        Livewire::test(ListKartuKeluargas::class)
            ->searchTable('FIRMAN HIDAYAT')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_table_can_search_by_partial_kepala_keluarga_name(): void
    {
        $match = KartuKeluarga::factory()->create(['kk_number' => '7371010101010013']);
        $other = KartuKeluarga::factory()->create(['kk_number' => '7371010101010014']);

        Penduduk::factory()->create([
            'kk_id' => $match->id,
            'full_name' => 'FIRMAN HIDAYAT',
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
        ]);

        Penduduk::factory()->create([
            'kk_id' => $other->id,
            'full_name' => 'SITI AMINAH',
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
        ]);

        Livewire::test(ListKartuKeluargas::class)
            ->searchTable('FIRMAN')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    /**
     * Only the KEPALA_KELUARGA member feeds this column, so searching the name
     * of a non-head member must NOT match the household through this path.
     */
    public function test_kepala_keluarga_search_ignores_non_head_members(): void
    {
        $kk = KartuKeluarga::factory()->create(['kk_number' => '7371010101010015']);

        Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'full_name' => 'FIRMAN HIDAYAT',
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
        ]);

        Penduduk::factory()->create([
            'kk_id' => $kk->id,
            'full_name' => 'ZULKARNAIN ANAK',
            'family_relation' => FamilyRelation::ANAK->value,
        ]);

        Livewire::test(ListKartuKeluargas::class)
            ->searchTable('ZULKARNAIN')
            ->assertCanNotSeeTableRecords([$kk]);
    }

    public function test_table_can_search_by_address(): void
    {
        $match = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010016',
            'address' => 'Jl. Dusun TANETE No. 12',
        ]);

        $other = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010017',
            'address' => 'Jl. Merdeka No. 5',
        ]);

        Livewire::test(ListKartuKeluargas::class)
            ->searchTable('TANETE')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_table_can_search_by_postal_code(): void
    {
        $match = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010018',
            'postal_code' => '90552',
        ]);

        $other = KartuKeluarga::factory()->create([
            'kk_number' => '7371010101010019',
            'postal_code' => '10110',
        ]);

        Livewire::test(ListKartuKeluargas::class)
            ->searchTable('90552')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_table_can_search_by_partial_kk_number(): void
    {
        $match = KartuKeluarga::factory()->create(['kk_number' => '7371001234567890']);
        $other = KartuKeluarga::factory()->create(['kk_number' => '1234567890123456']);

        Livewire::test(ListKartuKeluargas::class)
            ->searchTable('737100')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
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
