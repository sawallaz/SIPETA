<?php

namespace App\Filament\Resources\KartuKeluargas\Tables;

use App\Models\KartuKeluarga;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class KartuKeluargasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([

                /*
                 * ==========================================================
                 * FOTO KK
                 * ==========================================================
                 *
                 * Foto bukan hanya untuk OCR.
                 * Foto merupakan arsip KK dan harus selalu dapat dilihat
                 * langsung dari tabel.
                 */
                ImageColumn::make('active_photo_thumbnail_url')
                    ->label('Foto KK')
                    ->state(fn (KartuKeluarga $record): ?string => $record->active_photo_thumbnail_url)
                    ->height(58)
                    ->width(82)
                    ->extraImgAttributes([
                        'class' => 'cursor-pointer rounded-lg object-cover',
                    ])
                    ->url(fn (KartuKeluarga $record): ?string => $record->active_photo_full_url)
                    ->openUrlInNewTab()
                    ->tooltip('Klik untuk melihat foto KK')
                    ->toggleable(isToggledHiddenByDefault: false),

                /*
                 * ==========================================================
                 * NOMOR KK
                 * ==========================================================
                 */
                TextColumn::make('kk_number')
                    ->label('Nomor KK')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Nomor KK disalin')
                    ->copyMessageDuration(1500)
                    ->width('170px')
                    ->toggleable(isToggledHiddenByDefault: false),

                /*
                 * ==========================================================
                 * KEPALA KELUARGA
                 * ==========================================================
                 */
                TextColumn::make('kepala_keluarga')
                    ->label('Kepala Keluarga')
                    ->state(fn (KartuKeluarga $record): ?string => $record->kepalaKeluarga()?->full_name)
                    ->placeholder('Belum ditentukan')
                    ->searchable()
                    ->wrap()
                    ->width('220px')
                    ->toggleable(isToggledHiddenByDefault: false),

                /*
                 * ==========================================================
                 * RT/RW
                 * ==========================================================
                 */
                TextColumn::make('rt_rw')
                    ->label('RT / RW')
                    ->state(fn (KartuKeluarga $record): ?string => $record->rt_rw_label)
                    ->placeholder('-')
                    ->width('120px')
                    ->toggleable(isToggledHiddenByDefault: false),

                /*
                 * ==========================================================
                 * ALAMAT
                 * ==========================================================
                 *
                 * Jangan limit terlalu pendek.
                 * Nama jalan/alamat bisa sangat panjang.
                 */
                TextColumn::make('address')
                    ->label('Alamat')
                    ->searchable()
                    ->wrap()
                    ->width('420px')
                    ->limit(120)
                    ->tooltip(fn (KartuKeluarga $record): ?string => $record->address)
                    ->toggleable(isToggledHiddenByDefault: false),

                /*
                 * ==========================================================
                 * JUMLAH ANGGOTA
                 * ==========================================================
                 *
                 * SUMBER KEBENARAN:
                 * penduduk.kk_id
                 *
                 * Jangan menghitung dari kk_anggota.
                 */
                TextColumn::make('penduduks_count')
                    ->label('Anggota')
                    ->counts('penduduks')
                    ->badge()
                    ->color(fn (?int $state): string => ($state ?? 0) > 0 ? 'success' : 'danger')
                    ->formatStateUsing(fn (?int $state): string => number_format((int) $state, 0, ',', '.'))
                    ->suffix(' jiwa')
                    ->sortable()
                    ->width('130px')
                    ->toggleable(isToggledHiddenByDefault: false),

                /*
                 * ==========================================================
                 * KODE POS
                 * ==========================================================
                 */
                TextColumn::make('postal_code')
                    ->label('Kode Pos')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-')
                    ->width('110px')
                    ->toggleable(isToggledHiddenByDefault: true),

                /*
                 * ==========================================================
                 * TANGGAL
                 * ==========================================================
                 */
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

            /*
             * ==========================================================
             * FILTER
             * ==========================================================
             */
            ->filters([
                //
            ])

            /*
             * ==========================================================
             * ACTIONS
             * ==========================================================
             */
            ->recordActions([

                ViewAction::make()
                    ->label('Lihat')
                    ->icon('heroicon-o-eye'),

                EditAction::make()
                    ->label('Ubah')
                    ->icon('heroicon-o-pencil'),

                DeleteAction::make()
                    ->label('Hapus')
                    ->icon('heroicon-o-trash')
                    ->modalHeading('Hapus Kartu Keluarga')
                    ->modalDescription(
                        'Data Kartu Keluarga dan data terkait akan dihapus. Lanjutkan?'
                    )
                    ->modalSubmitActionLabel('Ya, Hapus')
                    ->modalCancelActionLabel('Batal')
                    ->successNotificationTitle('Kartu Keluarga berhasil dihapus'),
            ])

            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Hapus yang dipilih')
                        ->modalHeading('Hapus Kartu Keluarga terpilih')
                        ->modalDescription('Data yang dipilih akan dihapus. Lanjutkan?')
                        ->modalSubmitActionLabel('Ya, Hapus')
                        ->modalCancelActionLabel('Batal')
                        ->successNotificationTitle('Kartu Keluarga terpilih berhasil dihapus'),
                ]),
            ])

            /*
             * ==========================================================
             * TABLE BEHAVIOR
             * ==========================================================
             */
            ->defaultSort('created_at', 'desc')
            ->recordTitleAttribute('kk_number')

            /*
             * Lebih banyak data per halaman.
             */
            ->paginated([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)

            ->striped()

            ->emptyStateHeading('Belum ada data Kartu Keluarga')
            ->emptyStateDescription('Mulai dengan menambahkan Kartu Keluarga pertama.')
            ->emptyStateIcon(Heroicon::OutlinedDocumentPlus);
    }
}
