<?php

namespace App\Services;

use App\Enums\FamilyRelation;
use App\Enums\OcrJobStatus;
use App\Models\OcrJob;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Operator review & validation layer for parsed OCR data (Phase 5.6).
 *
 * Consumes the Phase 5.5 {@see ParsedOcrResult} and produces the
 * "validation before approval" gate (ADR-009 — OCR is an assistant, never
 * auto-saves). Only project-defined fields are validated, and the rule set is
 * grounded in the actual database schema (kk_number 16 digits, address and the
 * NOT NULL penduduk columns) so a passing result is importable by a later
 * phase without further surprises.
 *
 * No database writes, no KK/Penduduk creation, no OcrJob mutation: this
 * service only reads a parsed result (plus operator corrections) and returns
 * an in-memory {@see OcrReviewResult}.
 */
final class OcrReviewService
{
    /** @var array<string, string> field path => required-field label */
    public const REQUIRED_FIELDS = [
        'kk_number' => 'Nomor KK',
        'address' => 'Alamat',
        'members' => 'Anggota keluarga',
        'members.*.nik' => 'NIK',
        'members.*.nama' => 'Nama',
        'members.*.gender' => 'Jenis kelamin',
        'members.*.birth_place' => 'Tempat lahir',
        'members.*.birth_date' => 'Tanggal lahir',
        'members.*.religion' => 'Agama',
        'members.*.education' => 'Pendidikan',
        'members.*.occupation' => 'Pekerjaan',
        'members.*.marital_status' => 'Status perkawinan',
        'members.*.family_relation' => 'Status hubungan keluarga',
    ];

    /** @var array<int, string> */
    private const GENDERS = ['LAKI_LAKI', 'PEREMPUAN'];

    /** @var array<int, string> */
    private const MARITAL_STATUSES = ['BELUM_KAWIN', 'KAWIN', 'CERAI_HIDUP', 'CERAI_MATI'];

    /**
     * Whether a job can be reviewed: a terminal OCR state with raw text to
     * re-parse. Anything else (pending/failed, or success without text) is
     * rejected by the review page before the form is offered.
     */
    public static function isReviewable(OcrJob $job): bool
    {
        return in_array($job->status, [OcrJobStatus::SUCCESS, OcrJobStatus::LOW_CONFIDENCE], true)
            && filled($job->raw_text);
    }

    /**
     * Validate parsed data (with optional operator corrections) before
     * approval. Purely in-memory: never writes, never imports.
     *
     * @param  array<string, mixed>  $corrections  operator-edited form data,
     *                                             keyed like the review form
     *                                             (kk_number, address, rt, rw,
     *                                             lingkungan, members[])
     */
    public function validate(ParsedOcrResult $parsed, array $corrections = []): OcrReviewResult
    {
        $startedAt = microtime(true);
        $data = $this->effectiveData($parsed, $corrections);
        $errors = $this->validateData($data);

        $this->log($parsed, count($errors), $startedAt);

        return new OcrReviewResult($errors === [], $errors, $data, round((microtime(true) - $startedAt) * 1000, 2));
    }

    /**
     * Labels of the required fields still empty in the given review data —
     * the page highlights these as "wajib diisi" (missing-required).
     *
     * @param  array<string, mixed>  $data  review form data
     * @return array<int, string>
     */
    public function missingRequiredFields(array $data): array
    {
        return array_values(array_filter(
            $this->validateData($data),
            static fn (string $message): bool => str_ends_with($message, 'wajib diisi'),
        ));
    }

    /**
     * Confidence band per .ai/ocr.md §5: >=90 normal (null), 70–90 subtle
     * yellow (warning), <70 red (danger) with "Harap periksa".
     */
    public static function confidenceBand(float $confidence): ?string
    {
        if ($confidence >= 90.0) {
            return null;
        }

        return $confidence >= 70.0 ? 'warning' : 'danger';
    }

    /**
     * Merge the parsed baseline with operator corrections (per-field override;
     * members merged per row index) into one effective review dataset.
     *
     * @param  array<string, mixed>  $corrections
     * @return array<string, mixed>
     */
    private function effectiveData(ParsedOcrResult $parsed, array $corrections): array
    {
        $base = [
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
                    'family_relation' => self::normalizeRelationValue($member->familyRelation ?? '') ?? ($member->familyRelation ?? ''),
                ],
                $parsed->members,
            ),
        ];

        $data = array_replace($base, array_intersect_key($corrections, $base));

        if (isset($corrections['members']) && is_array($corrections['members'])) {
            foreach ($corrections['members'] as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                $data['members'][$index] = array_replace($data['members'][$index] ?? [], $item);
            }
        }

        return $data;
    }

    /**
     * Field-keyed validation errors for the effective review data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, string>
     */
    private function validateData(array $data): array
    {
        $errors = [];

        if ($data['kk_number'] === '') {
            $errors['kk_number'] = 'Nomor KK wajib diisi';
        } elseif (! preg_match('/^\d{16}$/', $data['kk_number'])) {
            $errors['kk_number'] = 'Nomor KK harus 16 digit angka';
        }

        if ($data['address'] === '') {
            $errors['address'] = 'Alamat wajib diisi';
        }

        $members = $data['members'] ?? [];

        if ($members === []) {
            $errors['members'] = 'Minimal satu anggota keluarga wajib diisi';
        }

        foreach ($members as $index => $member) {
            if (! is_array($member)) {
                continue;
            }
            $ordinal = $index + 1;
            $path = "members.{$index}.";

            if ($this->blank($member['nik'] ?? null)) {
                $errors["{$path}nik"] = "NIK anggota ke-{$ordinal} wajib diisi";
            } elseif (! preg_match('/^\d{16}$/', $member['nik'] ?? '')) {
                $errors["{$path}nik"] = "NIK anggota ke-{$ordinal} harus 16 digit angka";
            }

            if ($this->blank($member['nama'] ?? null)) {
                $errors["{$path}nama"] = "Nama anggota ke-{$ordinal} wajib diisi";
            }

            if ($this->blank($member['gender'] ?? null)) {
                $errors["{$path}gender"] = "Jenis kelamin anggota ke-{$ordinal} wajib diisi";
            } elseif (! in_array($member['gender'], self::GENDERS, true)) {
                $errors["{$path}gender"] = "Jenis kelamin anggota ke-{$ordinal} tidak dikenal";
            }

            if ($this->blank($member['birth_place'] ?? null)) {
                $errors["{$path}birth_place"] = "Tempat lahir anggota ke-{$ordinal} wajib diisi";
            }

            if ($this->blank($member['birth_date'] ?? null)) {
                $errors["{$path}birth_date"] = "Tanggal lahir anggota ke-{$ordinal} wajib diisi";
            } elseif (! $this->validBirthDate($member['birth_date'])) {
                $errors["{$path}birth_date"] = "Tanggal lahir anggota ke-{$ordinal} tidak valid";
            }

            foreach (['religion' => 'Agama', 'education' => 'Pendidikan', 'occupation' => 'Pekerjaan'] as $field => $label) {
                if ($this->blank($member[$field] ?? null)) {
                    $errors["{$path}{$field}"] = "{$label} anggota ke-{$ordinal} wajib diisi";
                }
            }

            if ($this->blank($member['marital_status'] ?? null)) {
                $errors["{$path}marital_status"] = "Status perkawinan anggota ke-{$ordinal} wajib diisi";
            } elseif (! in_array($member['marital_status'], self::MARITAL_STATUSES, true)) {
                $errors["{$path}marital_status"] = "Status perkawinan anggota ke-{$ordinal} tidak dikenal";
            }

            $rel = self::normalizeRelationValue($member['family_relation'] ?? null);
            if ($this->blank($member['family_relation'] ?? null)) {
                $errors["{$path}family_relation"] = "Status hubungan keluarga anggota ke-{$ordinal} wajib diisi";
            } elseif ($rel === null) {
                $errors["{$path}family_relation"] = "Status hubungan keluarga anggota ke-{$ordinal} tidak dikenal";
            }
        }

        return $errors;
    }

    public static function normalizeRelationValue(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $upper = strtoupper(trim((string) $value));

        return match ($upper) {
            'KEPALA_KELUARGA', 'KEPALA KELUARGA', 'KEPALA KEL.', 'KEPALA KEL', 'KEPALAKELUARGA', 'KEPALAKEUARGA', 'KEPALAKEL', 'KEPALA' => FamilyRelation::KEPALA_KELUARGA->value,
            'ISTRI', 'ISTERI', '1STRI', 'ISTRI KEPALA KELUARGA' => FamilyRelation::ISTRI->value,
            'ANAK', 'ANAK2', 'ANAK-', 'AN4K', 'ANAK KANDUNG', 'ANAK ANGKAT', 'ANAK TIRI' => FamilyRelation::ANAK->value,
            'MENANTU' => FamilyRelation::MENANTU->value,
            'CUCU' => FamilyRelation::CUCU->value,
            'ORANG_TUA', 'ORANG TUA', 'ORANGTUA', 'BAPAK', 'IBU', 'AYAH' => FamilyRelation::ORANG_TUA->value,
            'MERTUA' => FamilyRelation::MERTUA->value,
            'FAMILI_LAIN', 'FAMILI LAIN', 'FAMILI LAINNYA', 'FAMILI', 'FAMILILAIN' => FamilyRelation::FAMILI_LAIN->value,
            'PEMBANTU', 'LAINNYA', 'LAIN' => FamilyRelation::LAINNYA->value,
            default => FamilyRelation::tryFrom($upper)?->value ?? null,
        };
    }

    /**
     * Accepts Y-m-d (parser output) or d/m/Y / d-m-Y (manual entry).
     */
    private function validBirthDate(string $value): bool
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('~^(\d{1,2})[-/](\d{1,2})[-/](\d{2,4})$~', $value, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($year < 100) {
                $year += 2000;
            }
        } else {
            return false;
        }

        return checkdate($month, $day, $year)
            && $year >= 1900
            && $year <= (int) date('Y');
    }

    /** @return array<int, string> */
    private static function familyRelations(): array
    {
        return array_map(
            static fn (FamilyRelation $relation): string => $relation->value,
            FamilyRelation::cases(),
        );
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function log(ParsedOcrResult $parsed, int $errorCount, float $startedAt): void
    {
        try {
            Log::info('OCR review validation '.($errorCount === 0 ? 'success' : 'validation_error'), [
                'pipeline_stage' => 'validate',
                'outcome' => $errorCount === 0 ? 'success' : 'validation_error',
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'confidence' => $parsed->confidence,
                'member_count' => $parsed->memberCount(),
                'error_count' => $errorCount,
            ]);
        } catch (Throwable) {
            // Logging must never break the review flow.
        }
    }
}
