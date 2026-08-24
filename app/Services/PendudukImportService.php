<?php

namespace App\Services;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\KkAnggotaStatus;
use App\Enums\MaritalStatus;
use App\Enums\OcrOutcome;
use App\Enums\ResidentStatus;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\KkAnggota;
use App\Models\Occupation;
use App\Models\OcrJob;
use App\Models\Penduduk;
use App\Models\Religion;
use App\Models\Rt;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use OpenSpout\Reader\Common\Creator\ReaderFactory;
use Throwable;

/**
 * Persist Penduduk records from an approved OCR review (Phase 5.8).
 *
 * The KartuKeluarga already exists (created by Phase 5.7), and the approved
 * review data — the Phase 5.6 corrected dataset — was snapshotted onto the OCR
 * job's `extracted_data` when 5.7 marked it SAVED. This service consumes that
 * approved snapshot and creates one `Penduduk` row (+ one ACTIVE `KkAnggota`
 * membership) per member, all linked to the KK.
 *
 * This is the operator-triggered write that completes ADR-009: OCR is an
 * assistant; the Service layer persists the approved family only after the
 * operator has reviewed and saved it (Phase 5.7 created the KK record).
 *
 * Guarantees:
 * - **Existing validation** — the approved snapshot is re-run through
 *   {@see OcrReviewService::validate()} (the same schema-grounded gate the
 *   review page uses) before any write; a tampered or incomplete dataset is
 *   rejected up front (`invalid`, zero writes).
 * - **Duplicate NIK detection** (FR-OCR-05) — `penduduk.nik` is unique; the
 *   approved NIK set is checked for intra-list repeats and against existing
 *   `penduduk` rows, and the insert is wrapped so a concurrent insert that
 *   wins the race also resolves to a `duplicate` result, never a partial
 *   write.
 * - **Domain mapping** — enumerated fields map onto the existing domain:
 *   gender / marital_status / family_relation / blood_type / resident_status
 *   are values from their enums (blood_type defaults to TIDAK_DIKETAHUI and
 *   resident_status to ACTIVE); religion / education / occupation resolve to
 *   the evolving lookup masters (`religions` / `educations` /
 *   `occupations`), created on the fly when an approved label is absent; the
 *   reviewed `rt` resolves to an existing `Rt` by number.
 * - **Transactional write** — every Penduduk + KkAnggota insert and the
 *   OCR-job update happen in one DB transaction; a failed job update rolls
 *   the whole family back (no orphan residents).
 * - **OCR job updated on success** — the approved snapshot in
 *   `extracted_data` is augmented with a `penduduk_imported_at` timestamp and
 *   the created `penduduk_ids`, recording the completed family import for
 *   audit. The `status` / `outcome` / `kk_id` columns are left untouched (they
 *   reflect the Phase 5.7 KK save).
 * - **Idempotence guard** — re-running the import on a job that already has a
 *   Penduduk-import marker returns `already_imported` and writes nothing.
 *
 * This class is intentionally not `final` and {@see markJobImported()} is
 * `protected` so rollback behaviour can be verified by a test subclass that
 * makes the job-save step fail.
 */
class PendudukImportService
{
    public function __construct(
        private readonly OcrParsingService $parsing,
        private readonly OcrReviewService $review,
    ) {}

    /**
     * Import the approved OCR review members into Penduduk under the KK the
     * job was saved against (Phase 5.7).
     *
     * @param  User|null  $operator  the operator running the import (recorded
     *                               on the OCR job's pending import marker)
     * @return PendudukImportResult the outcome — never throws for business
     *                              decisions (duplicate, invalid, already
     *                              imported), but rethrows a fatal error from
     *                              the write step
     *
     * @throws InvalidArgumentException when the job has no saved KK to import
     *                                  into (not yet Phase 5.7-imported)
     */
    public function import(OcrJob $job, ?User $operator = null): PendudukImportResult
    {
        $startedAt = microtime(true);

        $kk = $this->assertSavedKk($job);

        if ($this->alreadyImported($job)) {
            return PendudukImportResult::alreadyImported($kk);
        }

        $data = $job->extracted_data ?? [];
        $review = $this->review->validate(
            $this->parsing->parse((string) $job->raw_text, (float) $job->confidence),
            $data,
        );

        if (! $review->isValid()) {
            return PendudukImportResult::invalid($review->errors(), $kk);
        }

        $members = $data['members'] ?? [];

        if ($members === [] || ! is_array($members)) {
            return PendudukImportResult::invalid(['members' => 'Tidak ada anggota keluarga untuk diimpor'], $kk);
        }

        $duplicateNik = $this->duplicateNik($members);

        if ($duplicateNik !== null) {
            return PendudukImportResult::duplicate($kk, $duplicateNik);
        }

        $rt = $this->resolveRt((string) ($data['rt'] ?? ''));

        if ($rt === null) {
            return PendudukImportResult::invalid(
                ['rt' => sprintf('RT tidak ditemukan di wilayah (\'%s\') — daftarkan RT terlebih dahulu.', (string) ($data['rt'] ?? ''))],
                $kk,
            );
        }

        try {
            $pendudukIds = DB::transaction(function () use ($job, $kk, $members, $rt, $operator): array {
                $ids = [];

                // Wilayah adalah milik Kartu Keluarga (ADR-004): simpan RT yang
                // di-resolve ke KK dulu, lalu setiap Penduduk mewarisi rt_id-nya
                // melalui hook Penduduk::booted(). Kolom penduduk.rt_id tetap
                // NOT NULL, jadi KK wajib punya RT sebelum Penduduk dibuat.
                if ($kk->rt_id !== $rt->id) {
                    $kk->rt_id = $rt->id;
                    $kk->save();
                }

                foreach ($members as $member) {
                    $penduduk = Penduduk::create($this->pendudukAttributes($kk, $member, $rt->id));
                    $ids[] = $penduduk->id;

                    // Preserve the parsed family relation in the membership-
                    // history table alongside the penduduk row (ADR-008).
                    KkAnggota::create([
                        'kk_id' => $kk->id,
                        'penduduk_id' => $penduduk->id,
                        'family_relation' => (string) ($member['family_relation'] ?? ''),
                        'status' => KkAnggotaStatus::AKTIF->value,
                        'effective_date' => now()->toDateString(),
                    ]);
                }

                $this->markJobImported($job, $ids, $operator);

                return $ids;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost the NIK insert race — the transaction rolled back and
            // nothing was persisted. Report against the colliding NIK.
            return PendudukImportResult::duplicate($kk, (string) ($this->firstExistingNik($members) ?? ''));
        } catch (Throwable $e) {
            $this->log('failure', $job, $kk->kk_number, $startedAt, $e);
            throw $e;
        }

        $this->log('saved', $job, $kk->kk_number, $startedAt);

        return PendudukImportResult::saved($kk, count($pendudukIds));
    }

    /**
     * Reject jobs whose KartuKeluarga has not been Phase 5.7-imported: the
     * Penduduk import needs the KK already created, the job marked SAVED and
     * its raw text available to re-run the review gate against the approved
     * snapshot.
     *
     * @throws InvalidArgumentException
     */
    private function assertSavedKk(OcrJob $job): KartuKeluarga
    {
        if ($job->kk_id === null || $job->outcome !== OcrOutcome::SAVED->value || ! filled($job->raw_text)) {
            throw new InvalidArgumentException(
                sprintf('OCR job %d has no imported KartuKeluarga to attach Penduduk to (expected outcome %s + kk_id, got %s).', $job->id, OcrOutcome::SAVED->value, $job->outcome ?? 'null')
            );
        }

        $kk = KartuKeluarga::find($job->kk_id);

        if ($kk === null) {
            throw new InvalidArgumentException(sprintf('OCR job %d points at kartu_keluarga id %d which no longer exists.', $job->id, $job->kk_id));
        }

        return $kk;
    }

    /**
     * The Penduduk import is idempotent: once the approved snapshot carries
     * the `penduduk_imported_at` marker the family has already been created.
     */
    private function alreadyImported(OcrJob $job): bool
    {
        $data = $job->extracted_data;

        return is_array($data) && isset($data['penduduk_imported_at']);
    }

    /**
     * Check the approved NIK list for duplicates among themselves and against
     * already-created `penduduk` rows. The first offending NIK is returned.
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    private function duplicateNik(array $members): ?string
    {
        $niks = [];

        foreach ($members as $member) {
            $nik = (string) ($member['nik'] ?? '');

            if ($nik === '') {
                continue;
            }

            if (in_array($nik, $niks, true)) {
                return $nik;
            }

            $niks[] = $nik;
        }

        return Penduduk::query()->whereIn('nik', $niks)->value('nik');
    }

    /**
     * The NIK that actually exists in `penduduk` after an insert race, so a
     * UniqueConstraintViolationException can report which NIK collided.
     *
     * @param  array<int, array<string, mixed>>  $members
     */
    private function firstExistingNik(array $members): ?string
    {
        foreach ($members as $member) {
            $nik = (string) ($member['nik'] ?? '');

            if ($nik !== '' && Penduduk::where('nik', $nik)->exists()) {
                return $nik;
            }
        }

        return null;
    }

    /**
     * Map an approved member row onto the existing Penduduk column set.
     *
     * @param  array<string, mixed>  $member
     * @return array<string, mixed>
     */
    private function pendudukAttributes(KartuKeluarga $kk, array $member, int $rtId): array
    {
        return [
            'kk_id' => $kk->id,
            'nik' => (string) ($member['nik'] ?? ''),
            'full_name' => (string) ($member['nama'] ?? ''),
            'gender' => (string) ($member['gender'] ?? ''),
            'birth_place' => (string) ($member['birth_place'] ?? ''),
            'birth_date' => $this->normalizeBirthDate((string) ($member['birth_date'] ?? '')),
            'religion_id' => $this->resolveLookupId(Religion::class, (string) ($member['religion'] ?? '')),
            'education_id' => $this->resolveLookupId(Education::class, (string) ($member['education'] ?? '')),
            'occupation_id' => $this->resolveLookupId(Occupation::class, (string) ($member['occupation'] ?? '')),
            'marital_status' => (string) ($member['marital_status'] ?? ''),
            'family_relation' => (string) ($member['family_relation'] ?? ''),
            'blood_type' => BloodType::TIDAK_DIKETAHUI->value,
            'resident_status' => ResidentStatus::ACTIVE->value,
            'rt_id' => $rtId,
        ];
    }

    /**
     * Resolve a lookup label (religion / education / occupation) to a row in
     * the corresponding master table, creating the master row (title-cased)
     * when the label is not yet present — the masters are a data-driven,
     * evolving taxonomy. Case-insensitive equality.
     *
     * @param  class-string<Model>  $model
     */
    private function resolveLookupId(string $model, string $label): int
    {
        $normalized = trim(preg_replace('/\s+/', ' ', $label) ?? $label);

        $existing = $model::query()
            ->whereRaw('UPPER(name) = ?', [mb_strtoupper($normalized)])
            ->value('id');

        if ($existing !== null) {
            return (int) $existing;
        }

        if ($model === Education::class) {
            $aliasGroups = [
                'D1' => ['D1', 'D-I', 'D I', 'DIPLOMA I', 'DIPLOMA 1', 'DIPLOMA I/II'],
                'D2' => ['D2', 'D-II', 'D II', 'DIPLOMA II', 'DIPLOMA 2'],
                'D3' => ['D3', 'D-III', 'D III', 'DIPLOMA III', 'DIPLOMA 3', 'AKADEMI', 'SARJANA MUDA', 'AKADEMI/DIPLOMA III/SARJANA MUDA'],
                'S1' => ['S1', 'S-I', 'S I', 'STRATA I', 'STRATA 1', 'SARJANA', 'D4', 'D-IV', 'D IV', 'DIPLOMA IV', 'DIPLOMA IV/STRATA I'],
                'S2' => ['S2', 'S-II', 'S II', 'STRATA II', 'STRATA 2', 'MAGISTER'],
                'S3' => ['S3', 'S-III', 'S III', 'STRATA III', 'STRATA 3', 'DOKTOR'],
                'SMA' => ['SMA', 'SMA/SEDERAJAT', 'SLTA', 'SLTA/SEDERAJAT', 'SMK', 'SMK/SEDERAJAT', 'MA', 'MA/SEDERAJAT'],
                'SMP' => ['SMP', 'SMP/SEDERAJAT', 'SLTP', 'SLTP/SEDERAJAT', 'MTS', 'MTS/SEDERAJAT'],
                'SD' => ['SD', 'SD/SEDERAJAT', 'TAMAT SD', 'TAMAT SD/SEDERAJAT', 'BELUM TAMAT SD', 'BELUM TAMAT SD/SEDERAJAT'],
                'Tidak/Belum Sekolah' => ['Tidak/Belum Sekolah', 'TIDAK/BELUM SEKOLAH', 'TIDAK BELUM SEKOLAH', 'BELUM SEKOLAH', 'TIDAK SEKOLAH'],
            ];

            $upperInput = mb_strtoupper($normalized);
            foreach ($aliasGroups as $targetCanonical => $groupAliases) {
                foreach ($groupAliases as $alias) {
                    if ($upperInput === mb_strtoupper($alias)) {
                        // Coba cari nama canonical target dulu (misal 'D1' atau 'SMA')
                        $targetId = $model::query()
                            ->whereRaw('UPPER(name) = ?', [mb_strtoupper($targetCanonical)])
                            ->value('id');
                        if ($targetId !== null) {
                            return (int) $targetId;
                        }

                        // Jika tidak ada nama target, coba cari alias lain yang sudah ada
                        foreach ($groupAliases as $candidate) {
                            $candId = $model::query()
                                ->whereRaw('UPPER(name) = ?', [mb_strtoupper($candidate)])
                                ->value('id');
                            if ($candId !== null) {
                                return (int) $candId;
                            }
                        }
                        break;
                    }
                }
            }
        }

        if ($model === Occupation::class) {
            $occGroups = [
                'Pegawai Negeri Sipil' => ['Pegawai Negeri Sipil', 'PEGAWAI NEGERI SIPIL', 'PNS', 'ASN', 'PEGAWAI NEGERI'],
                'Ibu Rumah Tangga' => ['Ibu Rumah Tangga', 'IBU RUMAH TANGGA', 'Mengurus Rumah Tangga', 'MENGURUS RUMAH TANGGA', 'RUMAH TANGGA', 'IRT'],
                'Buruh' => ['Buruh', 'BURUH', 'Buruh Harian Lepas', 'BURUH HARIAN LEPAS', 'Buruh Harian', 'BURUH HARIAN', 'Buruh Tani', 'BURUH TANI', 'Buruh Pabrik', 'BURUH PABRIK'],
                'Karyawan Swasta' => ['Karyawan Swasta', 'KARYAWAN SWASTA', 'Karyawan', 'KARYAWAN', 'Pegawai Swasta', 'PEGAWAI SWASTA', 'Karyawan BUMN', 'Karyawan BUMD', 'Swasta', 'SWASTA'],
                'Pelajar/Mahasiswa' => ['Pelajar/Mahasiswa', 'PELAJAR/MAHASISWA', 'Pelajar', 'PELAJAR', 'Mahasiswa', 'MAHASISWA', 'Pelajar Mahasiswa', 'PELAJAR MAHASISWA', 'Pelajarimahasiswa', 'PELAJARIMAHASISWA'],
                'Petani' => ['Petani', 'PETANI', 'Petani/Pekebun', 'PETANI/PEKEBUN', 'Pekebun', 'PEKEBUN', 'Petani Pekebun', 'PETANI PEKEBUN'],
                'Pedagang' => ['Pedagang', 'PEDAGANG', 'Perdagangan', 'PERDAGANGAN'],
                'Nelayan' => ['Nelayan', 'NELAYAN', 'Nelayan/Perikanan', 'NELAYAN/PERIKANAN', 'Perikanan', 'PERIKANAN'],
                'Wiraswasta' => ['Wiraswasta', 'WIRASWASTA', 'Wirausaha', 'WIRAUSAHA'],
                'Pensiunan' => ['Pensiunan', 'PENSIUNAN', 'Pensiun', 'PENSIUN'],
                'Tukang' => ['Tukang', 'TUKANG', 'Tukang Kayu', 'Tukang Batu', 'Tukang Jahit', 'Tukang Cukur', 'Tukang Las'],
                'Lainnya' => ['Lainnya', 'LAINNYA', 'Belum/Tidak Bekerja', 'BELUM/TIDAK BEKERJA', 'Belum Bekerja', 'BELUM BEKERJA', 'Tidak Bekerja', 'TIDAK BEKERJA'],
            ];

            $upperInput = mb_strtoupper($normalized);
            foreach ($occGroups as $targetCanonical => $groupAliases) {
                foreach ($groupAliases as $alias) {
                    if ($upperInput === mb_strtoupper($alias)) {
                        $targetId = $model::query()
                            ->whereRaw('UPPER(name) = ?', [mb_strtoupper($targetCanonical)])
                            ->value('id');
                        if ($targetId !== null) {
                            return (int) $targetId;
                        }

                        foreach ($groupAliases as $candidate) {
                            $candId = $model::query()
                                ->whereRaw('UPPER(name) = ?', [mb_strtoupper($candidate)])
                                ->value('id');
                            if ($candId !== null) {
                                return (int) $candId;
                            }
                        }
                        break;
                    }
                }
            }
        }

        if ($model === Religion::class) {
            $relGroups = [
                'Islam' => ['Islam', 'ISLAM'],
                'Kristen' => ['Kristen', 'KRISTEN', 'Kristen Protestan', 'PROTESTAN', 'Protestan'],
                'Katolik' => ['Katolik', 'KATOLIK', 'Catholic', 'CATHOLIC'],
                'Hindu' => ['Hindu', 'HINDU'],
                'Buddha' => ['Buddha', 'BUDDHA', 'Budha', 'BUDHA'],
                'Konghucu' => ['Konghucu', 'KONGHUCU', 'Khonghucu', 'KHONGHUCU'],
                'Lainnya' => ['Lainnya', 'LAINNYA', 'Kepercayaan', 'KEPERCAYAAN', 'Penghayat Kepercayaan'],
            ];

            $upperInput = mb_strtoupper($normalized);
            foreach ($relGroups as $targetCanonical => $groupAliases) {
                foreach ($groupAliases as $alias) {
                    if ($upperInput === mb_strtoupper($alias)) {
                        $targetId = $model::query()
                            ->whereRaw('UPPER(name) = ?', [mb_strtoupper($targetCanonical)])
                            ->value('id');
                        if ($targetId !== null) {
                            return (int) $targetId;
                        }

                        foreach ($groupAliases as $candidate) {
                            $candId = $model::query()
                                ->whereRaw('UPPER(name) = ?', [mb_strtoupper($candidate)])
                                ->value('id');
                            if ($candId !== null) {
                                return (int) $candId;
                            }
                        }
                        break;
                    }
                }
            }
        }

        return (int) $model::create(['name' => $this->titleCase($normalized)])->id;
    }

    /**
     * The OCR parser emits Y-m-d (e.g. 2016-01-28); an operator correction may
     * carry d/m/Y / d-m-Y. Normalize to the DATE column's Y-m-d.
     */
    private function normalizeBirthDate(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) === 1) {
            return sprintf('%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('~^(\d{1,2})[-/](\d{1,2})[-/](\d{2,4})$~', $value, $m) === 1) {
            $year = (int) $m[3];

            if ($year < 100) {
                $year += 2000;
            }

            return sprintf('%04d-%02d-%02d', $year, (int) $m[2], (int) $m[1]);
        }

        // Unreachable after the review gate validates the date.
        return $value;
    }

    private function titleCase(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Resolve the reviewed `rt` (e.g. "001") to an existing Rt by number.
     * Numbers are normalized (leading zeros) to match the seeded `rts` rows
     * ("01".."09"). When the same number exists under several area units the
     * first (by id) is chosen — the KK card carries no area unit, so the
     * area-unit is deliberately kept out of scope.
     */
    private function resolveRt(string $value): ?Rt
    {
        $value = trim($value);

        if ($value === '' || preg_match('/^\d{1,3}$/', $value) !== 1) {
            return null;
        }

        $number = str_pad((string) ((int) $value), 2, '0', STR_PAD_LEFT);

        return Rt::query()->where('number', $number)->orderBy('id')->first();
    }

    /**
     * Persist the Penduduk-import marker onto the OCR job's approved snapshot:
     * record the created families' ids and when / by whom the family was
     * imported. Runs inside the import transaction.
     *
     * Kept `protected` (not `final`) so a test subclass can make this step
     * throw, proving the Penduduk / KkAnggota inserts roll back when the job
     * update fails.
     *
     * @param  array<int, int>  $pendudukIds
     */
    protected function markJobImported(OcrJob $job, array $pendudukIds, ?User $operator): void
    {
        $data = is_array($job->extracted_data) ? $job->extracted_data : [];

        $data['penduduk_imported_at'] = now()->toDateTimeString();
        $data['penduduk_ids'] = $pendudukIds;
        $data['penduduk_operator_id'] = $operator?->id;

        $job->extracted_data = $data;
        $job->save();
    }

    private function log(string $outcome, OcrJob $job, ?string $kkNumber, float $startedAt, ?Throwable $error = null): void
    {
        try {
            Log::info('OCR Penduduk import '.$outcome, [
                'pipeline_stage' => 'import_penduduk',
                'outcome' => $outcome,
                'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
                'job_id' => $job->id,
                'kk_number' => $kkNumber,
                'error' => $error?->getMessage(),
            ]);
        } catch (Throwable) {
            // Logging must never break the import flow.
        }
    }

    /** @return array{success: bool, sheets?: array<int, string>, error?: string} */
    public function parseFile(string $path): array
    {
        try {
            $reader = ReaderFactory::createFromFile($path);
            $reader->open($path);
            $sheets = [];

            foreach ($reader->getSheetIterator() as $sheet) {
                $sheets[] = $sheet->getName();
            }

            $reader->close();

            return ['success' => true, 'sheets' => $sheets];
        } catch (Throwable $e) {
            if (isset($reader)) {
                try {
                    $reader->close();
                } catch (Throwable) {
                }
            }

            return ['success' => false, 'error' => 'File tidak dapat dibaca: '.$e->getMessage()];
        }
    }

    /** @return array{success: bool, sheet_name?: string, headers?: array<int, string>, rows?: array<int, array<string, mixed>>, total_rows?: int, error?: string} */
    public function parseSheet(string $path, string $sheetName): array
    {
        try {
            $reader = ReaderFactory::createFromFile($path);
            $reader->open($path);

            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheet->getName() !== $sheetName) {
                    continue;
                }

                $headers = null;
                $rows = [];

                foreach ($sheet->getRowIterator() as $row) {
                    $values = array_map(static fn ($value): mixed => $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value, $row->toArray());
                    if ($headers === null) {
                        $headers = array_map(static fn ($value): string => trim((string) $value), $values);
                        if ($headers === [] || count(array_filter($headers, static fn (string $header): bool => $header !== '')) === 0) {
                            $headers = null;
                        }

                        continue;
                    }

                    if ($row->isEmpty() || count(array_filter($values, static fn ($value): bool => trim((string) $value) !== '')) === 0) {
                        continue;
                    }

                    $values = array_pad($values, count($headers), null);
                    $rows[] = array_merge(
                        array_combine($headers, array_slice($values, 0, count($headers))) ?: [],
                        ['__row_number' => count($rows) + 2],
                    );
                }

                $reader->close();

                if ($headers === null) {
                    return ['success' => false, 'error' => 'Header tidak ditemukan pada sheet yang dipilih.'];
                }

                return [
                    'success' => true,
                    'sheet_name' => $sheetName,
                    'headers' => $headers,
                    'rows' => $rows,
                    'total_rows' => count($rows),
                ];
            }

            $reader->close();

            return ['success' => false, 'error' => 'Sheet yang dipilih tidak ditemukan.'];
        } catch (Throwable $e) {
            if (isset($reader)) {
                try {
                    $reader->close();
                } catch (Throwable) {
                }
            }

            return ['success' => false, 'error' => 'Sheet tidak dapat dibaca: '.$e->getMessage()];
        }
    }

    /**
     * Normalisasi kode numerik (NIK / No KK) dari format string, float, atau scientific notation.
     * Mempertahankan leading zero dan tidak mengubah angka menjadi integer atau float yang kehilangan presisi.
     */
    public function normalizeNumericCode(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }

        // Handle float representation e.g. "3207122801160001.0" or "3207122801160001,0"
        if (preg_match('/^(\d+)[.,]0+$/', $str, $m) === 1) {
            $str = $m[1];
        }

        // Handle scientific notation e.g. "3.207122801160001E+15"
        if (preg_match('/^(\d+)(?:\.(\d+))?[eE]\+(\d+)$/', $str, $m) === 1) {
            $intPart = $m[1];
            $fracPart = $m[2] ?? '';
            $exp = (int) $m[3];

            $combined = $intPart.$fracPart;
            if ($exp >= strlen($fracPart)) {
                $str = $combined.str_repeat('0', $exp - strlen($fracPart));
            } else {
                return null;
            }
        }

        // Strip separating non-digit characters like spaces, dashes, dots, quotes
        $digits = preg_replace('/[^\d]/', '', $str) ?? '';

        return $digits !== '' ? $digits : null;
    }

    /** @return array{mapping: array<string, string>, ambiguous: array<string, array<int, string>>, missing_required: array<int, string>, unrecognized: array<int, string>} */
    public function suggestMapping(array $headers): array
    {
        $aliases = $this->importHeaderAliases();
        $mapping = [];
        $ambiguous = [];

        foreach ($aliases as $field => $fieldAliases) {
            $matches = array_values(array_filter($headers, function (string $header) use ($fieldAliases): bool {
                return in_array($this->normalizeImportHeader($header), $fieldAliases, true);
            }));

            if (count($matches) > 1) {
                $ambiguous[$field] = $matches;
            }

            if ($matches !== []) {
                $mapping[$field] = $matches[0];
            }
        }

        $required = ['nik', 'full_name', 'kk_number', 'gender', 'birth_date', 'rt', 'rw', 'address'];
        $mappedHeaders = array_values($mapping);
        $unrecognized = array_values(array_filter($headers, static fn (string $h): bool => ! in_array($h, $mappedHeaders, true)));

        return [
            'mapping' => $mapping,
            'ambiguous' => $ambiguous,
            'missing_required' => array_values(array_diff($required, array_keys($mapping))),
            'unrecognized' => $unrecognized,
        ];
    }

    /** @return array{valid_count: int, duplicate_count: int, invalid_count: int, valid_rows: array<int, array<string, mixed>>, preview_rows: array<int, array<string, mixed>>, errors: array<int, array<int, string>>} */
    public function validateRows(array $rows, array $mapping, array $customMapping = []): array
    {
        $validRows = [];
        $previewRows = [];
        $errors = [];
        $seenNiks = [];
        $duplicateCount = 0;
        $invalidCount = 0;

        foreach ($rows as $index => $row) {
            $normalized = $this->normalizeImportRow($row, $mapping, $customMapping);
            $rowNumber = (int) ($row['__row_number'] ?? $index + 2);
            $rowErrors = [];

            // 1. NIK Validation
            $rawNik = $normalized['nik'] ?? null;
            $normalized['nik'] = $this->normalizeNumericCode($rawNik);
            if ($normalized['nik'] === null || ! preg_match('/^\d{16}$/', (string) $normalized['nik'])) {
                $rowErrors[] = 'NIK wajib terdiri dari 16 digit.';
            }

            // 2. Nama Lengkap Validation
            $normalized['full_name'] = trim((string) ($normalized['full_name'] ?? ''));
            if (blank($normalized['full_name'])) {
                $rowErrors[] = 'Nama wajib diisi.';
            }

            // 3. Nomor KK Validation
            $rawKk = $normalized['kk_number'] ?? null;
            $normalized['kk_number'] = $this->normalizeNumericCode($rawKk);
            if ($normalized['kk_number'] === null || ! preg_match('/^\d{16}$/', (string) $normalized['kk_number'])) {
                $rowErrors[] = 'Nomor KK wajib terdiri dari 16 digit.';
            }

            // 4. Gender Validation
            $normalized['gender'] = $this->normalizeGender($normalized['gender'] ?? null);
            if ($normalized['gender'] === null) {
                $rowErrors[] = 'Jenis kelamin tidak valid.';
            }

            // 5. Status Perkawinan
            $rawMaritalStatus = trim((string) ($normalized['marital_status'] ?? ''));
            $normalized['marital_status'] = $this->normalizeMaritalStatus($rawMaritalStatus);
            if ($rawMaritalStatus !== '' && $normalized['marital_status'] === null) {
                $rowErrors[] = 'Status perkawinan tidak valid.';
            }

            // 6. Hubungan Keluarga
            $rawFamilyRelation = trim((string) ($normalized['family_relation'] ?? ''));
            $normalized['family_relation'] = $this->normalizeFamilyRelation($rawFamilyRelation);
            if ($rawFamilyRelation !== '' && $normalized['family_relation'] === null) {
                $rowErrors[] = 'Hubungan keluarga tidak valid.';
            }

            // 7. Tanggal Lahir
            $normalized['birth_date'] = $this->normalizeBirthDateFromRow($normalized['birth_date'] ?? null);
            if ($normalized['birth_date'] === null) {
                $rowErrors[] = 'Tanggal lahir tidak valid.';
            }

            // 8. Status Penduduk & Tanggal Status
            $rawResidentStatus = trim((string) ($normalized['resident_status'] ?? ''));
            $normalized['resident_status'] = $this->normalizeResidentStatus($rawResidentStatus);
            $statusDate = $this->normalizeBirthDateFromRow($normalized['active_at'] ?? $normalized['moved_at'] ?? $normalized['deceased_at'] ?? null);
            $normalized['status_date'] = $statusDate;

            // 9. Alamat & Wilayah (RT, RW, Lingkungan)
            $normalized['address'] = trim(preg_replace('/\s+/', ' ', (string) ($normalized['address'] ?? '')) ?? '');
            foreach (['rt' => 'RT', 'rw' => 'RW', 'address' => 'Alamat'] as $field => $label) {
                if (blank($normalized[$field] ?? null)) {
                    $rowErrors[] = $label.' wajib diisi.';
                }
            }

            // 10. KK Check in Database
            $kk = filled($normalized['kk_number'] ?? null) ? KartuKeluarga::where('kk_number', $normalized['kk_number'])->first() : null;
            if ($kk === null) {
                $rowErrors[] = 'Nomor KK tidak ditemukan di database.';
            } elseif ($kk->rt_id === null) {
                $rowErrors[] = 'KK belum memiliki RT di database.';
            }

            // 11. Duplicate & Validity evaluation
            $existingResident = null;
            if ($normalized['nik'] !== null) {
                $existingResident = Penduduk::with('kartuKeluarga')->where('nik', $normalized['nik'])->first();
            }

            if ($rowErrors !== []) {
                $invalidCount++;
                $errors[$rowNumber] = $rowErrors;
                $normalized['status'] = 'INVALID';
            } elseif ($normalized['nik'] !== null && (in_array($normalized['nik'], $seenNiks, true) || $existingResident !== null)) {
                $duplicateCount++;
                $seenNiks[] = $normalized['nik'];
                $normalized['status'] = 'DUPLICATE';
                $normalized['existing_name'] = $existingResident?->full_name;
                $normalized['existing_kk'] = $existingResident?->kartuKeluarga?->kk_number;
            } else {
                if ($normalized['nik'] !== null) {
                    $seenNiks[] = $normalized['nik'];
                }
                $normalized['status'] = 'VALID';
                $validRows[] = $normalized;
            }

            if (count($previewRows) < 25) {
                $previewRows[] = [
                    'row_number' => $rowNumber,
                    'nik' => $normalized['nik'] ?? '',
                    'full_name' => $normalized['full_name'] ?? '',
                    'kk_number' => $normalized['kk_number'] ?? '',
                    'gender' => $normalized['gender'] ?? '',
                    'birth_date' => $normalized['birth_date'] ?? '',
                    'education' => $normalized['education'] ?? '',
                    'occupation' => $normalized['occupation'] ?? '',
                    'marital_status' => $normalized['marital_status'] ?? '',
                    'family_relation' => $normalized['family_relation'] ?? '',
                    'resident_status' => $normalized['resident_status'] ?? '',
                    'status_date' => $normalized['status_date'] ?? '',
                    'status' => $normalized['status'],
                    'existing_name' => $normalized['existing_name'] ?? null,
                    'existing_kk' => $normalized['existing_kk'] ?? null,
                ];
            }
        }

        return [
            'valid_count' => count($validRows),
            'duplicate_count' => $duplicateCount,
            'invalid_count' => $invalidCount,
            'valid_rows' => $validRows,
            'preview_rows' => $previewRows,
            'errors' => $errors,
        ];
    }

    /** @return array<string, array<int, string>> */
    private function importHeaderAliases(): array
    {
        $aliases = [
            'nik' => ['nik', 'no nik', 'nomor nik', 'no. nik', 'no_nik', 'nomor induk kependudukan', 'nik penduduk', 'no ktp', 'nomor ktp', 'no. ktp', 'no_ktp', 'nik/no. ktp', 'nik/ktp'],
            'full_name' => ['nama lengkap', 'nama', 'nama warga', 'nama penduduk', 'nama anggota', 'nama kepala keluarga', 'nama_lengkap', 'nama-lengkap', 'nama_penduduk', 'nama_warga'],
            'kk_number' => ['no kk', 'nomor kk', 'no. kk', 'no_kk', 'kartu keluarga', 'no kartu keluarga', 'nomor kartu keluarga', 'no. kartu keluarga', 'no_kartu_keluarga', 'kk', 'nomor kartu keluarga (kk)'],
            'gender' => ['jk', 'jenis kelamin', 'gender', 'j kelamin', 'j. kelamin', 'sex', 'jenis_kelamin'],
            'birth_date' => ['tgl lahir', 'tanggal lahir', 'tgl. lahir', 'tgl_lahir', 'tanggal_lahir', 'tanggal lahir penduduk', 'tgl lahir penduduk', 'birth date', 'tgl_lahir_penduduk'],
            'birth_place' => ['tempat lahir', 'tempat lahir penduduk', 'tmpt lahir', 'tmp lahir', 'tmp. lahir', 'tmp_lahir', 'birth place', 'tempat_lahir'],
            'religion' => ['agama', 'kepercayaan', 'agama/kepercayaan', 'agama kepercayaan', 'religion'],
            'education' => ['pendidikan', 'pendidikan terakhir', 'pndk', 'education', 'pendidikan_terakhir', 'pendidikan terakhir penduduk'],
            'occupation' => ['pekerjaan', 'profesi', 'mata pencaharian', 'pkrjn', 'jenis pekerjaan', 'occupation', 'jenis_pekerjaan'],
            'marital_status' => ['status perkawinan', 'status pernikahan', 'status kawin', 'status nikah', 'marital status', 'status_perkawinan', 'status_pernikahan'],
            'resident_status' => ['status penduduk', 'status kependudukan', 'status tinggal', 'status_penduduk', 'status'],
            'active_at' => ['tanggal aktif', 'tgl aktif', 'tgl. aktif', 'tgl_aktif', 'tanggal status', 'tgl status', 'tanggal_status', 'tanggal_aktif'],
            'moved_at' => ['tanggal pindah', 'tgl pindah', 'tgl. pindah', 'tgl_pindah', 'tanggal_pindah'],
            'deceased_at' => ['tanggal meninggal', 'tgl meninggal', 'tgl. meninggal', 'tgl_meninggal', 'tanggal_meninggal'],
            'family_relation' => ['status hubungan dalam keluarga', 'hubungan keluarga', 'hubungan dalam keluarga', 'shdk', 'status hubungan', 'status dalam kk', 'family relation', 'hubungan_keluarga', 'status_hubungan'],
            'rt' => ['rt', 'nomor rt', 'no rt', 'no. rt', 'no_rt'],
            'rw' => ['rw', 'nomor rw', 'no rw', 'no. rw', 'no_rw'],
            'lingkungan' => ['lingkungan', 'nama lingkungan', 'lingkungan/dusun', 'dusun', 'nama_lingkungan', 'lingkungan_rw'],
            'address' => ['alamat', 'alamat rumah', 'alamat tinggal', 'domisili', 'alamat domisili', 'alamat lengkap', 'alamat_rumah'],
            'notes' => ['catatan', 'keterangan', 'ket', 'notes', 'catatan_tambahan'],
        ];

        return array_map(fn (array $fieldAliases): array => array_map(fn (string $alias): string => $this->normalizeImportHeader($alias), $fieldAliases), $aliases);
    }

    private function normalizeImportHeader(string $header): string
    {
        $header = strtolower(trim($header));
        $header = preg_replace('/[^\pL\pN]+/u', ' ', $header) ?? $header;

        return trim(preg_replace('/\s+/', ' ', $header) ?? $header);
    }

    /** @return array<string, mixed> */
    private function normalizeImportRow(array $row, array $mapping, array $customMapping = []): array
    {
        $normalized = [];
        $fieldMapping = $mapping;

        foreach ($customMapping as $field => $header) {
            $fieldMapping[$field] = $header;
        }

        foreach ($fieldMapping as $field => $header) {
            if (is_string($field) && is_string($header)) {
                $normalized[$field] = $row[$header] ?? null;
            }
        }

        return $normalized;
    }

    /**
     * Import rows penduduk dari array data (bukan OCR job).
     * Excel/CSV hanya menjadi data source; persistensi tetap di service ini.
     *
     * @return array{status: string, imported: int, duplicates: int, invalids: int, message: string, details: array<int, mixed>}
     */
    public function importRows(array $rows, ?User $operator = null): array
    {
        $imported = 0;
        $duplicates = 0;
        $invalids = 0;

        DB::transaction(function () use ($rows, &$imported, &$duplicates, &$invalids): void {
            foreach ($rows as $row) {
                $result = $this->importRowFromArray($row);
                match ($result['status']) {
                    'imported' => $imported++,
                    'duplicate' => $duplicates++,
                    default => $invalids++,
                };
            }
        });

        return [
            'status' => 'completed',
            'imported' => $imported,
            'duplicates' => $duplicates,
            'invalids' => $invalids,
            'message' => sprintf('%d penduduk berhasil diimpor.', $imported),
            'details' => [],
        ];
    }

    /** Backward-compatible name used by the first controller draft. */
    public function importFromArray(
        array $rows,
        array $mapping,
        ?User $operator = null
    ): array {
        return $this->importRows($rows, $operator);
    }

    /**
     * Import satu row dari array data.
     */
    private function importRowFromArray(array $row): array
    {
        $nik = $this->normalizeNumericCode($row['nik'] ?? null);
        $fullName = trim((string) ($row['full_name'] ?? ''));
        $kkNumber = $this->normalizeNumericCode($row['kk_number'] ?? null);

        // Cek NIK duplicate
        if ($nik && Penduduk::where('nik', $nik)->exists()) {
            return ['status' => 'duplicate', 'nik' => $nik];
        }

        // Cek KK ada
        $kk = KartuKeluarga::where('kk_number', $kkNumber)->first();
        if (! $kk) {
            return ['status' => 'invalid', 'nik' => $nik, 'kk' => $kkNumber];
        }

        // Persiapan field
        $gender = isset($row['gender']) ? $this->normalizeGender($row['gender']) : null;
        if (filled($row['gender'] ?? null) && $gender === null) {
            throw new InvalidArgumentException('Jenis kelamin tidak valid.');
        }
        $birthDate = isset($row['birth_date']) ? $this->normalizeBirthDateFromRow($row['birth_date']) : null;
        if (filled($row['birth_date'] ?? null) && $birthDate === null) {
            throw new InvalidArgumentException('Tanggal lahir tidak valid.');
        }
        $birthPlace = filled($row['birth_place'] ?? null) ? trim((string) $row['birth_place']) : '-';
        $religionId = $this->resolveLookupId(Religion::class, (string) ($row['religion'] ?? 'Islam'));
        $educationId = $this->resolveLookupId(Education::class, (string) ($row['education'] ?? 'Tidak/Belum Sekolah'));
        $occupationId = $this->resolveLookupId(Occupation::class, (string) ($row['occupation'] ?? 'Lainnya'));
        $maritalStatus = $this->normalizeMaritalStatus($row['marital_status'] ?? null);
        if (filled($row['marital_status'] ?? null) && $maritalStatus === null) {
            throw new InvalidArgumentException('Status perkawinan tidak valid.');
        }
        $maritalStatus ??= MaritalStatus::BELUM_KAWIN->value;
        $familyRelation = $this->normalizeFamilyRelation($row['family_relation'] ?? null);
        if (filled($row['family_relation'] ?? null) && $familyRelation === null) {
            throw new InvalidArgumentException('Hubungan keluarga tidak valid.');
        }
        $familyRelation ??= FamilyRelation::LAINNYA->value;
        $residentStatus = isset($row['resident_status']) ? $this->normalizeResidentStatus($row['resident_status']) : ResidentStatus::ACTIVE->value;

        $activeAt = isset($row['active_at']) ? $this->normalizeBirthDateFromRow($row['active_at']) : null;
        $movedAt = isset($row['moved_at']) ? $this->normalizeBirthDateFromRow($row['moved_at']) : null;
        $deceasedAt = isset($row['deceased_at']) ? $this->normalizeBirthDateFromRow($row['deceased_at']) : null;

        if ($residentStatus === ResidentStatus::ACTIVE->value && $activeAt === null && isset($row['status_date'])) {
            $activeAt = $this->normalizeBirthDateFromRow($row['status_date']);
        } elseif ($residentStatus === ResidentStatus::PINDAH->value && $movedAt === null && isset($row['status_date'])) {
            $movedAt = $this->normalizeBirthDateFromRow($row['status_date']);
        } elseif ($residentStatus === ResidentStatus::MENINGGAL->value && $deceasedAt === null && isset($row['status_date'])) {
            $deceasedAt = $this->normalizeBirthDateFromRow($row['status_date']);
        }

        $rtId = $kk->rt_id;
        $penduduk = Penduduk::create([
            'kk_id' => $kk->id,
            'nik' => $nik,
            'full_name' => $fullName,
            'gender' => $gender,
            'birth_date' => $birthDate,
            'birth_place' => $birthPlace,
            'religion_id' => $religionId,
            'education_id' => $educationId,
            'occupation_id' => $occupationId,
            'marital_status' => $maritalStatus,
            'family_relation' => $familyRelation,
            'resident_status' => $residentStatus,
            'active_at' => $activeAt,
            'moved_at' => $movedAt,
            'deceased_at' => $deceasedAt,
            'blood_type' => BloodType::TIDAK_DIKETAHUI->value,
            'rt_id' => $rtId,
        ]);
        KkAnggota::create([
            'kk_id' => $kk->id,
            'penduduk_id' => $penduduk->id,
            'family_relation' => $familyRelation,
            'status' => KkAnggotaStatus::AKTIF->value,
            'effective_date' => now()->toDateString(),
        ]);

        return ['status' => 'imported', 'nik' => $nik];
    }

    /**
     * Normalisasi gender dari berbagai format ke enum.
     */
    private function normalizeGender($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $upper = strtoupper(trim((string) $value));

        return match ($upper) {
            'LAKI_LAKI', 'L', 'LAKI', 'M', 'MALE', 'PRIA', 'LAKI-LAKI', 'LAKI LAKI' => Gender::LAKI_LAKI->value,
            'PEREMPUAN', 'P', 'WANITA', 'W', 'FEMALE', 'PEWAR', 'CWE' => Gender::PEREMPUAN->value,
            default => null,
        };
    }

    /**
     * Normalisasi tanggal lahir / status dari format berbagai sumber ke Y-m-d.
     */
    public function normalizeBirthDateFromRow($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        $str = trim((string) $value);
        if ($str === '') {
            return null;
        }

        // Numeric Excel timestamp/serial date (e.g. 42000)
        if (is_numeric($str) && (float) $str >= 1000 && (float) $str <= 100000) {
            try {
                $days = (int) $str;
                $carbon = Carbon::createFromTimestampUTC(($days - 25569) * 86400);
                if ($carbon && $carbon->year >= 1900 && $carbon->year <= 2100) {
                    return $carbon->format('Y-m-d');
                }
            } catch (Throwable) {
            }
        }

        // YYYY-MM-DD or YYYY/MM/DD or YYYY.MM.DD
        if (preg_match('/^(\d{4})[-\/\.](\d{1,2})[-\/\.](\d{1,2})$/', $str, $m) === 1) {
            $year = (int) $m[1];
            $month = (int) $m[2];
            $day = (int) $m[3];
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            return null;
        }

        // DD-MM-YYYY or DD/MM/YYYY or DD.MM.YYYY
        if (preg_match('/^(\d{1,2})[-\/\.](\d{1,2})[-\/\.](\d{2,4})$/', $str, $m) === 1) {
            $day = (int) $m[1];
            $month = (int) $m[2];
            $year = (int) $m[3];
            if ($year < 100) {
                $year += ($year > 40 ? 1900 : 2000);
            }
            if (checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }

            return null;
        }

        // Fallback Carbon parse
        try {
            $date = Carbon::parse($str);
            if ($date && $date->year >= 1900 && $date->year <= 2100) {
                return $date->format('Y-m-d');
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Normalisasi status perkawinan.
     */
    private function normalizeMaritalStatus($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $upper = strtoupper(trim((string) $value));

        return match ($upper) {
            'KAWIN', 'K', 'MARRIED', 'MENIKAH', 'KAWIN TERCATAT', 'KAWIN BELUM TERCATAT' => 'KAWIN',
            'BELUM_KAWIN', 'BELUM KAWIN', 'BELUMKAWIN', 'BELUM KAWN', 'BELUMKAWN', 'BELUM MENIKAH', 'BELUM NIKAH', 'BLM KAWIN', 'B', 'SINGLE', 'LAJANG', 'BUJANG' => 'BELUM_KAWIN',
            'CERAI_HIDUP', 'CERAI HIDUP', 'CERAIHIDUP', 'CERAI', 'D', 'DIVORCED', 'DIVORCE', 'DUDA' => 'CERAI_HIDUP',
            'CERAI_MATI', 'CERAI MATI', 'CERAIMATI', 'M', 'WIDOWED', 'JANDA', 'RUKAN' => 'CERAI_MATI',
            default => null,
        };
    }

    /**
     * Normalisasi status kependudukan.
     */
    private function normalizeResidentStatus($value): string
    {
        if ($value === null || $value === '') {
            return ResidentStatus::ACTIVE->value;
        }
        $upper = strtoupper(trim((string) $value));

        return match ($upper) {
            'AKTIF', 'ACTIVE', 'A' => ResidentStatus::ACTIVE->value,
            'PINDAH', 'P', 'MIGRASI', 'MOVE' => ResidentStatus::PINDAH->value,
            'MENINGGAL', 'M', 'MATINGGAL', 'W', 'MUTUKA', 'DECEASED', 'DEATH', 'MATI' => ResidentStatus::MENINGGAL->value,
            default => ResidentStatus::ACTIVE->value,
        };
    }

    private function normalizeFamilyRelation($value): ?string
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
}
