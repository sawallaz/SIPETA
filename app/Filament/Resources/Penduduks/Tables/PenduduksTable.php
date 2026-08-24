<?php

namespace App\Filament\Resources\Penduduks\Tables;

use App\Enums\Gender;
use App\Enums\ResidentStatus;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\Penduduks\PendudukResource;
use App\Models\Penduduk;
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
            ->recordUrl(null)
            ->deferFilters()
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
                TextColumn::make('ktp_document')
                    ->label('KTP')
                    ->html()
                    ->state(function (Penduduk $record) {
                        $ktpDoc = $record->documents()->where('document_type', 'KTP')->where('is_active', true)->latest('id')->first();

                        if ($ktpDoc === null) {
                            return '<span class="text-gray-400">—</span>';
                        }

                        $url = route('penduduk-documents.preview', $ktpDoc);
                        $isImage = in_array($ktpDoc->mime_type, ['image/jpeg', 'image/png'], true);

                        if ($isImage) {
                            return '<div x-data="{ openDoc: false, docUrl: \'\', docTitle: \'\' }"'
                                .' class="relative inline-block">'
                                .'<img src="'.e($url).'"'
                                .' style="height: 40px; width: auto; object-fit: cover; border-radius: 4px;"'
                                .' @click.prevent="openDoc = true; docUrl = \''.addslashes($url).'\'; docTitle = \'Lihat KTP\'"'
                                .' class="cursor-pointer hover:opacity-80"'
                                .' title="Lihat KTP">'
                                .'<template x-if="openDoc">'
                                .'<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="openDoc = false" x-cloak>'
                                .'<div class="relative max-w-3xl w-full bg-white rounded-lg shadow-2xl overflow-hidden" @click.stop>'
                                .'<div class="flex items-center justify-between px-5 py-4 border-b">'
                                .'<h3 class="text-lg font-semibold text-gray-800 truncate pr-4" x-text="docTitle"></h3>'
                                .'<button @click="openDoc = false" class="w-8 h-8 flex items-center justify-center rounded-full text-gray-500 hover:bg-gray-200">'
                                .'<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>'
                                .'</button>'
                                .'</div>'
                                .'<div class="flex justify-center bg-gray-100">'
                                .'<img :src="docUrl" class="max-w-full max-h-[70vh] object-contain" alt="">'
                                .'</div>'
                                .'<div class="flex justify-end px-5 py-3 border-t">'
                                .'<button @click="openDoc = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Tutup</button>'
                                .'</div>'
                                .'</div>'
                                .'</div>'
                                .'</template>'
                                .'</div>';
                        }

                        return '<a href="'.e($url).'" target="_blank"'
                            .' class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 rounded text-xs font-medium text-gray-700 hover:bg-gray-200"'
                            .' title="Buka Akta Kelahiran (PDF)">'
                            .'<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                            .'<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>'
                            .'</svg>'
                            .'PDF</a>';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('akta_kelahiran_document')
                    ->label('Akta Kelahiran')
                    ->html()
                    ->state(function (Penduduk $record) {
                        $aktaDoc = $record->documents()->where('document_type', 'AKTA_KELAHIRAN')->where('is_active', true)->latest('id')->first();

                        if ($aktaDoc === null) {
                            return '<span class="text-gray-400">—</span>';
                        }

                        $url = route('penduduk-documents.preview', $aktaDoc);
                        $isImage = in_array($aktaDoc->mime_type, ['image/jpeg', 'image/png'], true);

                        if ($isImage) {
                            return '<div x-data="{ openDoc: false, docUrl: \'\', docTitle: \'\' }"'
                                .' class="relative inline-block">'
                                .'<img src="'.e($url).'"'
                                .' style="height: 40px; width: auto; object-fit: cover; border-radius: 4px;"'
                                .' @click.prevent="openDoc = true; docUrl = \''.addslashes($url).'\'; docTitle = \'Lihat Akta Kelahiran\'"'
                                .' class="cursor-pointer hover:opacity-80"'
                                .' title="Lihat Akta Kelahiran">'
                                .'<template x-if="openDoc">'
                                .'<div class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4" @click.self="openDoc = false" x-cloak>'
                                .'<div class="relative max-w-3xl w-full bg-white rounded-lg shadow-2xl overflow-hidden" @click.stop>'
                                .'<div class="flex items-center justify-between px-5 py-4 border-b">'
                                .'<h3 class="text-lg font-semibold text-gray-800 truncate pr-4" x-text="docTitle"></h3>'
                                .'<button @click="openDoc = false" class="w-8 h-8 flex items-center justify-center rounded-full text-gray-500 hover:bg-gray-200">'
                                .'<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>'
                                .'</button>'
                                .'</div>'
                                .'<div class="flex justify-center bg-gray-100">'
                                .'<img :src="docUrl" class="max-w-full max-h-[70vh] object-contain" alt="">'
                                .'</div>'
                                .'<div class="flex justify-end px-5 py-3 border-t">'
                                .'<button @click="openDoc = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50">Tutup</button>'
                                .'</div>'
                                .'</div>'
                                .'</div>'
                                .'</template>'
                                .'</div>';
                        }

                        return '<a href="'.e($url).'" target="_blank"'
                            .' class="inline-flex items-center gap-1 px-2 py-0.5 bg-gray-100 rounded text-xs font-medium text-gray-700 hover:bg-gray-200"'
                            .' title="Buka Akta Kelahiran (PDF)">'
                            .'<svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">'
                            .'<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>'
                            .'</svg>'
                            .'PDF</a>';
                    })
                    ->toggleable(isToggledHiddenByDefault: true),
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
                TextColumn::make('rt.areaUnit.name')
                    ->label('RW')
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
                TextColumn::make('status_date')
                    ->label('Tanggal Status')
                    ->state(fn (Penduduk $record): ?string => $record->formatted_status_date ?? '-')
                    ->sortable(false)
                    ->placeholder('-')
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
            ])
            ->filters(PendudukanFilters::build())
            ->recordActions([
                ViewAction::make()
                    ->label('Lihat')
                    ->icon('heroicon-o-eye')
                    ->modalHeading('Detail Penduduk')
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalFooterActions([
                        Action::make('edit')
                            ->label('Ubah')
                            ->icon('heroicon-o-pencil')
                            ->url(
                                fn (Penduduk $record): string => PendudukResource::getUrl(
                                    'edit',
                                    ['record' => $record]
                                )
                            ),
                    ]),

                EditAction::make()
                    ->label('Ubah')
                    ->icon('heroicon-o-pencil'),
                DeleteAction::make()
                    ->label('Hapus')
                    ->icon('heroicon-o-trash')
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
                    ->url(
                        fn (HasTable $livewire): string => route(
                            'penduduk.export.csv',
                            [
                                'filters' => $livewire->tableFilters,
                                'search' => $livewire->tableSearch,
                            ],
                        )
                    ),
                Action::make('export_xlsx')
                    ->label('Excel')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->requiresConfirmation(false)
                    ->url(
                        fn (HasTable $livewire): string => route(
                            'penduduk.export.xlsx',
                            [
                                'filters' => $livewire->tableFilters,
                                'search' => $livewire->tableSearch,
                            ],
                        )
                    ),
                Action::make('export_pdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->requiresConfirmation(false)
                    ->url(
                        fn (HasTable $livewire): string => route(
                            'penduduk.export.pdf',
                            [
                                'filters' => $livewire->tableFilters,
                                'search' => $livewire->tableSearch,
                            ],
                        )
                    ),
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
