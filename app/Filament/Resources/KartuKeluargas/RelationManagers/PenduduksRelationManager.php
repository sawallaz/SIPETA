<?php

namespace App\Filament\Resources\KartuKeluargas\RelationManagers;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Filament\Resources\Penduduks\PendudukResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Anggota keluarga (Penduduk) belonging to one Kartu Keluarga.
 *
 * Uses the model's existing `penduduks()` HasMany relation. Linked to
 * PendudukResource so create/edit/view open the full Penduduk pages
 * (family navigation) instead of reduced modals.
 */
class PenduduksRelationManager extends RelationManager
{
    protected static string $relationship = 'penduduks';

    protected static ?string $relatedResource = PendudukResource::class;

    protected static ?string $title = 'Anggota Keluarga';

    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return (string) $ownerRecord->penduduks()->count();
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
                    ->copyable(),
                TextColumn::make('full_name')
                    ->label('Nama Lengkap')
                    ->searchable()
                    ->sortable()
                    ->wrap(),
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
                    ->sortable(),
                TextColumn::make('gender')
                    ->label('Jenis Kelamin')
                    ->formatStateUsing(fn (Gender $state): string => match ($state) {
                        Gender::LAKI_LAKI => 'Laki-laki',
                        Gender::PEREMPUAN => 'Perempuan',
                    })
                    ->sortable(),
                TextColumn::make('birth_date')
                    ->label('Tanggal Lahir')
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('age')
                    ->label('Usia')
                    ->state(fn ($record): int => $record->age)
                    ->suffix(' th'),
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
                    ->sortable(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Tambah Anggota')
                    ->url(fn (): string => PendudukResource::getUrl('create', [
                        'kk_id' => $this->getOwnerRecord()->getKey(),
                    ])),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('family_relation')
            ->emptyStateHeading('Belum ada anggota keluarga')
            ->emptyStateDescription('Tambahkan anggota keluarga untuk Kartu Keluarga ini.')
            ->emptyStateIcon(Heroicon::OutlinedUserPlus);
    }
}
