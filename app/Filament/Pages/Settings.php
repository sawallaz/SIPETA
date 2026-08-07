<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Schema;

/**
 * Phase 6.5 — operator-facing Pengaturan (Settings) page.
 *
 * The "Pengaturan" menu in the five-menu navigation (`.ai/workflow.md` §1, §16).
 * Edits the singleton kelurahan identity, logo and backup path (FR-SET-01) via
 * the Phase 6.5 `SettingsService`; the row is created on first access and never
 * deleted (FR-SET-02).
 *
 * The logo is stored by the Filament `FileUpload` on the `local` disk under a
 * `logos/` prefix; only the relative path is persisted in `logo_path`. No extra
 * filesystem disk is added and `config/filesystems.php` is unchanged.
 * `backup_path` is operator configuration recorded for future phases only — the
 * Phase 6.2 `BackupService` keeps its own implementation (not modified here).
 */
class Settings extends Page
{
    use InteractsWithSchemas;

    protected string $view = 'filament.pages.settings';

    protected static ?string $navigationLabel = 'Pengaturan';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 90;

    /**
     * Livewire state container for the settings form.
     *
     * @var array<string, mixed>
     */
    public ?array $data = [];

    public function mount(): void
    {
        $setting = app(SettingsService::class)->get();

        $this->form->fill($setting->attributesToArray());
    }

    /**
     * SIMPAN — persist the edited settings singleton.
     */
    public function save(): void
    {
        $data = $this->form->getState();

        app(SettingsService::class)->update($data);

        Notification::make()
            ->title('Pengaturan tersimpan')
            ->body('Identitas kelurahan, logo, dan lokasi backup telah disimpan.')
            ->success()
            ->send();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identitas Kelurahan')
                ->description('Data identitas yang tampil pada dokumen dan laporan.')
                ->schema([
                    TextInput::make('kelurahan_name')->label('Nama Kelurahan')->required()->maxLength(255),
                    TextInput::make('kecamatan_name')->label('Kecamatan')->required()->maxLength(255),
                    TextInput::make('kabupaten_name')->label('Kabupaten/Kota')->required()->maxLength(255),
                    TextInput::make('province_name')->label('Provinsi')->required()->maxLength(255),
                ]),
            Section::make('Logo Kelurahan')
                ->description('Upload logo identitas yang digunakan pada dokumen (opsional).')
                ->schema([
                    FileUpload::make('logo_path')
                        ->label('Logo')
                        ->disk('local')
                        ->directory(SettingsService::LOGO_DIR)
                        ->image()
                        ->imagePreviewHeight(160)
                        ->maxSize(2048)
                        ->required(fn (): bool => false),
                ]),
            Section::make('Backup')
                ->description('Lokasi penyimpanan untuk pengaturan operator. Backup tetap dibuat sesuai konfigurasi aplikasi.')
                ->schema([
                    TextInput::make('backup_path')
                        ->label('Lokasi Backup')
                        ->helperText('Tersimpan sebagai pengaturan untuk fase berikutnya; tidak mengubah perilaku backup saat ini.')
                        ->required()
                        ->maxLength(255),
                ]),
        ]);
    }
}
