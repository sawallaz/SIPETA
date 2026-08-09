<?php

namespace App\Filament\Resources\KartuKeluargas;

use App\Filament\Resources\KartuKeluargas\Pages\CreateKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\Pages\EditKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\Pages\ListKartuKeluargas;
use App\Filament\Resources\KartuKeluargas\Pages\ViewKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\RelationManagers\PenduduksRelationManager;
use App\Filament\Resources\KartuKeluargas\Schemas\KartuKeluargaForm;
use App\Filament\Resources\KartuKeluargas\Tables\KartuKeluargasTable;
use App\Models\KartuKeluarga;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class KartuKeluargaResource extends Resource
{
    protected static ?string $model = KartuKeluarga::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Kependudukan';

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'Kartu Keluarga';

    protected static ?string $modelLabel = 'Kartu Keluarga';

    protected static ?string $pluralModelLabel = 'Kartu Keluarga';

    protected static ?string $recordTitleAttribute = 'kk_number';

    public static function form(Schema $schema): Schema
    {
        return KartuKeluargaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KartuKeluargasTable::configure($table);
    }

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist
            ->components([
                Section::make('Kartu Keluarga')
                    ->icon(Heroicon::OutlinedRectangleStack)
                    ->schema([
                        Grid::make([
                            'default' => 1,
                            'lg' => 3,
                        ])
                            ->schema([
                                Section::make('Foto KK')
                                    ->schema([
                                        TextEntry::make('active_photo_full_url')
                                            ->hiddenLabel()
                                            ->state(fn (KartuKeluarga $record): string => $record->active_photo_full_url
                                                ? '<div class="flex flex-col items-center gap-3">
                                                    <a href="'.e($record->active_photo_full_url).'" target="_blank">
                                                        <img src="'.e($record->active_photo_full_url).'"
                                                             alt="Foto KK"
                                                             class="w-full rounded-xl border object-contain shadow-sm"
                                                             style="max-height: 430px;">
                                                    </a>
                                                    <a href="'.e($record->active_photo_full_url).'"
                                                       target="_blank"
                                                       class="text-sm font-medium text-primary-600 hover:underline">
                                                        Buka Foto KK
                                                    </a>
                                                </div>'
                                                : '<div class="flex min-h-64 items-center justify-center rounded-xl border border-dashed p-8 text-center">
                                                    <div>
                                                        <div class="font-medium">Belum ada foto KK</div>
                                                        <div class="mt-1 text-sm text-gray-500">
                                                            Upload foto melalui menu Ubah.
                                                        </div>
                                                    </div>
                                                </div>')
                                            ->html(),
                                    ])
                                    ->columnSpan([
                                        'default' => 1,
                                        'lg' => 1,
                                    ]),

                                Section::make('Informasi KK')
                                    ->icon(Heroicon::OutlinedIdentification)
                                    ->columns(2)
                                    ->schema([
                                        TextEntry::make('kk_number')
                                            ->label('Nomor KK')
                                            ->copyable()
                                            ->weight('bold'),

                                        TextEntry::make('postal_code')
                                            ->label('Kode Pos')
                                            ->placeholder('-'),

                                        TextEntry::make('address')
                                            ->label('Alamat')
                                            ->columnSpanFull(),

                                        TextEntry::make('notes')
                                            ->label('Catatan')
                                            ->placeholder('-')
                                            ->columnSpanFull(),
                                    ])
                                    ->columnSpan([
                                        'default' => 1,
                                        'lg' => 2,
                                    ]),
                            ]),
                    ])
                    ->columnSpanFull(),

                Grid::make([
                    'default' => 1,
                    'md' => 3,
                ])
                    ->schema([
                        Section::make('Kepala Keluarga')
                            ->icon(Heroicon::OutlinedUser)
                            ->schema([
                                TextEntry::make('kepala_keluarga')
                                    ->label('Nama')
                                    ->state(fn (KartuKeluarga $record): ?string => $record->kepalaKeluarga()?->full_name
                                    )
                                    ->placeholder('Belum ditentukan'),
                            ]),

                        Section::make('Wilayah')
                            ->icon(Heroicon::OutlinedMapPin)
                            ->schema([
                                TextEntry::make('rt_rw')
                                    ->label('RT / RW')
                                    ->state(fn (KartuKeluarga $record): ?string => $record->rt_rw_label
                                    )
                                    ->placeholder('-'),
                            ]),

                        Section::make('Jumlah Anggota')
                            ->icon(Heroicon::OutlinedUsers)
                            ->schema([
                                TextEntry::make('jumlah_anggota')
                                    ->label('Anggota Keluarga')
                                    ->state(fn (KartuKeluarga $record): string => number_format($record->jumlah_anggota).' orang'
                                    )
                                    ->badge()
                                    ->color('success'),
                            ]),
                    ])
                    ->columnSpanFull(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            PenduduksRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKartuKeluargas::route('/'),
            'create' => CreateKartuKeluarga::route('/create'),
            'view' => ViewKartuKeluarga::route('/{record}'),
            'edit' => EditKartuKeluarga::route('/{record}/edit'),
        ];
    }
}
