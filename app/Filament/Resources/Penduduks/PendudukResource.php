<?php

namespace App\Filament\Resources\Penduduks;

use App\Filament\Resources\Penduduks\Pages\CreatePenduduk;
use App\Filament\Resources\Penduduks\Pages\EditPenduduk;
use App\Filament\Resources\Penduduks\Pages\ListPenduduks;
use App\Filament\Resources\Penduduks\Schemas\PendudukForm;
use App\Filament\Resources\Penduduks\Tables\PenduduksTable;
use App\Models\Penduduk;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class PendudukResource extends Resource
{
    protected static ?string $model = Penduduk::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Kependudukan';

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = 'Penduduk';

    protected static ?string $modelLabel = 'Penduduk';

    protected static ?string $pluralModelLabel = 'Penduduk';

    protected static ?string $recordTitleAttribute = 'full_name';

    /**
     * Global search: name (partial), NIK and KK number (per .ai/testing.md §5).
     *
     * @return array<int, string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return ['full_name', 'nik', 'kartuKeluarga.kk_number'];
    }

    public static function form(Schema $schema): Schema
    {
        return PendudukForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PenduduksTable::configure($table);
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
            'index' => ListPenduduks::route('/'),
            'create' => CreatePenduduk::route('/create'),
            'edit' => EditPenduduk::route('/{record}/edit'),
        ];
    }
}
