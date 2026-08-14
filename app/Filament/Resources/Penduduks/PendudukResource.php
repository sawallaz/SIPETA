<?php

namespace App\Filament\Resources\Penduduks;

use App\Filament\Resources\Penduduks\Pages\CreatePenduduk;
use App\Filament\Resources\Penduduks\Pages\EditPenduduk;
use App\Filament\Resources\Penduduks\Pages\ListPenduduks;
use App\Filament\Resources\Penduduks\Pages\ViewPenduduk;
use App\Filament\Resources\Penduduks\Schemas\PendudukForm;
use App\Filament\Resources\Penduduks\Tables\PenduduksTable;
use App\Models\Penduduk;
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

    public static function getGloballySearchableAttributes(): array
    {
        return ['full_name', 'nik', 'kartuKeluarga.kk_number'];
    }

    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        $canView = static::canView($record);

        if ($canView) {
            return static::getUrl(parameters: [
                'tableAction' => 'view',
                'tableActionRecord' => $record,
            ]);
        }

        return null;
    }

    public static function form(Schema $schema): Schema
    {
        return PendudukForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PenduduksTable::configure($table);
    }

    public static function infolist(Schema $infolist): Schema
    {
        return $infolist->components([
            Section::make('Data Penduduk')
                ->description('Informasi dasar penduduk.')
                ->icon(Heroicon::OutlinedUser)
                ->schema([
                    Grid::make([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])->schema([
                        TextEntry::make('nik')
                            ->label('NIK')
                            ->weight('bold')
                            ->copyable(),

                        TextEntry::make('full_name')
                            ->label('Nama Lengkap')
                            ->weight('bold'),

                        TextEntry::make('gender')
                            ->label('Jenis Kelamin')
                            ->formatStateUsing(
                                fn ($state): string => $state instanceof BackedEnum
                                    ? $state->value
                                    : ($state ?: '-')
                            ),

                        TextEntry::make('birth_place')
                            ->label('Tempat Lahir')
                            ->default('-'),

                        TextEntry::make('birth_date')
                            ->label('Tanggal Lahir')
                            ->date('d M Y')
                            ->default('-'),

                        TextEntry::make('age')
                            ->label('Usia')
                            ->formatStateUsing(fn ($state): string => filled($state) ? $state.' tahun' : '-'),
                    ]),
                ]),

            Section::make('Informasi Wilayah')
                ->description('Informasi Kartu Keluarga dan wilayah tempat tinggal.')
                ->icon(Heroicon::OutlinedHome)
                ->schema([
                    Grid::make([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])->schema([
                        TextEntry::make('kartuKeluarga.kk_number')
                            ->label('Nomor KK')
                            ->weight('bold')
                            ->copyable(),

                        TextEntry::make('currentRt.number')
                            ->label('RT')
                            ->formatStateUsing(
                                fn ($state): string => filled($state) ? 'RT '.$state : '-'
                            )
                            ->default('-'),

                        TextEntry::make('rt_rw_label')
                            ->label('RW / Wilayah')
                            ->default('-'),

                        TextEntry::make('kartuKeluarga.address')
                            ->label('Alamat')
                            ->columnSpanFull()
                            ->default('-'),
                    ]),
                ]),

            Section::make('Data Sosial')
                ->description('Agama, pendidikan, pekerjaan, dan status perkawinan.')
                ->icon(Heroicon::OutlinedIdentification)
                ->schema([
                    Grid::make([
                        'default' => 1,
                        'md' => 2,
                        'xl' => 3,
                    ])->schema([
                        TextEntry::make('religion.name')
                            ->label('Agama')
                            ->default('-'),

                        TextEntry::make('education.name')
                            ->label('Pendidikan')
                            ->default('-'),

                        TextEntry::make('occupation.name')
                            ->label('Pekerjaan')
                            ->default('-'),

                        TextEntry::make('marital_status')
                            ->label('Status Perkawinan')
                            ->formatStateUsing(
                                fn ($state): string => $state instanceof BackedEnum
                                    ? $state->value
                                    : ($state ?: '-')
                            ),

                        TextEntry::make('resident_status')
                            ->label('Status Penduduk')
                            ->badge()
                            ->formatStateUsing(
                                fn ($state): string => $state instanceof BackedEnum
                                    ? $state->value
                                    : ($state ?: '-')
                            ),
                    ]),
                ]),

            Section::make('Catatan')
                ->description('Informasi tambahan penduduk.')
                ->icon(Heroicon::OutlinedDocumentText)
                ->schema([
                    TextEntry::make('notes')
                        ->label('Catatan')
                        ->default('-')
                        ->placeholder('-')
                        ->columnSpanFull(),
                ]),

            Section::make('Dokumen')
                ->description('Dokumen administrasi penduduk.')
                ->icon(Heroicon::OutlinedDocumentDuplicate)
                ->schema([
                    Grid::make([
                        'default' => 1,
                        'md' => 3,
                    ])->schema([
                        // KTP
                        TextEntry::make('documents')
                            ->label('KTP')
                            ->state(function ($record) {
                                $document = $record->documents()
                                    ->where('document_type', 'KTP')
                                    ->where('is_active', true)
                                    ->latest()
                                    ->first();

                                if (! $document) {
                                    return '<div class="rounded-xl border border-dashed p-6 text-center text-gray-500">
                                        <div class="font-medium">Belum ada KTP</div>
                                        <div class="mt-1 text-sm">Dokumen belum tersedia.</div>
                                    </div>';
                                }

                                $url = route('penduduk-documents.preview', $document);

                                return '<div class="space-y-3">
                                    <a href="'.e($url).'" target="_blank" class="block w-full overflow-hidden rounded-xl border bg-gray-50 transition hover:opacity-90">
                                        <img src="'.e($url).'" alt="KTP" class="h-44 w-full object-contain">
                                    </a>
                                    <div class="text-center text-sm text-gray-500">
                                        Klik gambar untuk melihat dokumen
                                    </div>
                                </div>';
                            })
                            ->html()
                            ->columnSpan(1),

                        // Akta Kelahiran
                        TextEntry::make('documents')
                            ->label('Akta Kelahiran')
                            ->state(function ($record) {
                                $document = $record->documents()
                                    ->where('document_type', 'AKTA_KELAHIRAN')
                                    ->where('is_active', true)
                                    ->latest()
                                    ->first();

                                if (! $document) {
                                    return '<div class="rounded-xl border border-dashed p-6 text-center text-gray-500">
                                        <div class="font-medium">Belum ada Akta Kelahiran</div>
                                        <div class="mt-1 text-sm">Dokumen belum tersedia.</div>
                                    </div>';
                                }

                                $url = route('penduduk-documents.preview', $document);

                                return '<div class="space-y-3">
                                    <a href="'.e($url).'" target="_blank" class="block w-full overflow-hidden rounded-xl border bg-gray-50 transition hover:opacity-90">
                                        <img src="'.e($url).'" alt="Akta Kelahiran" class="h-44 w-full object-contain">
                                    </a>
                                    <div class="text-center text-sm text-gray-500">
                                        Klik gambar untuk melihat dokumen
                                    </div>
                                </div>';
                            })
                            ->html()
                            ->columnSpan(1),

                        // Foto KK
                        TextEntry::make('kartuKeluarga.active_photo_full_url')
                            ->label('Foto KK')
                            ->state(function ($record) {
                                $photoUrl = $record->kartuKeluarga?->active_photo_full_url;

                                if (! $photoUrl) {
                                    return '<div class="rounded-xl border border-dashed p-6 text-center text-gray-500">
                                        <div class="font-medium">Belum ada Foto KK</div>
                                        <div class="mt-1 text-sm">Dokumen belum tersedia.</div>
                                    </div>';
                                }

                                return '<div class="space-y-3">
                                    <a href="'.e($photoUrl).'" target="_blank" class="block w-full overflow-hidden rounded-xl border bg-gray-50 transition hover:opacity-90">
                                        <img src="'.e($photoUrl).'" alt="Foto Kartu Keluarga" class="h-44 w-full object-contain">
                                    </a>
                                    <div class="text-center text-sm text-gray-500">
                                        Klik gambar untuk melihat dokumen
                                    </div>
                                </div>';
                            })
                            ->html()
                            ->columnSpan(1),
                    ]),
                ]),
        ]);
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
            'view' => ViewPenduduk::route('/{record}'),
            'edit' => EditPenduduk::route('/{record}/edit'),
        ];
    }
}
