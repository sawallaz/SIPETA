<?php

namespace App\Filament\Resources\Rts;

use App\Filament\Resources\Rts\Pages\ListRts;
use App\Filament\Resources\Rts\Schemas\RtForm;
use App\Filament\Resources\Rts\Tables\RtsTable;
use App\Models\Rt;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class RtResource extends Resource
{
    protected static ?string $model = Rt::class;

    protected static bool $shouldRegisterNavigation = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static string|UnitEnum|null $navigationGroup = 'Kependudukan';

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'Master RT / RW';

    protected static ?string $modelLabel = 'RT';

    protected static ?string $pluralModelLabel = 'Master RT / RW';

    public static function form(Schema $schema): Schema
    {
        return RtForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RtsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRts::route('/'),
        ];
    }
}
