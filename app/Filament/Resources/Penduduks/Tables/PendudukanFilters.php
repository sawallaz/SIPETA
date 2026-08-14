<?php

namespace App\Filament\Resources\Penduduks\Tables;

use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Models\Rt;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Phase UI-2 — complete operator filtering for the Penduduk list.
 *
 * Order of the area filters is intentional: RW (area_unit) comes
 * FIRST, then RT. RT options are scoped to the selected RW so that
 * "RT 01" from RW I and "RT 01" from RW II never mix, and the
 * dropdown is labelled "RT 01", "RT 02", … instead of bare "01".
 *
 * Every filter name is preserved (F-CORE-06) so the existing feature tests that
 * call filterTable('rt', …) / filterTable('area_unit', …) keep passing.
 *
 * Age presets come from config('penduduk.age_presets') and are translated to a
 * birth_date span via Penduduk::scopeAgeRange() — the SAME query the export
 * service reuses, so the age predicate is never duplicated. Filament's built-in
 * "Reset" button in the filters popover clears every filter (F-HIGH-05).
 */
class PendudukanFilters
{
    /** @return array<int, Filter|SelectFilter> */
    public static function build(): array
    {
        return [
            // ---- Search ----------------------------------------------------
            self::textFilter('nama', 'Nama', 'full_name'),
            self::textFilter('nik', 'NIK', 'nik'),
            Filter::make('kk_number')
                ->label('Nomor KK')
                ->schema([
                    TextInput::make('query')->label('Nomor KK')->placeholder('Cari nomor KK'),
                ])
                ->query(fn (Builder $query, array $data): Builder => filled($data['query'] ?? null)
                    ? $query->whereHas('kartuKeluarga', function (Builder $q) use ($data): void {
                        $q->where('kk_number', 'like', "%{$data['query']}%");
                    })
                    : $query),

            // ---- Area: RW FIRST -------------------------------
            SelectFilter::make('area_unit')
                ->label('RW')
                ->relationship('rt.areaUnit', 'name')
                ->preload()
                ->searchable()
                ->modifyFormFieldUsing(fn (Select $field): Select => $field
                    ->placeholder('Pilih RW')
                    ->live()),

            // ---- Area: RT (scoped to the chosen RW) -----------
            SelectFilter::make('rt')
                ->label('RT')
                ->relationship('rt', 'number')
                ->searchable()
                ->modifyFormFieldUsing(function (Select $field, $livewire): Select {
                    return $field
                        ->placeholder('Pilih RW terlebih dahulu')
                        ->live()
                        ->disabled(fn (): bool => blank(self::selectedAreaUnitId($livewire)))
                        ->options(fn (): array => self::rtOptions($livewire));
                }),

            // ---- Lookup tables ---------------------------------------------
            SelectFilter::make('gender')
                ->label('Jenis Kelamin')
                ->options([
                    Gender::LAKI_LAKI->value => 'Laki-laki',
                    Gender::PEREMPUAN->value => 'Perempuan',
                ]),
            SelectFilter::make('religion_id')
                ->label('Agama')
                ->relationship('religion', 'name')
                ->preload()
                ->searchable(),
            SelectFilter::make('education_id')
                ->label('Pendidikan')
                ->relationship('education', 'name')
                ->preload()
                ->searchable(),
            SelectFilter::make('occupation_id')
                ->label('Pekerjaan')
                ->relationship('occupation', 'name')
                ->preload()
                ->searchable(),
            SelectFilter::make('resident_status')
                ->label('Status Penduduk')
                ->options([
                    ResidentStatus::ACTIVE->value => 'Aktif',
                    ResidentStatus::PINDAH->value => 'Pindah',
                    ResidentStatus::MENINGGAL->value => 'Meninggal',
                ]),

            // ---- Age: preset -----------------------------------------------
            SelectFilter::make('age_preset')
                ->label('Usia (Preset)')
                ->options(
                    collect(config('penduduk.age_presets'))
                        ->mapWithKeys(fn (array $preset, string $key): array => [
                            $key => $preset['label'],
                        ])
                        ->all()
                )
                ->query(function (Builder $query, array $data): Builder {
                    $presetKey = $data['value'] ?? '';
                    $preset = config("penduduk.age_presets.{$presetKey}", []);

                    if ($preset === []) {
                        return $query;
                    }

                    return $query->where(function (Builder $q) use ($preset): void {
                        $q->ageRange($preset['min'], $preset['max']);
                    });
                }),

            // ---- Age (custom min/max) --------------------------------------
            Filter::make('age')
                ->label('Usia (Kustom)')
                ->schema([
                    TextInput::make('min')->label('Minimum')->numeric()->minValue(0),
                    TextInput::make('max')->label('Maksimum')->numeric()->minValue(0),
                ])
                ->query(function (Builder $query, array $data): Builder {
                    $hasMin = filled($data['min'] ?? null);
                    $hasMax = filled($data['max'] ?? null);

                    if (! $hasMin && ! $hasMax) {
                        return $query;
                    }

                    return $query->where(function (Builder $q) use ($data, $hasMin, $hasMax): void {
                        $q->ageRange(
                            $hasMin ? (int) $data['min'] : null,
                            $hasMax ? (int) $data['max'] : null,
                        );
                    });
                }),
        ];
    }

    /**
     * Read the RW / Lingkungan currently chosen *inside the open filter form*.
     *
     * Uses the live (deferred) filter form state so the RT dropdown reacts to
     * the operator's in-popover selection rather than the last applied value.
     */
    private static function selectedAreaUnitId($livewire): ?int
    {
        /** @var HasTable $livewire */
        $state = $livewire->getTableFilterFormState('area_unit') ?? [];

        $value = $state['value'] ?? null;

        return filled($value) ? (int) $value : null;
    }

    /**
     * RT options scoped to the selected RW, labelled "RT 01" etc.
     * so duplicate numbers across RW never collide in the dropdown.
     */
    private static function rtOptions($livewire): array
    {
        $areaUnitId = self::selectedAreaUnitId($livewire);

        if ($areaUnitId === null) {
            return [];
        }

        return Rt::query()
            ->where('area_unit_id', $areaUnitId)
            ->orderBy('number')
            ->get()
            ->mapWithKeys(fn (Rt $rt): array => [
                $rt->id => 'RT '.$rt->number,
            ])
            ->all();
    }

    private static function textFilter(string $name, string $label, string $column): Filter
    {
        return Filter::make($name)
            ->label($label)
            ->schema([
                TextInput::make('query')->label($label),
            ])
            ->query(fn (Builder $query, array $data): Builder => filled($data['query'] ?? null)
                ? $query->where($column, 'like', "%{$data['query']}%")
                : $query);
    }
}
