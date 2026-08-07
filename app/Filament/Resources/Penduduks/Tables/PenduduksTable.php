<?php

namespace App\Filament\Resources\Penduduks\Tables;

use App\Enums\ExportFormat;
use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Services\PendudukExportService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class PenduduksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('nik')
                    ->label('NIK')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('full_name')
                    ->label('Nama Lengkap')
                    ->searchable()
                    ->sortable()
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('kartuKeluarga.kk_number')
                    ->label('Nomor KK')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->url(fn ($record): ?string => $record->kk_id
                        ? KartuKeluargaResource::getUrl('edit', ['record' => $record->kk_id])
                        : null)
                    ->tooltip('Buka Kartu Keluarga')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('gender')
                    ->label('Jenis Kelamin')
                    ->badge()
                    ->formatStateUsing(fn (Gender $state): string => match ($state) {
                        Gender::LAKI_LAKI => 'Laki-laki',
                        Gender::PEREMPUAN => 'Perempuan',
                    })
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('birth_date')
                    ->label('Tanggal Lahir')
                    ->date('d M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('age')
                    ->label('Usia')
                    ->state(fn ($record): int => $record->age)
                    ->suffix(' th')
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('rt.number')
                    ->label('RT')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: false),
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
                    ->toggleable(isToggledHiddenByDefault: false),
                TextColumn::make('religion.name')
                    ->label('Agama')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('education.name')
                    ->label('Pendidikan')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('occupation.name')
                    ->label('Pekerjaan')
                    ->sortable()
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Lihat'),
                EditAction::make()
                    ->label('Ubah'),
                DeleteAction::make()
                    ->label('Hapus')
                    ->modalHeading('Hapus Data Penduduk')
                    ->modalDescription('Data yang dihapus tidak dapat dikembalikan. Lanjutkan?')
                    ->successNotificationTitle('Data penduduk berhasil dihapus'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Hapus yang dipilih')
                        ->modalHeading('Hapus data penduduk terpilih')
                        ->modalDescription('Data yang dihapus tidak dapat dikembalikan. Lanjutkan?')
                        ->successNotificationTitle('Data penduduk terpilih berhasil dihapus'),
                ]),
                Action::make('export_csv')
                    ->label('CSV')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->requiresConfirmation(false)
                    ->action(fn (HasTable $livewire) => app(PendudukExportService::class)
                        ->exportQuery($livewire->getFilteredTableQuery(), ExportFormat::CSV)),
                Action::make('export_xlsx')
                    ->label('Excel')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->requiresConfirmation(false)
                    ->action(fn (HasTable $livewire) => app(PendudukExportService::class)
                        ->exportQuery($livewire->getFilteredTableQuery(), ExportFormat::XLSX)),
                Action::make('export_pdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->requiresConfirmation(false)
                    ->action(fn (HasTable $livewire) => app(PendudukExportService::class)
                        ->exportQuery($livewire->getFilteredTableQuery(), ExportFormat::PDF)),
            ])
            ->defaultSort('full_name')
            ->recordTitleAttribute('full_name')
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading('Belum ada data penduduk')
            ->emptyStateDescription('Mulai dengan menambahkan data penduduk pertama.')
            ->emptyStateIcon(Heroicon::OutlinedUserPlus);
    }
}
