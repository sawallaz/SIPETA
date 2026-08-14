<?php

namespace App\Filament\Resources\KartuKeluargas\Pages;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\OcrJobStatus;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaDeleteGuard;
use App\Filament\Resources\KartuKeluargas\KartuKeluargaResource;
use App\Filament\Resources\KartuKeluargas\Pages\Concerns\ChecksDuplicateKkNumber;
use App\Filament\Resources\KartuKeluargas\Schemas\KartuKeluargaForm;
use App\Models\OcrJob;
use App\Models\Rt;
use App\Services\KkPhotoService;
use App\Services\OcrProcessingService;
use App\Services\ParsedOcrResult;
use App\Services\ParsedResident;
use App\Services\PendudukKkService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Placeholder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Throwable;

class EditKartuKeluarga extends EditRecord
{
    use ChecksDuplicateKkNumber;

    protected static string $resource = KartuKeluargaResource::class;

    /**
     * OCR is intentionally transient on Edit KK.
     *
     * Scan -> parse -> review only.
     * No Penduduk / KkAnggota write happens here.
     * Persistence of member changes belongs to the dedicated
     * KK/Penduduk workflow introduced in the next phase.
     *
     * Public so Livewire dehydrates/hydrates it: a protected property
     * would reset to [] on any following live re-render (e.g. selecting
     * RT) and the review panel would vanish after the scan.
     */
    public array $ocrPreview = [];

    /**
     * Prevent re-entrant Livewire updates while a scan is running.
     */
    protected bool $scanning = false;

    /**
     * Path OCR temp (on kk_uploads disk) that must be cleaned after scan.
     */
    protected ?string $ocrTempPath = null;

    public function getMaxContentWidth(): Width
    {
        return Width::Full;
    }

    public function getTitle(): string
    {
        return 'Ubah Kartu Keluarga';
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Perubahan Kartu Keluarga berhasil disimpan';
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()
            ->label('Simpan Perubahan')
            ->icon('heroicon-o-check');
    }

    protected function getCancelFormAction(): Action
    {
        return parent::getCancelFormAction()
            ->label('Batal')
            ->icon('heroicon-o-x-mark')
            ->color('gray');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ...KartuKeluargaForm::components(),

            Section::make('Hasil Pemindaian OCR')
                ->description(
                    'Hasil ini hanya untuk pemeriksaan. Data anggota belum disimpan dari halaman ini.'
                )
                ->visible(fn (): bool => filled($this->ocrPreview))
                ->schema([
                    Placeholder::make('ocr_preview')
                        ->hiddenLabel()
                        ->content(fn (): Htmlable => $this->renderOcrPreview()),
                ])
                ->columnSpanFull(),
        ]);
    }

    /**
     * Upload Foto KK -> automatic OCR.
     */
    public function updated(string $property): void
    {
        if (
            $property !== 'data.kk_photo'
            && ! str_starts_with($property, 'data.kk_photo.')
        ) {
            return;
        }

        if (blank($this->data['kk_photo'] ?? null)) {
            return;
        }

        $this->scanFoto();
    }

    /**
     * Run OCR against the newly uploaded/current KK photo.
     *
     * The result is kept in memory only.
     */
    public function scanFoto(): void
    {
        $rawPath = $this->data['kk_photo'] ?? null;

        $path = $this->resolveOcrDiskPath($rawPath);

        if ($path === null) {
            Notification::make()
                ->warning()
                ->title('Foto KK belum siap')
                ->body(
                    'Foto Kartu Keluarga belum tersedia sebagai file yang dapat diproses. '
                    .'Tunggu sampai upload selesai, lalu coba Scan Foto KK kembali.'
                )
                ->send();

            return;
        }

        if ($this->scanning) {
            return;
        }

        $this->scanning = true;

        try {
            $parsed = $this->runOcr($path);

            $this->applyParsed($parsed);

            if ($parsed->isEmpty()) {
                Notification::make()
                    ->warning()
                    ->title('Data KK tidak terbaca')
                    ->body(
                        'Foto berhasil diproses, tetapi tidak ada data Kartu Keluarga '
                        .'yang berhasil dikenali. Periksa kualitas foto atau lengkapi '
                        .'data secara manual.'
                    )
                    ->send();

                return;
            }

            $summary = collect($parsed->validationErrors)
                ->merge($parsed->warnings)
                ->filter(fn ($messageOrArray): bool => is_scalar($messageOrArray))
                ->map(fn ($messageOrArray): string => (string) $messageOrArray)
                ->take(3)
                ->implode(' · ');

            Notification::make()
                ->success()
                ->title('Data Kartu Keluarga berhasil dibaca')
                ->body(
                    'Periksa hasil pemindaian sebelum menyimpan.'
                    .($summary !== '' ? ' '.$summary : '')
                )
                ->send();
        } catch (Throwable $e) {
            Log::warning('KK edit scan failed', [
                'kk_id' => $this->record?->getKey(),
                'error' => $e->getMessage(),
                'exception' => $e::class,
                'photo_state_type' => get_debug_type($rawPath),
                'normalized_path' => $path,
            ]);

            $this->ocrPreview = [];

            Notification::make()
                ->danger()
                ->title('Pemindaian KK gagal')
                ->body(
                    $this->ocrErrorMessage($e)
                )
                ->send();
        } finally {
            $this->scanning = false;

            $this->cleanupOcrTemp($rawPath);
        }
    }

    /**
     * Manual re-scan action.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('approveOcr')
                ->label('Setujui & Simpan Anggota')
                ->icon('heroicon-m-check-circle')
                ->color('success')
                ->visible(fn (): bool => filled($this->ocrPreview))
                ->requiresConfirmation()
                ->modalHeading('Simpan Hasil OCR?')
                ->modalDescription(
                    'Data anggota yang tampil pada hasil OCR akan disimpan '
                    .'ke database. NIK yang sudah terdaftar akan menggunakan '
                    .'data penduduk yang sama.'
                )
                ->modalSubmitActionLabel('Ya, Simpan')
                ->modalCancelActionLabel('Periksa Lagi')
                ->action(fn () => $this->approveOcr()),

            Action::make('scanFotoKk')
                ->label('Scan Foto KK')
                ->icon('heroicon-m-camera')
                ->color('primary')
                ->action(fn () => $this->scanFoto()),

            Action::make('clearOcrPreview')
                ->label('Tutup Hasil OCR')
                ->icon('heroicon-m-x-mark')
                ->color('gray')
                ->visible(fn (): bool => filled($this->ocrPreview))
                ->action(function (): void {
                    $this->ocrPreview = [];

                    Notification::make()
                        ->info()
                        ->title('Hasil OCR ditutup')
                        ->body(
                            'Data hasil pemindaian tidak disimpan. Data KK tetap seperti sebelumnya.'
                        )
                        ->send();
                }),

            DeleteAction::make()
                ->label('Hapus')
                ->modalHeading('Hapus Kartu Keluarga')
                ->modalDescription(
                    'Kartu Keluarga hanya dapat dihapus jika tidak memiliki anggota atau data histori yang masih terhubung. Penghapusan tidak akan menghapus anggota, foto, maupun riwayat secara otomatis.'
                )
                ->modalSubmitActionLabel('Ya, Hapus')
                ->modalCancelActionLabel('Batal')
                ->before(
                    function (Model $record): void {
                        KartuKeluargaDeleteGuard::assertDeletable($record);
                    }
                )
                ->successNotificationTitle(
                    'Kartu Keluarga berhasil dihapus'
                ),
        ];
    }

    /**
     * Save only KK fields + uploaded photo.
     *
     * IMPORTANT:
     * OCR member preview is intentionally NOT persisted here.
     * Member synchronization is deferred to the dedicated
     * Penduduk/KK workflow.
     */
    protected function handleRecordUpdate(
        Model $record,
        array $data,
    ): Model {
        return DB::transaction(function () use ($record, $data): Model {
            $photo = $data['kk_photo'] ?? null;

            unset($data['kk_photo']);

            $record->update($data);

            /*
             * Dengan storeFiles(false), nilai form berupa TemporaryUploadedFile.
             * Serahkan ke service yang membaca byte file langsung sehingga
             * tidak bergantung pada path file tersimpan yang mungkin hilang.
             */
            if ($photo instanceof TemporaryUploadedFile) {
                app(KkPhotoService::class)->storeUploadedFileForKk(
                    $record->id,
                    $photo,
                    auth()->id(),
                );
            }

            return $record->fresh();
        });
    }

    /**
     * Approve hasil OCR dan persist seluruh anggota KK.
     *
     * IMPORTANT:
     * Hasil OCR hanya disimpan setelah operator menekan
     * "Setujui & Simpan Anggota".
     */
    public function approveOcr(): void
    {
        if (blank($this->ocrPreview)) {
            Notification::make()
                ->warning()
                ->title('Tidak ada hasil OCR')
                ->body(
                    'Lakukan pemindaian Kartu Keluarga terlebih dahulu.'
                )
                ->send();

            return;
        }

        $members = $this->ocrPreview['members'] ?? [];

        if ($members === []) {
            Notification::make()
                ->warning()
                ->title('Tidak ada anggota')
                ->body(
                    'Tidak ada anggota yang dapat disimpan dari hasil OCR.'
                )
                ->send();

            return;
        }

        try {
            $saved = app(PendudukKkService::class)
                ->saveOcrMembers(
                    $this->record,
                    $members,
                );

            $this->ocrPreview = [];

            Notification::make()
                ->success()
                ->title('Anggota KK berhasil disimpan')
                ->body(
                    sprintf(
                        '%d anggota berhasil disinkronkan dengan Kartu Keluarga.',
                        count($saved),
                    )
                )
                ->send();

            /*
             * Refresh record agar relation manager / data KK
             * langsung membaca data terbaru.
             */
            $this->record->refresh();

        } catch (ValidationException $e) {
            $messages = collect($e->errors())
                ->flatten()
                ->filter()
                ->take(5)
                ->implode(' ');

            Notification::make()
                ->danger()
                ->title('Import OCR tidak dapat disimpan')
                ->body(
                    $messages !== ''
                        ? $messages
                        : 'Periksa kembali hasil OCR sebelum menyimpan.'
                )
                ->persistent()
                ->send();

        } catch (Throwable $e) {
            Log::error('OCR member approval failed', [
                'kk_id' => $this->record?->getKey(),
                'error' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            Notification::make()
                ->danger()
                ->title('Gagal menyimpan anggota')
                ->body(
                    'Tidak ada perubahan anggota yang disimpan. '
                    .'Periksa log aplikasi untuk detail teknis.'
                )
                ->persistent()
                ->send();
        }
    }

    /**
     * Normalisasi nilai FileUpload menjadi satu path foto.
     */
    private function normalizePhotoPath(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value !== '' ? $value : null;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                $path = $this->normalizePhotoPath($item);

                if ($path !== null) {
                    return $path;
                }
            }
        }

        return null;
    }

    /**
     * Resolusi path foto untuk OCR.
     *
     * OCR membaca dari disk kk_uploads. TemporaryUploadedFile (hasil
     * storeFiles(false)) berada di disk livewire-tmp, sehingga byte-nya
     * disalin ke kk_uploads/ocr-tmp terlebih dahulu dan path tersebut
     * yang dikembalikan agar runOcr() dapat membacanya.
     *
     * String/array (path yang sudah ada di disk) diteruskan apa adanya.
     */
    private function resolveOcrDiskPath(mixed $value): ?string
    {
        if ($value instanceof TemporaryUploadedFile) {
            return $this->storeOcrTemporaryFile($value);
        }

        if (is_array($value)) {
            foreach ($value as $uploadedFile) {
                if ($uploadedFile instanceof TemporaryUploadedFile) {
                    return $this->storeOcrTemporaryFile($uploadedFile);
                }
            }

            return null;
        }

        return $this->normalizePhotoPath($value);
    }

    /**
     * Salin byte TemporaryUploadedFile (hasil storeFiles(false)) ke disk
     * kk_uploads/ocr-tmp agar runOcr() dapat membacanya, lalu kembalikan
     * path-nya.
     */
    private function storeOcrTemporaryFile(
        TemporaryUploadedFile $file
    ): ?string {
        $bytes = $file->get();

        if ($bytes === null || $bytes === '') {
            return null;
        }

        $extension = 'jpg';

        if (
            preg_match(
                '/\.(png|jpe?g)$/i',
                (string) $file->getClientOriginalName(),
                $match,
            )
        ) {
            $extension = strtolower($match[1]) === 'jpeg'
                ? 'jpg'
                : 'png';
        }

        $path = 'ocr-tmp/'.Str::uuid().'.'.$extension;

        Storage::disk(KkPhotoService::DISK)->put(
            $path,
            $bytes,
        );

        $this->ocrTempPath = $path;

        return $path;
    }

    /**
     * Hapus file OCR temp di disk kk_uploads apabila berasal dari
     * TemporaryUploadedFile.
     */
    private function cleanupOcrTemp(mixed $rawValue): void
    {
        if (
            $rawValue instanceof TemporaryUploadedFile
            && $this->ocrTempPath !== null
            && Storage::disk(KkPhotoService::DISK)->exists($this->ocrTempPath)
        ) {
            Storage::disk(KkPhotoService::DISK)->delete($this->ocrTempPath);
        }

        $this->ocrTempPath = null;
    }

    /**
     * Pesan error OCR yang ramah operator.
     */
    private function ocrErrorMessage(Throwable $e): string
    {
        $message = strtolower($e->getMessage());

        if (
            str_contains($message, 'array to string conversion')
            || str_contains($message, 'array given')
        ) {
            return 'Format data foto belum siap diproses. '
                .'Tunggu upload selesai, kemudian tekan Scan Foto KK kembali.';
        }

        if (
            str_contains($message, 'could not be decoded')
            || str_contains($message, 'image could not be decoded')
        ) {
            return 'File foto tidak dapat dibaca sebagai gambar. '
                .'Gunakan JPG atau PNG yang dapat dibuka dengan normal.';
        }

        if (str_contains($message, 'resolution below minimum')) {
            return 'Resolusi foto terlalu rendah untuk OCR. '
                .'Gunakan foto KK yang lebih jelas dan beresolusi lebih tinggi.';
        }

        if (
            str_contains($message, 'source image')
            || str_contains($message, 'file does not exist')
            || str_contains($message, 'unable to load')
        ) {
            return 'File foto KK tidak ditemukan atau belum selesai disimpan. '
                .'Silakan upload ulang foto KK.';
        }

        return 'Foto berhasil diterima, tetapi proses OCR gagal. '
            .'Periksa kualitas foto lalu coba Scan Foto KK kembali.';
    }

    /**
     * Run the existing OCR pipeline.
     *
     * No domain data is written here.
     */
    private function runOcr(string $path): ParsedOcrResult
    {
        $disk = Storage::disk(KkPhotoService::DISK);

        if (! $disk->exists($path)) {
            throw new \RuntimeException(
                'File foto KK tidak ditemukan pada penyimpanan.'
            );
        }

        $contents = $disk->get($path);

        if ($contents === '') {
            throw new \RuntimeException(
                'File foto KK kosong dan tidak dapat diproses.'
            );
        }

        $job = OcrJob::create([
            'kk_id' => $this->record?->getKey(),
            'source_image_hash' => hash('sha256', $contents),
            'source_image_path' => $path,
            'status' => OcrJobStatus::PENDING,
            'operator_id' => auth()->id(),
            'started_at' => now(),
        ]);

        $processing = app(OcrProcessingService::class);

        $processing->start($job);
        $processing->extract($job);

        return $processing->parse($job);
    }

    /**
     * Put OCR's KK-level data into the edit form and retain
     * member results separately for review.
     */
    private function applyParsed(ParsedOcrResult $parsed): void
    {
        $data = $this->data ?? [];

        if ($parsed->kkNumber !== null) {
            $data['kk_number'] = $parsed->kkNumber;
        }

        if ($parsed->address !== null) {
            $data['address'] = $parsed->address;
        }

        /*
         * Wilayah belongs to the KK.
         *
         * Do not overwrite an RT that the operator already selected.
         */
        if (blank($data['rt_id'] ?? null)) {
            $rt = $this->resolveRt($parsed->rt);

            if ($rt !== null) {
                $data['area_unit_id'] = $rt->area_unit_id;
                $data['rt_id'] = $rt->id;
            }
        }

        $this->form->fill($data);
        $this->data = $data;

        $this->ocrPreview = [
            'kk_number' => $parsed->kkNumber,
            'address' => $parsed->address,
            'rt' => $parsed->rt,
            'members' => array_map(
                fn (ParsedResident $member): array => $this->memberFromParsed($member),
                $parsed->members,
            ),
            'confidence' => $parsed->confidence,
            'validation_errors' => $parsed->validationErrors,
            'warnings' => $parsed->warnings,
        ];
    }

    /**
     * Convert the parsed OCR resident into a display-safe array.
     *
     * This does NOT create/update Penduduk.
     */
    private function memberFromParsed(ParsedResident $member): array
    {
        return [
            'full_name' => $member->nama ?? '',
            'nik' => $member->nik ?? '',
            'gender' => $this->genderLabel($member->gender),
            'birth_place' => $member->birthPlace ?? '',
            'birth_date' => $member->birthDate ?? '',
            'religion' => $member->religion ?? '',
            'education' => $member->education ?? '',
            'occupation' => $member->occupation ?? '',
            'marital_status' => $this->maritalLabel($member->maritalStatus),
            'family_relation' => $this->relationLabel($member->familyRelation),
            'confidence' => $member->confidence,
            'low_confidence' => $member->lowConfidence,
        ];
    }

    private function genderLabel(?string $value): string
    {
        return match ($value) {
            Gender::LAKI_LAKI->value => 'Laki-laki',
            Gender::PEREMPUAN->value => 'Perempuan',
            default => $value ?: '-',
        };
    }

    private function maritalLabel(?string $value): string
    {
        return match ($value) {
            MaritalStatus::BELUM_KAWIN->value => 'Belum Kawin',
            MaritalStatus::KAWIN->value => 'Kawin',
            MaritalStatus::CERAI_HIDUP->value => 'Cerai Hidup',
            MaritalStatus::CERAI_MATI->value => 'Cerai Mati',
            default => $value ?: '-',
        };
    }

    private function relationLabel(?string $value): string
    {
        return match ($value) {
            FamilyRelation::KEPALA_KELUARGA->value => 'Kepala Keluarga',
            FamilyRelation::ISTRI->value => 'Istri',
            FamilyRelation::ANAK->value => 'Anak',
            FamilyRelation::MENANTU->value => 'Menantu',
            FamilyRelation::CUCU->value => 'Cucu',
            FamilyRelation::ORANG_TUA->value => 'Orang Tua',
            FamilyRelation::MERTUA->value => 'Mertua',
            FamilyRelation::FAMILI_LAIN->value => 'Famili Lain',
            FamilyRelation::LAINNYA->value => 'Lainnya',
            default => $value ?: '-',
        };
    }

    /**
     * Resolve RT from OCR text.
     *
     * This follows the existing CreateKartuKeluarga behavior:
     * RT is resolved by its number.
     */
    private function resolveRt(?string $number): ?Rt
    {
        $number = trim((string) $number);

        if ($number === '') {
            return null;
        }

        $normalized = ltrim($number, '0');

        if ($normalized === '') {
            $normalized = '0';
        }

        return Rt::query()
            ->where(function ($query) use ($number, $normalized): void {
                $query
                    ->where('number', $number)
                    ->orWhere('number', str_pad($normalized, 2, '0', STR_PAD_LEFT))
                    ->orWhere('number', str_pad($normalized, 3, '0', STR_PAD_LEFT));
            })
            ->first();
    }

    /**
     * Render the transient OCR result directly inside the Edit page.
     */
    private function renderOcrPreview(): Htmlable
    {
        $preview = $this->ocrPreview;

        $members = $preview['members'] ?? [];

        $html = '<div class="space-y-5">';

        $html .= '<div class="grid gap-4 md:grid-cols-3">';

        $html .= $this->previewCard(
            'Nomor KK',
            e($preview['kk_number'] ?? '-'),
        );

        $html .= $this->previewCard(
            'RT / RW',
            e($preview['rt'] ?? '-'),
        );

        $html .= $this->previewCard(
            'Confidence',
            e(number_format(
                (float) ($preview['confidence'] ?? 0),
                1,
                ',',
                '.',
            ).'%'),
        );

        $html .= '</div>';

        $html .= '<div>';
        $html .= '<div class="mb-2 text-sm font-semibold">Alamat</div>';
        $html .= '<div class="rounded-lg border p-3 text-sm">';
        $html .= e($preview['address'] ?? '-');
        $html .= '</div>';
        $html .= '</div>';

        if (! empty($preview['warnings'])) {
            $html .= '<div class="rounded-lg border p-3 text-sm">';
            $html .= '<div class="mb-2 font-semibold">Peringatan</div>';
            $html .= '<ul class="list-disc space-y-1 pl-5">';

            foreach ($preview['warnings'] as $warning) {
                $html .= '<li>'.e((string) $warning).'</li>';
            }

            $html .= '</ul>';
            $html .= '</div>';
        }

        if (! empty($preview['validation_errors'])) {
            $html .= '<div class="rounded-lg border p-3 text-sm">';
            $html .= '<div class="mb-2 font-semibold">Validasi</div>';
            $html .= '<ul class="list-disc space-y-1 pl-5">';

            foreach ($preview['validation_errors'] as $error) {
                $html .= '<li>'.e((string) $error).'</li>';
            }

            $html .= '</ul>';
            $html .= '</div>';
        }

        $html .= '<div>';
        $html .= '<div class="mb-2 flex items-center justify-between">';
        $html .= '<div class="text-sm font-semibold">Anggota Terdeteksi</div>';
        $html .= '<div class="text-sm text-gray-500">';
        $html .= count($members).' orang';
        $html .= '</div>';
        $html .= '</div>';

        if (empty($members)) {
            $html .= '<div class="rounded-lg border border-dashed p-6 text-center text-sm text-gray-500">';
            $html .= 'Tidak ada anggota yang berhasil dikenali.';
            $html .= '</div>';
        } else {
            $html .= '<div class="overflow-x-auto rounded-lg border">';
            $html .= '<table class="w-full text-sm">';
            $html .= '<thead>';
            $html .= '<tr class="border-b text-left">';
            $html .= '<th class="px-3 py-2">No.</th>';
            $html .= '<th class="px-3 py-2">Nama</th>';
            $html .= '<th class="px-3 py-2">NIK</th>';
            $html .= '<th class="px-3 py-2">Jenis Kelamin</th>';
            $html .= '<th class="px-3 py-2">Tanggal Lahir</th>';
            $html .= '<th class="px-3 py-2">Hubungan</th>';
            $html .= '<th class="px-3 py-2">Confidence</th>';
            $html .= '</tr>';
            $html .= '</thead>';
            $html .= '<tbody>';

            foreach ($members as $index => $member) {
                $html .= '<tr class="border-b last:border-0">';

                $html .= '<td class="px-3 py-2">';
                $html .= ($index + 1);
                $html .= '</td>';

                $html .= '<td class="px-3 py-2 font-medium">';
                $html .= e($member['full_name'] ?: '-');
                $html .= '</td>';

                $html .= '<td class="px-3 py-2 font-mono">';
                $html .= e($member['nik'] ?: '-');
                $html .= '</td>';

                $html .= '<td class="px-3 py-2">';
                $html .= e($member['gender'] ?: '-');
                $html .= '</td>';

                $html .= '<td class="px-3 py-2">';
                $html .= e($member['birth_date'] ?: '-');
                $html .= '</td>';

                $html .= '<td class="px-3 py-2">';
                $html .= e($member['family_relation'] ?: '-');
                $html .= '</td>';

                $html .= '<td class="px-3 py-2">';
                $html .= e(number_format(
                    (float) $member['confidence'],
                    1,
                    ',',
                    '.',
                ).'%');
                $html .= '</td>';

                $html .= '</tr>';
            }

            $html .= '</tbody>';
            $html .= '</table>';
            $html .= '</div>';
        }

        $html .= '</div>';
        $html .= '</div>';

        return new HtmlString($html);
    }

    private function previewCard(string $label, string $value): string
    {
        return
            '<div class="rounded-lg border p-3">'
            .'<div class="text-xs text-gray-500">'
            .$label
            .'</div>'
            .'<div class="mt-1 font-medium">'
            .$value
            .'</div>'
            .'</div>';
    }
}
