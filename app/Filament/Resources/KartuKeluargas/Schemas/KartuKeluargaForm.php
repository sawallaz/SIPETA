<?php

namespace App\Filament\Resources\KartuKeluargas\Schemas;

use App\Services\KkPhotoService;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

class KartuKeluargaForm
{
    /**
     * Shared KK form fields — the photo section plus the household data
     * section. Used by both the create (scan) page and the edit page.
     *
     * @return array<int, Component>
     */
    public static function components(): array
    {
        return [

            /*
             * ==========================================================
             * FOTO KK
             * ==========================================================
             */
            Section::make('Dokumen Kartu Keluarga')
                ->description(
                    'Foto KK digunakan sebagai arsip sekaligus membantu pengisian data otomatis.'
                )
                ->icon(Heroicon::OutlinedDocumentPlus)
                ->schema([

                    Grid::make([
                        'default' => 1,
                        'md' => 2,
                    ])
                        ->schema([

                            self::photoField(),

                            self::currentPhotoPlaceholder(),

                        ]),
                ])
                ->collapsible(),

            /*
             * ==========================================================
             * DATA KK
             * ==========================================================
             */
            Section::make('Data Kartu Keluarga')
                ->description('Informasi utama Kartu Keluarga.')
                ->icon(Heroicon::OutlinedHomeModern)
                ->schema([

                    Grid::make([
                        'default' => 1,
                        'md' => 2,
                    ])
                        ->schema([

                            TextInput::make('kk_number')
                                ->label('Nomor KK')
                                ->required()
                                ->unique('kartu_keluarga', 'kk_number', ignoreRecord: true)
                                ->maxLength(16)
                                ->regex('/^[0-9]{16}$/')
                                ->numeric()
                                ->inputMode('numeric')
                                ->placeholder('Masukkan 16 digit Nomor KK')
                                ->helperText('Nomor Kartu Keluarga terdiri dari 16 digit.'),

                            TextInput::make('postal_code')
                                ->label('Kode Pos')
                                ->nullable()
                                ->maxLength(5)
                                ->regex('/^[0-9]{5}$/')
                                ->numeric()
                                ->inputMode('numeric')
                                ->placeholder('Contoh: 90711'),
                        ]),

                    TextInput::make('address')
                        ->label('Alamat Lengkap')
                        ->required()
                        ->maxLength(255)
                        ->columnSpanFull()
                        ->placeholder('Contoh: Jl. ... RT 001 RW 002 Kelurahan Tanete')
                        ->helperText('Masukkan alamat sesuai Kartu Keluarga.'),

                    Textarea::make('notes')
                        ->label('Catatan')
                        ->nullable()
                        ->rows(3)
                        ->columnSpanFull()
                        ->placeholder('Catatan tambahan jika diperlukan...'),
                ])
                ->collapsible(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components(self::components());
    }

    /**
     * The KK photo upload. Stored on the private `kk_uploads` disk so the scan
     * pipeline and the photo archive share the same private storage layer.
     */
    private static function photoField(): FileUpload
    {
        return FileUpload::make('kk_photo')
            ->label('Foto / Scan Kartu Keluarga')
            ->disk(KkPhotoService::DISK)
            ->directory('kk-photos')
            ->image()
            ->imageEditor()
            ->maxSize(5120)
            ->acceptedFileTypes(['image/jpeg', 'image/png'])
            ->required(false)
            ->downloadable()
            ->openable()
            ->previewable()
            ->helperText(
                fn (string $operation): string => $operation === 'edit'
                        ? 'Kosongkan jika tidak ingin mengganti foto.'
                        : 'Unggah foto KK untuk membantu pembacaan otomatis, atau isi data secara manual.'
            )
            ->columnSpan(1);
    }

    /**
     * Show the household's current active photo thumbnail on the edit page.
     */
    private static function currentPhotoPlaceholder(): Placeholder
    {
        return Placeholder::make('current_kk_photo')
            ->label('Foto KK Saat Ini')
            ->content(
                fn ($record): Htmlable => new HtmlString(
                    (
                        $record !== null &&
                        filled($record->active_photo_thumbnail_url)
                    )
                        ? sprintf(
                            '<div class="flex items-center justify-center">
                                    <img
                                        src="%s"
                                        alt="Foto Kartu Keluarga"
                                        class="max-h-64 max-w-full rounded-xl border border-gray-200 object-contain shadow-sm"
                                    >
                                </div>',
                            e($record->active_photo_thumbnail_url)
                        )
                        : '<div class="rounded-xl border border-dashed border-gray-300 p-8 text-center text-sm text-gray-400">
                                Belum ada foto KK.
                               </div>'
                )
            )
            ->columnSpan(1);
    }
}
