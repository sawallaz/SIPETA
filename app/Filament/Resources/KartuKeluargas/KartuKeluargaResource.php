<?php

namespace App\Filament\Resources\KartuKeluargas;

use App\Filament\Resources\KartuKeluargas\Pages\CreateKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\Pages\EditKartuKeluarga;
use App\Filament\Resources\KartuKeluargas\Pages\ListKartuKeluargas;
use App\Filament\Resources\KartuKeluargas\Schemas\KartuKeluargaForm;
use App\Filament\Resources\KartuKeluargas\Tables\KartuKeluargasTable;
use App\Models\KartuKeluarga;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class KartuKeluargaResource extends Resource
{
    protected static ?string $model = KartuKeluarga::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    public static function form(Schema $schema): Schema
    {
        return KartuKeluargaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return KartuKeluargasTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListKartuKeluargas::route('/'),
            'create' => CreateKartuKeluarga::route('/create'),
            'edit' => EditKartuKeluarga::route('/{record}/edit'),
        ];
    }
}
