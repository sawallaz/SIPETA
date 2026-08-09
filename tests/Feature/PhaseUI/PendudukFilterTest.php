<?php

namespace Tests\Feature\PhaseUI;

use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Filament\Resources\Penduduks\Pages\ListPenduduks;
use App\Filament\Resources\Penduduks\Tables\PendudukanFilters;
use App\Models\AreaUnit;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\Occupation;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Feature\Phase3\Phase3ResourceTestCase;

/**
 * Phase UI-2 — Complete operator filtering on the Penduduk list.
 *
 * Drives the real Filament table component (Livewire) and asserts each
 * documented filter narrows the visible records — search (NIK, Nama, Nomor KK),
 * RT, RW/Lingkungan, lookups (gender, religion, education, occupation, status),
 * age presets and custom min/max — plus combined AND filters and a reset that
 * restores every row, with no runtime exception.
 */
class PendudukFilterTest extends Phase3ResourceTestCase
{
    public function test_search_filter_by_name(): void
    {
        $match = Penduduk::factory()->create(['full_name' => 'Sitti Aminah']);
        $other = Penduduk::factory()->create(['full_name' => 'Muhammad Yusuf']);

        $this->assertPenduduksFiltered(
            fn () => Livewire::test(ListPenduduks::class)
                ->filterTable('nama', ['query' => 'Aminah']),
            $match,
            $other,
        );
    }

    public function test_search_filter_by_nik(): void
    {
        $match = Penduduk::factory()->create(['nik' => '7371010101010101']);
        $other = Penduduk::factory()->create(['nik' => '7371010101010102']);

        $this->assertPenduduksFiltered(
            fn () => Livewire::test(ListPenduduks::class)
                ->filterTable('nik', ['query' => '7371010101010101']),
            $match,
            $other,
        );
    }

    public function test_search_filter_by_kk_number(): void
    {
        $kk = KartuKeluarga::factory()->create(['kk_number' => '7371000000000001']);
        $match = Penduduk::factory()->create(['kk_id' => $kk->id]);
        $other = Penduduk::factory()->create();

        $this->assertPenduduksFiltered(
            fn () => Livewire::test(ListPenduduks::class)
                ->filterTable('kk_number', ['query' => '7371000000000001']),
            $match,
            $other,
        );
    }

    public function test_filter_by_gender(): void
    {
        $match = Penduduk::factory()->create(['gender' => Gender::PEREMPUAN->value]);
        $other = Penduduk::factory()->create(['gender' => Gender::LAKI_LAKI->value]);

        $this->assertPenduduksFiltered(
            fn () => Livewire::test(ListPenduduks::class)
                ->filterTable('gender', Gender::PEREMPUAN->value),
            $match,
            $other,
        );
    }

    public function test_filter_by_religion(): void
    {
        $religion = Religion::factory()->create();
        $otherReligion = Religion::factory()->create();
        $match = Penduduk::factory()->create(['religion_id' => $religion->id]);
        $other = Penduduk::factory()->create(['religion_id' => $otherReligion->id]);

        $this->assertPenduduksFiltered(
            fn () => Livewire::test(ListPenduduks::class)
                ->filterTable('religion_id', $religion->id),
            $match,
            $other,
        );
    }

    public function test_filter_by_education(): void
    {
        $education = Education::factory()->create();
        $otherEducation = Education::factory()->create();
        $match = Penduduk::factory()->create(['education_id' => $education->id]);
        $other = Penduduk::factory()->create(['education_id' => $otherEducation->id]);

        $this->assertPenduduksFiltered(
            fn () => Livewire::test(ListPenduduks::class)
                ->filterTable('education_id', $education->id),
            $match,
            $other,
        );
    }

    public function test_filter_by_occupation(): void
    {
        $occupation = Occupation::factory()->create();
        $otherOccupation = Occupation::factory()->create();
        $match = Penduduk::factory()->create(['occupation_id' => $occupation->id]);
        $other = Penduduk::factory()->create(['occupation_id' => $otherOccupation->id]);

        $this->assertPenduduksFiltered(
            fn () => Livewire::test(ListPenduduks::class)
                ->filterTable('occupation_id', $occupation->id),
            $match,
            $other,
        );
    }

    public function test_filter_by_resident_status(): void
    {
        $match = Penduduk::factory()->create(['resident_status' => ResidentStatus::MENINGGAL->value]);
        $other = Penduduk::factory()->create(['resident_status' => ResidentStatus::ACTIVE->value]);

        $this->assertPenduduksFiltered(
            fn () => Livewire::test(ListPenduduks::class)
                ->filterTable('resident_status', ResidentStatus::MENINGGAL->value),
            $match,
            $other,
        );
    }

    public function test_filter_by_rt(): void
    {
        $rt = Rt::factory()->create(['number' => '001']);
        $otherRt = Rt::factory()->create(['number' => '002']);
        $match = Penduduk::factory()->create(['kk_id' => KartuKeluarga::factory()->create(['rt_id' => $rt->id])]);
        $other = Penduduk::factory()->create(['kk_id' => KartuKeluarga::factory()->create(['rt_id' => $otherRt->id])]);

        $this->assertPenduduksFiltered(
            fn () => Livewire::test(ListPenduduks::class)
                ->filterTable('rt', $rt->id),
            $match,
            $other,
        );
    }

    public function test_filter_by_area_unit_rw_lingkungan(): void
    {
        $areaUnit = AreaUnit::factory()->create();
        $otherArea = AreaUnit::factory()->create();
        $rt = Rt::factory()->create(['area_unit_id' => $areaUnit->id]);
        $otherRt = Rt::factory()->create(['area_unit_id' => $otherArea->id]);
        $match = Penduduk::factory()->create(['kk_id' => KartuKeluarga::factory()->create(['rt_id' => $rt->id])]);
        $other = Penduduk::factory()->create(['kk_id' => KartuKeluarga::factory()->create(['rt_id' => $otherRt->id])]);

        $this->assertPenduduksFiltered(
            fn () => Livewire::test(ListPenduduks::class)
                ->filterTable('area_unit', $areaUnit->id),
            $match,
            $other,
        );
    }

    public function test_age_preset_filters_balita(): void
    {
        $balita = Penduduk::factory()->create(['birth_date' => now()->subYears(5)->format('Y-m-d')]);
        $dewasa = Penduduk::factory()->create(['birth_date' => now()->subYears(30)->format('Y-m-d')]);

        $this->assertPenduduksFiltered(
            fn () => Livewire::test(ListPenduduks::class)
                ->filterTable('age_preset', 'balita'),
            $balita,
            $dewasa,
        );
    }

    public function test_custom_age_min_max_filters(): void
    {
        $inRange = Penduduk::factory()->create(['birth_date' => now()->subYears(25)->format('Y-m-d')]);
        $tooYoung = Penduduk::factory()->create(['birth_date' => now()->subYears(10)->format('Y-m-d')]);
        $tooOld = Penduduk::factory()->create(['birth_date' => now()->subYears(2)->format('Y-m-d')]);

        Livewire::test(ListPenduduks::class)
            ->filterTable('age', ['min' => 20, 'max' => 30])
            ->assertCanSeeTableRecords([$inRange])
            ->assertCanNotSeeTableRecords([$tooYoung, $tooOld]);
    }

    public function test_multiple_filters_combine_with_and(): void
    {
        $both = Penduduk::factory()->create([
            'gender' => Gender::PEREMPUAN->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
        ]);
        $wrongGender = Penduduk::factory()->create([
            'gender' => Gender::LAKI_LAKI->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
        ]);

        Livewire::test(ListPenduduks::class)
            ->filterTable('gender', Gender::PEREMPUAN->value)
            ->filterTable('resident_status', ResidentStatus::ACTIVE->value)
            ->assertCanSeeTableRecords([$both])
            ->assertCanNotSeeTableRecords([$wrongGender]);
    }

    public function test_reset_filters_restores_all_records(): void
    {
        $a = Penduduk::factory()->create(['gender' => Gender::PEREMPUAN->value]);
        $b = Penduduk::factory()->create(['gender' => Gender::LAKI_LAKI->value]);

        Livewire::test(ListPenduduks::class)
            ->filterTable('gender', Gender::PEREMPUAN->value)
            ->assertCanSeeTableRecords([$a])
            ->assertCanNotSeeTableRecords([$b])
            ->resetTableFilters()
            ->assertCanSeeTableRecords([$a, $b]);
    }

    public function test_all_filters_are_registered(): void
    {
        $names = collect(PendudukanFilters::build())
            ->map(fn ($filter) => $filter->getName())
            ->all();

        $this->assertEqualsCanonicalizing([
            'nama', 'nik', 'kk_number', 'rt', 'area_unit', 'gender',
            'religion_id', 'education_id', 'occupation_id', 'resident_status',
            'age_preset', 'age',
        ], $names);
    }

    /** @param \Closure(): mixed $build */
    private function assertPenduduksFiltered(\Closure $build, Penduduk $match, Penduduk $other): void
    {
        tap($build())
            ->assertCanSeeTableRecords([$match])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_rt_filter_is_scoped_to_selected_lingkungan(): void
    {
        $lingkunganA = AreaUnit::factory()->create();
        $lingkunganB = AreaUnit::factory()->create();

        $rtA = Rt::factory()->create(['area_unit_id' => $lingkunganA->id, 'number' => '01']);
        $rtB = Rt::factory()->create(['area_unit_id' => $lingkunganB->id, 'number' => '01']);

        $component = Livewire::test(ListPenduduks::class);

        // Before any RW / Lingkungan is chosen, RT offers no real options.
        $rtOptionsBefore = $this->rtFilterOptions($component);
        $this->assertEmpty($rtOptionsBefore);

        // Choose RW / Lingkungan A through the (deferred) filter form state.
        $component->set('tableDeferredFilters.area_unit', ['value' => $lingkunganA->id]);

        $rtOptionsAfter = $this->rtFilterOptions($component);

        // Only the RT(s) belonging to Lingkungan A appear, labelled "RT 01".
        $this->assertArrayHasKey($rtA->id, $rtOptionsAfter);
        $this->assertArrayNotHasKey($rtB->id, $rtOptionsAfter);
        $this->assertSame('RT 01', $rtOptionsAfter[$rtA->id]);
    }

    /**
     * Resolve the live RT Select options from the filter form field, exactly as
     * the popover would render them while open.
     *
     * @return array<int|string, string>
     */
    private function rtFilterOptions(Testable $component): array
    {
        $filter = $component->instance()->getTable()->getFilter('rt');

        // getSchemaComponents() applies the modifyFormFieldUsing hook, so the
        // live()/options() cascade (scoping RT to the chosen lingkungan) runs.
        $field = $filter->getSchemaComponents()[0];

        return $field->getOptions();
    }
}
