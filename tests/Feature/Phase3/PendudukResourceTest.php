<?php

namespace Tests\Feature\Phase3;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Filament\Resources\Penduduks\Pages\CreatePenduduk;
use App\Filament\Resources\Penduduks\Pages\EditPenduduk;
use App\Filament\Resources\Penduduks\Pages\ListPenduduks;
use App\Filament\Resources\Penduduks\PendudukResource;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\Occupation;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

/**
 * Phase 3.3 — Penduduk Resource feature tests.
 *
 * Covers rendering, validation, CRUD, search, sorting, pagination,
 * row actions and bulk delete. No OCR, no widgets (out of scope).
 */
class PendudukResourceTest extends Phase3ResourceTestCase
{
    /**
     * Valid form payload built from real lookup rows, so FK constraints hold.
     *
     * @return array<string, mixed>
     */
    protected function validPayload(array $overrides = []): array
    {
        return array_merge([
            'kk_id' => KartuKeluarga::factory()->create()->getKey(),
            'nik' => '7371010101010101',
            'full_name' => 'Andi Baso',
            'birth_place' => 'Bulukumba',
            'birth_date' => '1990-05-17',
            'gender' => Gender::LAKI_LAKI->value,
            'blood_type' => BloodType::O->value,
            'family_relation' => FamilyRelation::KEPALA_KELUARGA->value,
            'religion_id' => Religion::factory()->create()->getKey(),
            'education_id' => Education::factory()->create()->getKey(),
            'occupation_id' => Occupation::factory()->create()->getKey(),
            'marital_status' => MaritalStatus::KAWIN->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
        ], $overrides);
    }

    public function test_list_page_renders(): void
    {
        $records = Penduduk::factory()->count(3)->create();

        Livewire::test(ListPenduduks::class)
            ->assertOk()
            ->assertCanSeeTableRecords($records);
    }

    public function test_create_page_renders_all_fields(): void
    {
        Livewire::test(CreatePenduduk::class)
            ->assertOk()
            ->assertFormFieldExists('nik')
            ->assertFormFieldExists('full_name')
            ->assertFormFieldExists('kk_id')
            ->assertFormFieldExists('gender')
            ->assertFormFieldExists('birth_date')
            ->assertFormFieldExists('religion_id')
            ->assertFormFieldExists('education_id')
            ->assertFormFieldExists('occupation_id')
            ->assertFormFieldExists('marital_status')
            ->assertFormFieldExists('family_relation')
            ->assertFormFieldExists('blood_type')
            ->assertFormFieldExists('resident_status');
    }

    public function test_edit_page_renders_with_record_data(): void
    {
        $penduduk = Penduduk::factory()->create();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->assertOk()
            ->assertFormSet([
                'nik' => $penduduk->nik,
                'full_name' => $penduduk->full_name,
            ]);
    }

    /**
     * Wilayah (RW / Lingkungan & RT) is owned by the KK, not the Penduduk.
     * On edit the Penduduk form must NOT expose area_unit_id / rt_id — the
     * read-only wilayah placeholder reflects the KK's RT (ADR-004).
     */
    public function test_edit_page_has_no_area_or_rt_fields(): void
    {
        $penduduk = Penduduk::factory()->create();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->assertOk()
            ->assertFormFieldDoesNotExist('area_unit_id')
            ->assertFormFieldDoesNotExist('rt_id');
    }

    public function test_can_create_penduduk(): void
    {
        Livewire::test(CreatePenduduk::class)
            ->fillForm($this->validPayload())
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('penduduk', [
            'nik' => '7371010101010101',
            'full_name' => 'Andi Baso',
        ]);
    }

    public function test_can_edit_penduduk(): void
    {
        $penduduk = Penduduk::factory()->create();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm(['full_name' => 'Nama Diperbarui'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Nama Diperbarui', $penduduk->refresh()->full_name);
    }

    public function test_required_fields_are_validated(): void
    {
        Livewire::test(CreatePenduduk::class)
            ->fillForm([
                'nik' => null,
                'full_name' => null,
                'birth_place' => null,
                'birth_date' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'nik' => 'required',
                'full_name' => 'required',
                'birth_place' => 'required',
                'birth_date' => 'required',
            ]);
    }

    public function test_nik_must_be_sixteen_digits(): void
    {
        Livewire::test(CreatePenduduk::class)
            ->fillForm($this->validPayload(['nik' => '12345']))
            ->call('create')
            ->assertHasFormErrors(['nik']);
    }

    /**
     * NIK adalah identitas orang, bukan identitas KK.
     *
     * NIK yang sudah terdaftar TIDAK ditolak dan TIDAK menghasilkan
     * Penduduk kedua — orang yang sama dipindahkan ke KK yang dipilih.
     */
    public function test_existing_nik_is_reused_instead_of_duplicated(): void
    {
        $existing = Penduduk::factory()->create(['nik' => '7371010101010102']);
        $kkBaru = KartuKeluarga::factory()->create();

        Livewire::test(CreatePenduduk::class)
            ->fillForm($this->validPayload([
                'nik' => $existing->nik,
                'kk_id' => $kkBaru->getKey(),
            ]))
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(1, Penduduk::where('nik', '7371010101010102')->count());
        $this->assertSame($kkBaru->getKey(), $existing->refresh()->kk_id);
    }

    /**
     * Database tetap menjadi pengaman terakhir: NIK milik orang lain
     * tidak boleh diambil alih lewat Edit. Pesannya harus muncul di
     * field, bukan sebagai HTTP 500 dari unique index.
     */
    public function test_editing_cannot_take_over_another_residents_nik(): void
    {
        $lain = Penduduk::factory()->create(['nik' => '7371010101010105']);
        $penduduk = Penduduk::factory()->create(['nik' => '7371010101010106']);

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm(['nik' => $lain->nik])
            ->call('save')
            ->assertHasFormErrors(['nik']);

        $this->assertSame('7371010101010106', $penduduk->refresh()->nik);
    }

    public function test_editing_a_record_ignores_its_own_nik_for_uniqueness(): void
    {
        $penduduk = Penduduk::factory()->create();

        Livewire::test(EditPenduduk::class, ['record' => $penduduk->getKey()])
            ->fillForm(['nik' => $penduduk->nik, 'full_name' => 'Tetap Sama'])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_moved_date_is_required_when_status_is_pindah(): void
    {
        Livewire::test(CreatePenduduk::class)
            ->fillForm($this->validPayload([
                'resident_status' => ResidentStatus::PINDAH->value,
                'moved_at' => null,
            ]))
            ->call('create')
            ->assertHasFormErrors(['moved_at' => 'required']);
    }

    public function test_deceased_date_is_required_when_status_is_meninggal(): void
    {
        Livewire::test(CreatePenduduk::class)
            ->fillForm($this->validPayload([
                'resident_status' => ResidentStatus::MENINGGAL->value,
                'deceased_at' => null,
            ]))
            ->call('create')
            ->assertHasFormErrors(['deceased_at' => 'required']);
    }

    public function test_table_can_search_by_name(): void
    {
        $match = Penduduk::factory()->create(['full_name' => 'Sitti Aminah']);
        $other = Penduduk::factory()->create(['full_name' => 'Muhammad Yusuf']);

        Livewire::test(ListPenduduks::class)
            ->searchTable('Aminah')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_table_can_search_by_nik(): void
    {
        $match = Penduduk::factory()->create(['nik' => '7371010101010103']);
        $other = Penduduk::factory()->create(['nik' => '7371010101010104']);

        Livewire::test(ListPenduduks::class)
            ->searchTable('7371010101010103')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_table_can_search_by_kk_number(): void
    {
        $kk = KartuKeluarga::factory()->create(['kk_number' => '7371010101019999']);
        $match = Penduduk::factory()->create(['kk_id' => $kk->getKey()]);
        $other = Penduduk::factory()->create();

        Livewire::test(ListPenduduks::class)
            ->searchTable('7371010101019999')
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_table_can_sort_by_full_name(): void
    {
        $first = Penduduk::factory()->create(['full_name' => 'Ahmad Awal']);
        $last = Penduduk::factory()->create(['full_name' => 'Zulkifli Akhir']);

        Livewire::test(ListPenduduks::class)
            ->sortTable('full_name')
            ->assertCanSeeTableRecords([$first, $last], inOrder: true);
    }

    public function test_table_is_paginated(): void
    {
        Penduduk::factory()->count(30)->create();

        Livewire::test(ListPenduduks::class)
            ->assertCountTableRecords(30)
            ->set('tableRecordsPerPage', 10)
            ->assertOk();
    }

    public function test_can_delete_via_row_action(): void
    {
        $penduduk = Penduduk::factory()->create();

        Livewire::test(ListPenduduks::class)
            ->callAction(TestAction::make('delete')->table($penduduk))
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('penduduk', ['id' => $penduduk->id]);
    }

    public function test_can_delete_via_bulk_action(): void
    {
        $records = Penduduk::factory()->count(2)->create();

        Livewire::test(ListPenduduks::class)
            ->selectTableRecords($records->pluck('id')->all())
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertHasNoErrors();

        foreach ($records as $record) {
            $this->assertDatabaseMissing('penduduk', ['id' => $record->id]);
        }
    }

    public function test_resource_navigation_metadata(): void
    {
        $this->assertSame('Kependudukan', PendudukResource::getNavigationGroup());
        $this->assertSame('Penduduk', PendudukResource::getNavigationLabel());
        $this->assertSame('Penduduk', PendudukResource::getPluralModelLabel());
    }
}
