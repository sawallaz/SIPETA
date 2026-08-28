<?php

namespace App\Filament\Pages;

use App\Http\Controllers\PendudukImportController;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;
use Livewire\WithFileUploads;

class ImportPenduduk extends Page
{
    use WithFileUploads;

    protected string $view = 'filament.pages.import-penduduk';

    protected static ?string $title = 'Import Penduduk';

    protected static ?string $navigationLabel = 'Import Penduduk';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-import';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 95;

    public string $currentStep = 'upload';

    public mixed $file = null;

    public ?array $completedImportResult = null;

    public bool $isImporting = false;

    public static function canAccess(): bool
    {
        return auth()->check();
    }

    public function updatedFile(): void
    {
        if ($this->file !== null) {
            $this->uploadFile();
        }
    }

    #[Computed]
    public function sheets(): array
    {
        return session('penduduk_import.sheets', []);
    }

    #[Computed]
    public function selectedSheetName(): string
    {
        return session('penduduk_import.selected_sheet', '');
    }

    #[Computed]
    public function headers(): array
    {
        return session('penduduk_import.headers', []);
    }

    #[Computed]
    public function rows(): array
    {
        return session('penduduk_import.rows', []);
    }

    #[Computed]
    public function totalRows(): int
    {
        return session('penduduk_import.total_rows', 0);
    }

    #[Computed]
    public function mapping(): array
    {
        return session('penduduk_import.mapping', []);
    }

    #[Computed]
    public function ambiguous(): array
    {
        return session('penduduk_import.mapping.ambiguous', []);
    }

    #[Computed]
    public function missingRequired(): array
    {
        return session('penduduk_import.mapping.missing_required', []);
    }

    #[Computed]
    public function unrecognized(): array
    {
        return session('penduduk_import.mapping.unrecognized', []);
    }

    #[Computed]
    public function previewData(): ?array
    {
        return session('penduduk_import.preview_result', null);
    }

    #[Computed]
    public function importResult(): ?array
    {
        return session('penduduk_import.import_result', null);
    }

    #[Computed]
    public function fileName(): ?string
    {
        return session('penduduk_import.file_name', null);
    }

    #[Computed]
    public function hasFileUploaded(): bool
    {
        return session('penduduk_import.file_path', null) !== null;
    }

    public function getHeading(): string
    {
        return 'Import Penduduk dari Excel/CSV';
    }

    public function getSubheading(): ?string
    {
        return 'Unggah file Excel atau CSV untuk mengimpor data penduduk ke dalam sistem.';
    }

    protected function getHeaderActions(): array
    {
        $actions = [];

        if ($this->currentStep !== 'upload') {
            $actions[] = Action::make('cancel')
                ->label('Batal')
                ->icon('heroicon-o-x-mark')
                ->color('gray')
                ->action('cancelImport');
        }

        if ($this->currentStep === 'preview' && $this->previewData) {
            $actions[] = Action::make('import')
                ->label('Impor Data')
                ->icon('heroicon-o-check')
                ->color('primary')
                ->action('importData');
        }

        return $actions;
    }

    public function mount(): void
    {
        $this->currentStep = session('penduduk_import.step', 'upload');
        $this->restoreCompletedImportResult();
    }

    public function hydrate(): void
    {
        $this->restoreCompletedImportResult();
    }

    private function restoreCompletedImportResult(): void
    {
        $this->completedImportResult = session('penduduk_import.import_result');

        if ($this->completedImportResult !== null) {
            $this->currentStep = 'result';
        }
    }

    // -------------------------------------------------------------------------
    // Step 1: Upload
    // -------------------------------------------------------------------------

    public function uploadFile(): void
    {
        if ($this->file === null) {
            Notification::make()->title('Pilih file terlebih dahulu.')->danger()->send();

            return;
        }

        try {
            $request = request();
            $controller = app(PendudukImportController::class);
            $result = $controller->upload($request, $this->file);

            if (isset($result['error'])) {
                Notification::make()
                    ->title('Gagal mengunggah file')
                    ->body($result['error'])
                    ->danger()
                    ->send();

                $this->file = null;

                return;
            }

            $sheets = $result['sheets'] ?? [];

            session()->put('penduduk_import', array_merge(session('penduduk_import', []), [
                'file_path' => $result['file_path'],
                'file_name' => $result['file_name'] ?? '',
                'sheets' => $sheets,
                'step' => 'sheet',
            ]));

            $this->currentStep = 'sheet';
            $this->file = null;

            Notification::make()
                ->title('File berhasil diunggah')
                ->body(count($sheets) > 1 ? 'Silakan pilih sheet yang akan diimpor.' : 'File siap dipetakan.')
                ->success()
                ->send();

            if (count($sheets) === 1) {
                $this->selectSheet(0);
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Terjadi kesalahan saat memproses file')
                ->body($e->getMessage())
                ->danger()
                ->send();

            $this->file = null;
        }
    }

    // -------------------------------------------------------------------------
    // Step 2: Pilih Sheet
    // -------------------------------------------------------------------------

    public function selectSheet(int $sheetIndex): void
    {
        try {
            $request = request();
            $controller = app(PendudukImportController::class);
            $result = $controller->selectSheet($request, $sheetIndex);

            if (isset($result['error'])) {
                Notification::make()
                    ->title('Gagal memuat sheet')
                    ->body($result['error'])
                    ->danger()
                    ->send();

                return;
            }

            session()->put('penduduk_import', array_merge(session('penduduk_import', []), [
                'headers' => $result['headers'] ?? [],
                'rows' => $result['rows'] ?? [],
                'total_rows' => $result['total_rows'] ?? 0,
                'selected_sheet' => $result['sheet_name'] ?? '',
                'step' => 'mapping',
            ]));

            $this->currentStep = 'mapping';
            $this->mapColumns();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal memuat sheet')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    // -------------------------------------------------------------------------
    // Step 3: Mapping
    // -------------------------------------------------------------------------

    public function mapColumns(): void
    {
        try {
            $controller = app(PendudukImportController::class);
            $result = $controller->mapColumns(request());

            if (isset($result['error'])) {
                Notification::make()
                    ->title('Gagal memetakan kolom')
                    ->body($result['error'])
                    ->danger()
                    ->send();

                return;
            }

            session()->put('penduduk_import.mapping', $result);

            if (! empty($result['ambiguous'])) {
                Notification::make()
                    ->title('Kolom ambigu terdeteksi')
                    ->body('Beberapa kolom memiliki nama yang mirip. Pilih mapping yang tepat.')
                    ->warning()
                    ->send();
            }

            // Auto lanjut ke preview jika tidak ada ambiguous
            if (empty($result['ambiguous']) && empty($result['missing_required'])) {
                $this->currentStep = 'preview';
                session()->put('penduduk_import.step', 'preview');
                $this->loadPreview();
            }
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal memetakan kolom')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function goToSheet(): void
    {
        $this->currentStep = 'sheet';
    }

    public function goToMapping(): void
    {
        if (isset($this->sheets[0])) {
            $this->selectSheet(0);
        }
    }

    public function confirmMapping(): void
    {
        $mapping = $this->mapping();

        if (! empty($mapping['ambiguous']) || ! empty($mapping['missing_required'])) {
            Notification::make()
                ->title('Mapping belum lengkap')
                ->body('Pilih kolom ambigu dan lengkapi semua kolom wajib.')
                ->warning()
                ->send();

            return;
        }

        $this->currentStep = 'preview';
        session()->put('penduduk_import.step', 'preview');
        $this->loadPreview();
    }

    public function updateMapping(string $field, string $selectedHeader): void
    {
        $mapping = $this->mapping();
        if (blank($selectedHeader)) {
            unset($mapping['mapping'][$field]);
        } else {
            $mapping['mapping'][$field] = $selectedHeader;
        }

        if (isset($mapping['ambiguous'][$field])) {
            unset($mapping['ambiguous'][$field]);
        }

        $required = ['nik', 'full_name', 'kk_number'];
        $activeFields = array_keys(array_filter($mapping['mapping'] ?? [], fn ($h) => filled($h)));
        $mapping['missing_required'] = array_values(array_diff($required, $activeFields));

        $allHeaders = $this->headers();
        $mappedHeaders = array_values($mapping['mapping'] ?? []);
        $mapping['unrecognized'] = array_values(array_filter($allHeaders, fn ($h) => ! in_array($h, $mappedHeaders, true)));

        $custom = session('penduduk_import.mapping.custom_mapping', []);
        $custom[$field] = $selectedHeader;

        session()->put('penduduk_import.mapping', $mapping);
        session()->put('penduduk_import.mapping.custom_mapping', $custom);
    }

    public function mapHeaderToField(string $header, string $field): void
    {
        if (filled($field)) {
            $this->updateMapping($field, $header);
        }
    }

    // -------------------------------------------------------------------------
    // Step 4: Preview
    // -------------------------------------------------------------------------

    public function loadPreview(): void
    {
        try {
            $controller = app(PendudukImportController::class);
            $result = $controller->preview(request());

            if (isset($result['error'])) {
                Notification::make()
                    ->title('Gagal memuat preview')
                    ->body($result['error'])
                    ->danger()
                    ->send();

                return;
            }

            session()->put('penduduk_import.preview_result', $result);
            $this->currentStep = 'preview';

            $total = $result['total'] ?? 0;
            $valid = $result['valid'] ?? 0;
            $duplicate = $result['duplicate'] ?? 0;
            $invalid = $result['invalid'] ?? 0;

            Notification::make()
                ->title('Preview Data')
                ->body("Total: {$total} | Valid: {$valid} | Duplikat: {$duplicate} | Tidak valid: {$invalid}")
                ->info()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal memuat preview')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    public function prepareImport(): void
    {
        if ($this->previewData === null) {
            Notification::make()->title('Preview belum dimuat.')->warning()->send();

            return;
        }

        $this->currentStep = 'import';
        session()->put('penduduk_import.step', 'import');
    }

    // -------------------------------------------------------------------------
    // Step 5: Import
    // -------------------------------------------------------------------------

    public function importData(): void
    {
        if ($this->isImporting) {
            return;
        }

        $this->isImporting = true;

        try {
            $controller = app(PendudukImportController::class);
            $result = $controller->import(request());

            if (isset($result['error'])) {
                Notification::make()
                    ->title('Gagal melakukan import')
                    ->body($result['error'])
                    ->danger()
                    ->send();

                return;
            }

            $this->completedImportResult = $result;
            $this->currentStep = 'result';
            session()->put('penduduk_import', [
                'import_result' => $result,
            ]);

            Notification::make()
                ->title('Import Selesai')
                ->body($result['message'] ?? 'Import selesai.')
                ->success()
                ->send();
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Gagal melakukan import')
                ->body($e->getMessage())
                ->danger()
                ->send();
        } finally {
            $this->isImporting = false;
        }
    }

    // -------------------------------------------------------------------------
    // Cancel
    // -------------------------------------------------------------------------

    public function cancelImport(): void
    {
        if (! session()->has('penduduk_import')) {
            $this->completedImportResult = null;
            $this->currentStep = 'upload';
            $this->file = null;
            session()->forget('penduduk_import.import_result');

            return;
        }

        $controller = app(PendudukImportController::class);
        $controller->cancel(request());

        session()->forget('penduduk_import');

        $this->currentStep = 'upload';
        $this->reset();
    }
}
