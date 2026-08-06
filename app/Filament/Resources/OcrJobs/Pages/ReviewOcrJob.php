<?php

namespace App\Filament\Resources\OcrJobs\Pages;

use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Filament\Resources\OcrJobs\OcrJobResource;
use App\Models\OcrJob;
use App\Services\OcrParsingService;
use App\Services\OcrReviewService;
use App\Services\ParsedOcrResult;
use App\Services\ParsedResident;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;

/**
 * Operator review layer for parsed OCR data (Phase 5.6).
 *
 * Loads a finished OCR job, re-parses its raw text in-memory (never writing),
 * displays every parsed field, highlights missing required fields and
 * low-confidence values (.ai/ocr.md §5), lets the operator correct them, and
 * runs the pre-approval validation gate. Nothing is saved to the database —
 * the review is an assistant (ADR-009); importing the accepted data is a
 * later phase.
 */
class ReviewOcrJob extends Page
{
    use InteractsWithRecord;

    protected static string $resource = OcrJobResource::class;

    protected static ?string $title = 'Review Hasil OCR';

    protected string $view = 'filament.resources.ocr-jobs.review-ocr-job';

    public ?string $rejectedReason = null;

    /**
     * Livewire state container for the review form (Filament v4 pattern:
     * a public `$data` array bound via `statePath('data')`).
     *
     * @var array<string, mixed>
     */
    public ?array $data = [];

    protected ?ParsedOcrResult $parsed = null;

    protected OcrReviewService $review;

    public function boot(): void
    {
        $this->review = app(OcrReviewService::class);
    }

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $this->ensureParsed();

        if ($this->parsed !== null) {
            $this->form->fill($this->toFormState($this->parsed));
        }
    }

    /**
     * "Validasi Data" — the pre-approval validation gate. Validates the
     * operator-corrected form state against the schema-derived rules and
     * reports the outcome. Never persists, never imports.
     */
    public function validateReview(): void
    {
        $this->ensureParsed();

        if ($this->rejectedReason !== null || $this->parsed === null) {
            Notification::make()
                ->title('Belum dapat divalidasi')
                ->body('Hasil OCR belum tersedia untuk direview.')
                ->danger()
                ->send();

            return;
        }

        $data = $this->form->getState(); // rule violations surface as field errors
        $result = $this->review->validate($this->parsed, $data);

        if (! $result->isValid()) {
            Notification::make()
                ->title('Validasi gagal')
                ->body('Perbaiki field yang ditandai, lalu validasi kembali.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Validasi berhasil')
            ->body('Data siap diimpor. (Belum ada data yang disimpan.)')
            ->success()
            ->send();
    }

    public function defaultForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('data');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            ...$this->statusComponents(),
            Section::make('Data Kartu Keluarga')
                ->description('Periksa dan perbaiki field yang diuraikan dari hasil OCR.')
                ->schema([
                    Grid::make(3)
                        ->schema([
                            TextInput::make('kk_number')
                                ->label('Nomor KK')
                                ->required()
                                ->maxLength(16)
                                ->regex('/^\d{16}$/', 'Harus 16 digit angka'),
                            TextInput::make('rt')
                                ->label('RT')
                                ->maxLength(3),
                            TextInput::make('rw')
                                ->label('RW')
                                ->maxLength(3),
                        ]),
                    TextInput::make('address')
                        ->label('Alamat')
                        ->required()
                        ->columnSpanFull(),
                    TextInput::make('lingkungan')
                        ->label('Lingkungan')
                        ->columnSpanFull(),
                ]),
            Section::make('Anggota Keluarga')
                ->description('Periksa tiap anggota. Kolom dengan nilai kosong harus diisi manual sebelum validasi.')
                ->schema([
                    Repeater::make('members')
                        ->label('Anggota keluarga')
                        ->addActionLabel('Tambah anggota')
                        ->defaultItems(0)
                        ->columns(1)
                        ->itemLabel(fn (?int $index): string => $this->memberLabel($index))
                        ->schema([
                            Grid::make(4)
                                ->schema([
                                    TextInput::make('nama')->label('Nama')->required(),
                                    TextInput::make('nik')->label('NIK')->required()->maxLength(16)->regex('/^\d{16}$/', 'Harus 16 digit angka'),
                                    Select::make('gender')->label('Jenis kelamin')->required()->options($this->genderOptions()),
                                    TextInput::make('birth_place')->label('Tempat lahir')->required(),
                                ]),
                            Grid::make(4)
                                ->schema([
                                    TextInput::make('birth_date')->label('Tanggal lahir')->placeholder('YYYY-MM-DD')->required(),
                                    Select::make('religion')->label('Agama')->required()->options(OcrParsingService::religionOptions()),
                                    Select::make('education')->label('Pendidikan')->required()->options(OcrParsingService::educationOptions()),
                                    Select::make('occupation')->label('Pekerjaan')->required()->options(OcrParsingService::occupationOptions()),
                                ]),
                            Grid::make(2)
                                ->schema([
                                    Select::make('marital_status')->label('Status perkawinan')->required()->options($this->maritalOptions()),
                                    Select::make('family_relation')->label('Status hubungan keluarga')->required()->options($this->relationOptions()),
                                ]),
                        ]),
                ]),
        ]);
    }

    /**
     * Load the job record (once per request) and re-parse its raw text for
     * review. Never touches form state — callers decide whether to hydrate.
     */
    protected function ensureParsed(): void
    {
        if ($this->rejectedReason !== null || $this->parsed !== null) {
            return;
        }

        /** @var OcrJob|null $job */
        $job = $this->record instanceof OcrJob ? $this->record : null;

        if ($job === null) {
            $this->rejectedReason = 'Dokumen OCR tidak ditemukan.';

            return;
        }

        if (! OcrReviewService::isReviewable($job)) {
            $this->rejectedReason = 'Hasil OCR belum siap direview (status: '.($job->status?->value ?? 'unknown').'). Uraikan OCR terlebih dahulu.';

            return;
        }

        $this->parsed = app(OcrParsingService::class)->parse((string) $job->raw_text, (float) $job->confidence);
    }

    /**
     * Status sections: parse problems, missing required fields and
     * low-confidence members — the highlights the review page must show.
     *
     * @return array<int, Section>
     */
    private function statusComponents(): array
    {
        $components = [];

        if ($this->parsed?->isEmpty() ?? false) {
            $components[] = Section::make('OCR tidak menghasilkan data')
                ->description('Tidak ada field yang terbaca — isi form secara manual.')
                ->schema([
                    Text::make('Isi form secara manual, lalu validasi.')
                        ->badge()
                        ->color('danger'),
                ]);
        }

        if (($this->parsed?->validationErrors ?? []) !== []) {
            $components[] = Section::make('Masalah yang terdeteksi saat parsing')
                ->description('Masalah ini bersumber dari hasil parsing dan harus diperbaiki.')
                ->schema([
                    UnorderedList::make($this->parsed->validationErrors),
                ]);
        }

        $missing = $this->review->missingRequiredFields($this->currentData());

        if ($missing !== []) {
            $components[] = Section::make('Field wajib belum diisi')
                ->description('Lengkapi field di bawah ini sebelum validasi.')
                ->schema([
                    Text::make('Wajib diisi')
                        ->badge()
                        ->color('danger'),
                    UnorderedList::make($missing),
                ]);
        }

        $lowConfidence = $this->lowConfidenceMembers();

        if ($lowConfidence !== []) {
            $components[] = Section::make('Confidence rendah — periksa ulang')
                ->description('Nilai dengan confidence rendah harus dicek ulang terhadap dokumen asli.')
                ->schema([
                    Text::make('Harap periksa')
                        ->badge()
                        ->color($this->lowConfidenceBand() === 'danger' ? 'danger' : 'warning'),
                    UnorderedList::make($lowConfidence),
                ]);
        }

        return $components;
    }

    private function memberLabel(?int $index): string
    {
        return 'Anggota '.((int) ($index ?? 0) + 1);
    }

    /**
     * @return array<string, string>
     */
    private function genderOptions(): array
    {
        return array_combine(
            array_column(Gender::cases(), 'value'),
            array_map(static fn (Gender $gender): string => str_replace('_', ' ', $gender->value), Gender::cases()),
        );
    }

    /**
     * @return array<string, string>
     */
    private function maritalOptions(): array
    {
        return array_combine(
            array_column(MaritalStatus::cases(), 'value'),
            array_map(static fn (MaritalStatus $status): string => str_replace('_', ' ', $status->value), MaritalStatus::cases()),
        );
    }

    /**
     * @return array<string, string>
     */
    private function relationOptions(): array
    {
        return array_combine(
            array_column(FamilyRelation::cases(), 'value'),
            array_map(static fn (FamilyRelation $relation): string => str_replace('_', ' ', $relation->value), FamilyRelation::cases()),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function toFormState(ParsedOcrResult $parsed): array
    {
        return [
            'kk_number' => $parsed->kkNumber ?? '',
            'address' => $parsed->address ?? '',
            'rt' => $parsed->rt ?? '',
            'rw' => $parsed->rw ?? '',
            'lingkungan' => $parsed->lingkungan ?? '',
            'members' => array_map(
                static fn (ParsedResident $member): array => [
                    'nama' => $member->nama ?? '',
                    'nik' => $member->nik ?? '',
                    'gender' => $member->gender ?? '',
                    'birth_place' => $member->birthPlace ?? '',
                    'birth_date' => $member->birthDate ?? '',
                    'religion' => $member->religion ?? '',
                    'education' => $member->education ?? '',
                    'occupation' => $member->occupation ?? '',
                    'marital_status' => $member->maritalStatus ?? '',
                    'family_relation' => $member->familyRelation ?? '',
                ],
                $parsed->members,
            ),
        ];
    }

    /**
     * Current review data for the status highlights. While the schema is
     * being built the form is not yet resolvable (this method is called from
     * within {@see form()}), so fall back to the parsed baseline then.
     *
     * @return array<string, mixed>
     */
    private function currentData(): array
    {
        if ($this->isCachingSchemas()) {
            $data = $this->parsed !== null ? $this->toFormState($this->parsed) : ($this->data ?? []);
        } else {
            $data = $this->form->getRawState();
        }

        // The Repeater keys its items with UUIDs once the schema hydrates the
        // state; the review service expects a plain numeric list.
        if (isset($data['members']) && is_array($data['members'])) {
            $data['members'] = array_values($data['members']);
        }

        return $data;
    }

    private function lowConfidenceBand(): ?string
    {
        $band = null;

        foreach ($this->parsed?->members ?? [] as $member) {
            $memberBand = OcrReviewService::confidenceBand($member->confidence);
            if ($memberBand === 'danger') {
                return 'danger';
            }
            $band ??= $memberBand;
        }

        return $band;
    }

    /**
     * Operator-facing lines listing low-confidence members.
     *
     * @return array<int, string>
     */
    private function lowConfidenceMembers(): array
    {
        if ($this->parsed === null) {
            return [];
        }

        $lines = [];

        foreach ($this->parsed->members as $index => $member) {
            $band = OcrReviewService::confidenceBand($member->confidence);

            if ($band === null) {
                continue;
            }

            $lines[] = sprintf(
                'Anggota %d (%s) — %s (confidence %.1f)',
                $index + 1,
                $member->nama ?: 'NIK '.($member->nik ?: '?'),
                $band === 'danger' ? 'Harap periksa' : 'Periksa',
                $member->confidence,
            );
        }

        return $lines;
    }
}
