<?php

namespace App\Filament\Pages;

use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Models\KartuKeluarga;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class KartuKeluargaHistory extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.kartu-keluarga-history';

    protected static ?string $title = 'Riwayat Kartu Keluarga';

    protected static ?string $navigationLabel = 'Riwayat Kartu Keluarga';

    protected static string|BackedEnum|null $navigationIcon =
        'heroicon-o-clock';

    protected static ?int $navigationSort = 100;

    /**
     * Halaman ini tidak perlu menjadi menu utama.
     *
     * Operator masuk melalui:
     *
     * Pengaturan
     *      ↓
     * Riwayat Kartu Keluarga
     */
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function getTitle(): string
    {
        return 'Riwayat Kartu Keluarga';
    }

    public function getSubheading(): ?string
    {
        return 'Daftar Kartu Keluarga yang sudah tidak memiliki anggota aktif tetapi masih memiliki riwayat administrasi.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                KartuKeluarga::query()
                    ->whereDoesntHave('penduduks')
                    ->whereHas('kkAnggotas')
                    ->with([
                        'rt.areaUnit',
                    ])
                    ->withCount([
                        'kkAnggotas',
                    ])
            )
            ->recordUrl(null)
            ->columns([
                TextColumn::make('kk_number')
                    ->label('Nomor KK')
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->copyMessage('Nomor KK disalin'),

                TextColumn::make('rt_rw')
                    ->label('Wilayah')
                    ->state(
                        fn (KartuKeluarga $record): ?string => $record->rt_rw_label
                    )
                    ->placeholder('-'),

                TextColumn::make('address')
                    ->label('Alamat')
                    ->searchable()
                    ->wrap()
                    ->limit(80)
                    ->tooltip(
                        fn (KartuKeluarga $record): ?string => $record->address
                    ),

                TextColumn::make('kk_anggotas_count')
                    ->label('Riwayat Anggota')
                    ->counts('kkAnggotas')
                    ->badge()
                    ->color('gray')
                    ->suffix(' riwayat')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Terakhir Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->actions([
                Action::make('lihat')
                    ->label('Lihat')
                    ->icon('heroicon-o-eye')
                    ->url(
                        fn (KartuKeluarga $record): string => KartuKeluargaResource::getUrl(
                            'view',
                            ['record' => $record],
                        )
                    ),
            ])
            ->defaultSort('updated_at', 'desc')
            ->paginated([10, 25, 50])
            ->defaultPaginationPageOption(10)
            ->emptyStateHeading('Belum ada riwayat Kartu Keluarga')
            ->emptyStateDescription(
                'KK yang sudah tidak memiliki anggota aktif akan muncul di sini apabila mempunyai histori administrasi.'
            );
    }
}
