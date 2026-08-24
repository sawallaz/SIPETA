<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Schema;

class Settings extends Page
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.settings';

    protected static ?string $title = 'Pengaturan';

    protected static ?string $navigationLabel = 'Pengaturan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 90;

    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    /**
     * State form Settings.
     *
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    /**
     * Inisialisasi data Settings.
     */
    public function mount(): void
    {
        $setting = app(SettingsService::class)->get();

        $this->form->fill([
            'kelurahan_name' => $setting->kelurahan_name,
            'kecamatan_name' => $setting->kecamatan_name,
            'kabupaten_name' => $setting->kabupaten_name,
            'province_name' => $setting->province_name,
            'logo_path' => $setting->logo_path,
        ]);
    }

    /**
     * Judul halaman.
     */
    public function getHeading(): string
    {
        return 'Pengaturan';
    }

    /**
     * Deskripsi halaman.
     */
    public function getSubheading(): ?string
    {
        return 'Kelola identitas dan logo kelurahan untuk dokumen SIPETA.';
    }

    /**
     * Action halaman.
     *
     * Tombol Simpan berada di kanan atas header,
     * bukan di dalam body halaman.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('importPenduduk')
                ->label('Import Penduduk')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->url(fn (): string => ImportPenduduk::getUrl()),
            Action::make('save')
                ->label('Simpan')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->action('save'),
        ];
    }

    /**
     * Simpan pengaturan.
     */
    public function save(): void
    {
        $data = $this->form->getState();

        app(SettingsService::class)->update($data);

        Notification::make()
            ->title('Pengaturan tersimpan')
            ->body('Perubahan pengaturan SIPETA berhasil disimpan.')
            ->success()
            ->send();
    }

    /**
     * State path form.
     */
    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    /**
     * Form Settings.
     */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([

                /*
                |--------------------------------------------------------------------------
                | IDENTITAS KELURAHAN
                |--------------------------------------------------------------------------
                */
                Section::make('Identitas Kelurahan')
                    ->description(
                        'Informasi identitas yang digunakan pada dokumen dan laporan SIPETA.'
                    )
                    ->icon('heroicon-o-building-office-2')
                    ->columns(2)
                    ->schema([

                        TextInput::make('kelurahan_name')
                            ->label('Nama Kelurahan')
                            ->placeholder('Contoh: Kelurahan Tanete')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('kecamatan_name')
                            ->label('Kecamatan')
                            ->placeholder('Contoh: Tanete')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('kabupaten_name')
                            ->label('Kabupaten/Kota')
                            ->placeholder('Contoh: Barru')
                            ->required()
                            ->maxLength(255),

                        TextInput::make('province_name')
                            ->label('Provinsi')
                            ->placeholder('Contoh: Sulawesi Selatan')
                            ->required()
                            ->maxLength(255),
                    ]),

                /*
                |--------------------------------------------------------------------------
                | LOGO KELURAHAN
                |--------------------------------------------------------------------------
                */
                Section::make('Logo Kelurahan')
                    ->description(
                        'Logo digunakan sebagai identitas pada dokumen dan laporan SIPETA.'
                    )
                    ->icon('heroicon-o-photo')
                    ->schema([

                        FileUpload::make('logo_path')
                            ->label('Logo Kelurahan')
                            ->disk('local')
                            ->directory(SettingsService::LOGO_DIR)
                            ->image()
                            ->imagePreviewHeight(160)
                            ->maxSize(2048)
                            ->nullable()
                            ->helperText(
                                'Format PNG atau JPG. Ukuran maksimal 2 MB.'
                            ),
                    ]),
            ]);
    }
}
