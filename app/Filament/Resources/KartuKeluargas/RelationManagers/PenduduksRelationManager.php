<?php

namespace App\Filament\Resources\KartuKeluargas\RelationManagers;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Filament\Resources\Penduduks\Schemas\PendudukForm;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PenduduksRelationManager extends RelationManager
{
    protected static string $relationship = 'penduduks';

    protected static ?string $title = 'Anggota Keluarga';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->penduduks()->count();
    }

    public function form(Schema $schema): Schema
    {
        return PendudukForm::configure(
            $schema,
            ['hide_kk_id' => true],
        );
    }

    /**
     * Tampilan detail anggota menggunakan infolist,
     * bukan PendudukForm.
     */
    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Identitas Penduduk')
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'lg' => 3,
                        ])
                            ->schema([
                                TextEntry::make('nik')
                                    ->label('NIK')
                                    ->copyable(),

                                TextEntry::make('full_name')
                                    ->label('Nama Lengkap'),

                                TextEntry::make('gender')
                                    ->label('Jenis Kelamin')
                                    ->formatStateUsing(
                                        fn (Gender|string|null $state): string => match ($state instanceof Gender ? $state : Gender::tryFrom((string) $state)) {
                                            Gender::LAKI_LAKI => 'Laki-laki',
                                            Gender::PEREMPUAN => 'Perempuan',
                                            default => '-',
                                        }
                                    ),

                                TextEntry::make('birth_place')
                                    ->label('Tempat Lahir'),

                                TextEntry::make('birth_date')
                                    ->label('Tanggal Lahir')
                                    ->date('d M Y'),

                                TextEntry::make('age')
                                    ->label('Usia')
                                    ->state(fn ($record): string => $record->age.' tahun'),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Kartu Keluarga & Wilayah')
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'lg' => 3,
                        ])
                            ->schema([
                                TextEntry::make('kartuKeluarga.kk_number')
                                    ->label('Nomor KK'),

                                TextEntry::make('family_relation')
                                    ->label('Hubungan Keluarga')
                                    ->badge()
                                    ->formatStateUsing(
                                        fn (FamilyRelation|string|null $state): string => match ($state instanceof FamilyRelation ? $state : FamilyRelation::tryFrom((string) $state)) {
                                            FamilyRelation::KEPALA_KELUARGA => 'Kepala Keluarga',
                                            FamilyRelation::ISTRI => 'Istri',
                                            FamilyRelation::ANAK => 'Anak',
                                            FamilyRelation::MENANTU => 'Menantu',
                                            FamilyRelation::CUCU => 'Cucu',
                                            FamilyRelation::ORANG_TUA => 'Orang Tua',
                                            FamilyRelation::MERTUA => 'Mertua',
                                            FamilyRelation::FAMILI_LAIN => 'Famili Lain',
                                            FamilyRelation::LAINNYA => 'Lainnya',
                                            default => '-',
                                        }
                                    ),

                                TextEntry::make('rt.number')
                                    ->label('RT')
                                    ->formatStateUsing(
                                        fn ($state): string => filled($state)
                                            ? 'RT '.$state
                                            : '-',
                                    ),
                            ]),
                    ])
                    ->columnSpanFull(),

                Section::make('Data Sosial & Kependudukan')
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'md' => 2,
                            'lg' => 4,
                        ])
                            ->schema([
                                TextEntry::make('religion.name')
                                    ->label('Agama'),

                                TextEntry::make('education.name')
                                    ->label('Pendidikan'),

                                TextEntry::make('occupation.name')
                                    ->label('Pekerjaan'),

                                TextEntry::make('marital_status')
                                    ->label('Status Perkawinan'),

                                TextEntry::make('blood_type')
                                    ->label('Golongan Darah'),

                                TextEntry::make('resident_status')
                                    ->label('Status Kependudukan')
                                    ->badge()
                                    ->formatStateUsing(
                                        fn (ResidentStatus|string|null $state): string => match ($state instanceof ResidentStatus ? $state : ResidentStatus::tryFrom((string) $state)) {
                                            ResidentStatus::ACTIVE => 'Aktif',
                                            ResidentStatus::PINDAH => 'Pindah',
                                            ResidentStatus::MENINGGAL => 'Meninggal',
                                            default => '-',
                                        }
                                    ),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('full_name')
            ->columns([
                TextColumn::make('nik')
                    ->label('NIK')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('NIK disalin')
                    ->extraAttributes([
                        'class' => 'whitespace-nowrap',
                    ]),

                TextColumn::make('full_name')
                    ->label('Nama Lengkap')
                    ->searchable()
                    ->sortable()
                    ->extraAttributes([
                        'class' => 'whitespace-nowrap',
                    ]),

                TextColumn::make('family_relation')
                    ->label('Hubungan Keluarga')
                    ->badge()
                    ->formatStateUsing(
                        fn (FamilyRelation $state): string => match ($state) {
                            FamilyRelation::KEPALA_KELUARGA => 'Kepala Keluarga',
                            FamilyRelation::ISTRI => 'Istri',
                            FamilyRelation::ANAK => 'Anak',
                            FamilyRelation::MENANTU => 'Menantu',
                            FamilyRelation::CUCU => 'Cucu',
                            FamilyRelation::ORANG_TUA => 'Orang Tua',
                            FamilyRelation::MERTUA => 'Mertua',
                            FamilyRelation::FAMILI_LAIN => 'Famili Lain',
                            FamilyRelation::LAINNYA => 'Lainnya',
                        }
                    )
                    ->sortable(),

                TextColumn::make('gender')
                    ->label('Jenis Kelamin')
                    ->formatStateUsing(
                        fn (Gender $state): string => match ($state) {
                            Gender::LAKI_LAKI => 'Laki-laki',
                            Gender::PEREMPUAN => 'Perempuan',
                        }
                    )
                    ->sortable(),

                TextColumn::make('birth_date')
                    ->label('Tanggal Lahir')
                    ->date('d M Y')
                    ->sortable()
                    ->extraAttributes([
                        'class' => 'whitespace-nowrap',
                    ]),

                TextColumn::make('age')
                    ->label('Usia')
                    ->state(fn ($record): int => (int) $record->age)
                    ->suffix(' th')
                    ->sortable()
                    ->extraAttributes([
                        'class' => 'whitespace-nowrap',
                    ]),

                TextColumn::make('resident_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(
                        fn (ResidentStatus $state): string => match ($state) {
                            ResidentStatus::ACTIVE => 'Aktif',
                            ResidentStatus::PINDAH => 'Pindah',
                            ResidentStatus::MENINGGAL => 'Meninggal',
                        }
                    )
                    ->color(
                        fn (ResidentStatus $state): string => match ($state) {
                            ResidentStatus::ACTIVE => 'success',
                            ResidentStatus::PINDAH => 'warning',
                            ResidentStatus::MENINGGAL => 'danger',
                        }
                    )
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('resident_status')
                    ->label('Status')
                    ->options([
                        ResidentStatus::ACTIVE->value => 'Aktif',
                        ResidentStatus::PINDAH->value => 'Pindah',
                        ResidentStatus::MENINGGAL->value => 'Meninggal',
                    ]),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Tambah Anggota')
                    ->modalHeading('Tambah Anggota Keluarga'),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading('Detail Anggota')
                    ->modalWidth('5xl'),

                EditAction::make()
                    ->modalHeading('Ubah Anggota')
                    ->modalWidth('5xl'),

                DeleteAction::make()
                    ->modalHeading('Hapus Anggota')
                    ->modalDescription(
                        'Data anggota ini tidak dapat dikembalikan. Lanjutkan?'
                    ),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Hapus terpilih'),
                ]),
            ])
            ->defaultSort('family_relation')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Belum ada anggota keluarga')
            ->emptyStateDescription(
                'Tambahkan anggota keluarga untuk Kartu Keluarga ini.'
            )
            ->emptyStateIcon(Heroicon::OutlinedUserPlus);
    }
}
