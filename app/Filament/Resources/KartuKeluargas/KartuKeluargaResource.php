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
use Illuminate\Database\Eloquent\Model;
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

    public static function getGloballySearchableAttributes(): array
    {
        return ['kk_number', 'penduduks.full_name', 'address'];
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        $canView = static::canView($record);

        if ($canView) {
            return static::getUrl(parameters: [
                'tableAction' => 'lihat',
                'tableActionRecord' => $record,
            ]);
        }

        return null;
    }

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
                                Section::make('Foto Kartu Keluarga')
                                    ->schema([
                                        TextEntry::make('active_photo_full_url')
                                            ->hiddenLabel()
                                            ->state(fn (KartuKeluarga $record): string => $record->active_photo_full_url
                                                ? '<div class="overflow-hidden rounded-xl border border-gray-200 bg-gray-50">
                                                    <div class="flex h-48 items-center justify-center p-3">
                                                        <a href="'.e($record->active_photo_full_url).'" target="_blank" class="flex h-full w-full items-center justify-center">
                                                            <img src="'.e($record->active_photo_full_url).'"
                                                                 alt="Foto Kartu Keluarga"
                                                                 class="max-h-full max-w-full rounded-lg object-contain">
                                                        </a>
                                                    </div>
                                                </div>'
                                                : '<div class="flex h-48 items-center justify-center rounded-xl border border-dashed border-gray-300 bg-gray-50 p-4 text-center">
                                                    <div>
                                                        <div class="text-sm font-medium text-gray-500">Belum ada foto KK.</div>
                                                        <div class="mt-1 text-xs text-gray-400">Foto KK belum tersedia.</div>
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
