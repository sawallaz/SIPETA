<?php

namespace App\Filament\Resources\KartuKeluargas\Tables;

use App\Enums\FamilyRelation;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaDeleteGuard;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Models\KartuKeluarga;
use App\Models\Rt;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

class KartuKeluargasTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->recordUrl(null)
            ->modifyQueryUsing(
                fn (Builder $query): Builder => $query->active()
            )
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
                    ->label('Foto')
                    ->state(fn (KartuKeluarga $record): ?string => $record->active_photo_thumbnail_url)
                    ->height(52)
                    ->width(74)
                    ->extraImgAttributes([
                        'class' => 'cursor-pointer rounded-lg object-cover',
                    ])
                    ->url(fn (KartuKeluarga $record): ?string => $record->active_photo_full_url)
                    ->openUrlInNewTab()
                    ->tooltip('Buka foto KK')
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
                    ->width('165px')
                    ->toggleable(isToggledHiddenByDefault: false),

                /*
                 * ==========================================================
                 * KEPALA KELUARGA
                 * ==========================================================
                 */
                TextColumn::make('kepala_keluarga')
                    ->label('Kepala Keluarga')
                    ->state(fn (KartuKeluarga $record): ?string => $record->kepalaKeluarga?->full_name)
                    ->placeholder('Belum ditentukan')
                    /*
                     * `kepala_keluarga` BUKAN kolom pada tabel `kartu_keluarga`.
                     * Nama kepala keluarga hidup di penduduk.full_name, terhubung
                     * lewat penduduk.kk_id dengan family_relation = KEPALA_KELUARGA.
                     *
                     * ->searchable() tanpa argumen membuat Filament menebak nama
                     * kolom dari nama column ('kepala_keluarga') dan menghasilkan
                     * `kartu_keluarga`.`kepala_keluarga` LIKE ? → SQLSTATE[42S22].
                     *
                     * Karena itu pencarian dialihkan ke relasi via closure
                     * `query:` (Filament\Tables\Columns\Concerns\CanBeSearchable),
                     * yang men-short-circuit jalur nama kolom sepenuhnya.
                     */
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas(
                            'penduduks',
                            fn (Builder $penduduk): Builder => $penduduk
                                ->where('family_relation', FamilyRelation::KEPALA_KELUARGA->value)
                                ->where('full_name', 'like', '%'.$search.'%')
                        );
                    })
                    ->wrap()
                    ->width('190px')
                    ->toggleable(isToggledHiddenByDefault: false),

                /*
                 * ==========================================================
                 * RT/RW
                 * ==========================================================
                 */
                TextColumn::make('rt_rw')
                    ->label('RW / RT')
                    ->state(fn (KartuKeluarga $record): ?string => $record->rt_rw_label)
                    ->placeholder('-')
                    ->width('130px')
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
                    ->limit(80)
                    ->tooltip(fn (KartuKeluarga $record): ?string => $record->address)
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->width('120px')
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
                SelectFilter::make('area_unit')
                    ->label('RW')
                    ->relationship('rt.areaUnit', 'name')
                    ->preload()
                    ->searchable()
                    ->modifyFormFieldUsing(fn (Select $field): Select => $field
                        ->placeholder('Pilih RW')
                        ->live()),

                SelectFilter::make('rt')
                    ->label('RT')
                    ->relationship('rt', 'number')
                    ->searchable()
                    ->modifyFormFieldUsing(function (Select $field, $livewire): Select {
                        $selectedRw = data_get($livewire, 'tableFilters.area_unit.value');

                        return $field
                            ->placeholder('Pilih RT')
                            ->options(function () use ($selectedRw): array {
                                $query = Rt::query()->orderBy('number');
                                if (filled($selectedRw)) {
                                    $query->where('area_unit_id', $selectedRw);
                                }

                                return $query->pluck('number', 'id')
                                    ->map(fn ($num): string => 'RT '.$num)
                                    ->toArray();
                            });
                    }),
            ])

            /*
             * ==========================================================
             * ACTIONS
             * ==========================================================
             */
            ->recordActions([

                Action::make('lihat')
                    ->label('Lihat')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (KartuKeluarga $record): string => 'Kartu Keluarga '.$record->kk_number)
                    ->modalWidth('5xl')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Tutup')
                    ->modalFooterActions([
                        Action::make('ubah')
                            ->label('Ubah')
                            ->icon('heroicon-o-pencil')
                            ->url(
                                fn (KartuKeluarga $record): string => KartuKeluargaResource::getUrl(
                                    'edit',
                                    ['record' => $record]
                                )
                            ),
                    ])
                    ->modalContent(
                        fn (KartuKeluarga $record): HtmlString => new HtmlString(
                            view(
                                'filament.resources.kartu-keluargas.kk-detail-modal',
                                [
                                    'kk' => $record->load([
                                        'rt.areaUnit',
                                        'penduduks.religion',
                                        'penduduks.education',
                                        'penduduks.occupation',
                                    ]),
                                ]
                            )->render()
                        )
                    ),

                EditAction::make()
                    ->label('Ubah')
                    ->icon('heroicon-o-pencil'),

                DeleteAction::make()
                    ->label('Hapus')
                    ->icon('heroicon-o-trash')
                    ->visible(
                        fn (KartuKeluarga $record): bool => ! KartuKeluargaDeleteGuard::isHistorical($record)
                    )
                    ->modalHeading('Hapus Kartu Keluarga')
                    ->modalDescription(
                        'Hapus permanen hanya digunakan untuk data KK yang benar-benar kosong dan belum memiliki histori, foto, atau proses OCR.'
                    )
                    ->modalSubmitActionLabel('Ya, Hapus Permanen')
                    ->modalCancelActionLabel('Batal')
                    ->before(
                        function (KartuKeluarga $record): void {
                            KartuKeluargaDeleteGuard::assertDeletable($record);
                        }
                    )
                    ->successNotificationTitle('Kartu Keluarga berhasil dihapus'),
            ])

            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->label('Hapus yang dipilih')
                        ->modalHeading('Hapus Kartu Keluarga terpilih')
                        ->modalDescription('Kartu Keluarga yang masih memiliki anggota, foto, atau riwayat tidak akan dihapus. Hanya KK yang benar-benar kosong yang dapat dihapus.')
                        ->modalSubmitActionLabel('Ya, Hapus')
                        ->modalCancelActionLabel('Batal')
                        ->before(
                            function (Collection $records): void {
                                foreach ($records as $record) {
                                    KartuKeluargaDeleteGuard::assertDeletable($record);
                                }
                            }
                        )
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
