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
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Anggota keluarga (Penduduk) belonging to one Kartu Keluarga.
 *
 * Uses the model's existing `penduduks()` HasMany relation. Members are
 * managed in place — create / edit / view open modals on the KK page and the
 * KK of each new member is bound automatically via the relationship, so the
 * operator never leaves the family screen (UI-5: no forced KK <-> Penduduk
 * navigation).
 */
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
        return PendudukForm::configure($schema, ['hide_kk_id' => true]);
    }

    public function infolist(Schema $schema): Schema
    {
        return PendudukForm::configure($schema, ['hide_kk_id' => true]);
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
                    ->width('180px'),
                TextColumn::make('full_name')
                    ->label('Nama Lengkap')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->width('240px'),
                TextColumn::make('family_relation')
                    ->label('Hubungan Keluarga')
                    ->badge()
                    ->formatStateUsing(fn (FamilyRelation $state): string => match ($state) {
                        FamilyRelation::KEPALA_KELUARGA => 'Kepala Keluarga',
                        FamilyRelation::ISTRI => 'Istri',
                        FamilyRelation::ANAK => 'Anak',
                        FamilyRelation::MENANTU => 'Menantu',
                        FamilyRelation::CUCU => 'Cucu',
                        FamilyRelation::ORANG_TUA => 'Orang Tua',
                        FamilyRelation::MERTUA => 'Mertua',
                        FamilyRelation::FAMILI_LAIN => 'Famili Lain',
                        FamilyRelation::LAINNYA => 'Lainnya',
                    })
                    ->sortable()
                    ->width('180px'),
                TextColumn::make('gender')
                    ->label('Jenis Kelamin')
                    ->formatStateUsing(fn (Gender $state): string => match ($state) {
                        Gender::LAKI_LAKI => 'Laki-laki',
                        Gender::PEREMPUAN => 'Perempuan',
                    })
                    ->sortable()
                    ->width('140px'),
                TextColumn::make('birth_date')
                    ->label('Tanggal Lahir')
                    ->date('d M Y')
                    ->sortable()
                    ->width('150px'),
                TextColumn::make('age')
                    ->label('Usia')
                    ->state(fn ($record): int => (int) $record->age)
                    ->suffix(' th')
                    ->sortable()
                    ->width('100px'),
                TextColumn::make('resident_status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (ResidentStatus $state): string => match ($state) {
                        ResidentStatus::ACTIVE => 'Aktif',
                        ResidentStatus::PINDAH => 'Pindah',
                        ResidentStatus::MENINGGAL => 'Meninggal',
                    })
                    ->color(fn (ResidentStatus $state): string => match ($state) {
                        ResidentStatus::ACTIVE => 'success',
                        ResidentStatus::PINDAH => 'warning',
                        ResidentStatus::MENINGGAL => 'danger',
                    })
                    ->sortable()
                    ->width('120px'),
            ])
            ->filters([
                // Filter anggota keluarga by status kependudukan.
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
                    ->modalHeading('Detail Anggota'),
                EditAction::make()
                    ->modalHeading('Ubah Anggota'),
                DeleteAction::make()
                    ->modalHeading('Hapus Anggota')
                    ->modalDescription('Data anggota ini tidak dapat dikembalikan. Lanjutkan?'),
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
            ->emptyStateDescription('Tambahkan anggota keluarga untuk Kartu Keluarga ini.')
            ->emptyStateIcon(Heroicon::OutlinedUserPlus);
    }
}
