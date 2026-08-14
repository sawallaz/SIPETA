<?php

namespace App\Services;

use App\Enums\KkAnggotaStatus;
use App\Enums\ResidentStatus;
use App\Models\Education;
use App\Models\KartuKeluarga;
use App\Models\KkAnggota;
use App\Models\Occupation;
use App\Models\Penduduk;
use App\Models\Religion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PendudukKkService
{
    /**
     * Simpan penduduk sekaligus menjaga sinkronisasi
     * Penduduk <-> KK <-> kk_anggota.
     *
     * Aturan utama:
     *
     * 1. NIK adalah identitas satu orang.
     * 2. NIK yang sudah ada TIDAK membuat Penduduk baru.
     * 3. Jika NIK existing pindah KK:
     *      - membership KK lama ditutup.
     *      - Penduduk.kk_id dipindahkan.
     *      - membership KK baru dibuat AKTIF.
     * 4. RT Penduduk tidak ditentukan di sini.
     *    Penduduk::booted() akan mengambil RT dari KK.
     *
     * @param  array<string, mixed>  $data
     */
    /**
     * Import anggota KK dari hasil review OCR.
     *
     * IMPORTANT:
     * - Tidak menghapus anggota lama yang tidak terbaca OCR.
     * - NIK adalah identitas utama.
     * - NIK existing akan menggunakan Penduduk yang sama.
     * - Membership KK lama akan ditutup jika pindah KK.
     * - Semua anggota diproses dalam SATU transaction.
     * - Jika satu anggota gagal, seluruh import rollback.
     *
     * @param  array<int, array<string, mixed>>  $members
     * @return array<int, Penduduk>
     */
    public function saveOcrMembers(
        KartuKeluarga $kk,
        array $members,
    ): array {
        return DB::transaction(function () use ($kk, $members): array {
            $kk = KartuKeluarga::query()
                ->lockForUpdate()
                ->find($kk->id);

            if ($kk === null) {
                throw ValidationException::withMessages([
                    'kk_id' => 'Kartu Keluarga tidak ditemukan.',
                ]);
            }

            if ($members === []) {
                throw ValidationException::withMessages([
                    'ocr' => 'Tidak ada anggota hasil OCR yang dapat disimpan.',
                ]);
            }

            /*
             * ============================================================
             * 1. VALIDASI SEMUA DATA TERLEBIH DAHULU
             * ============================================================
             *
             * Jangan melakukan CREATE/UPDATE sebelum seluruh anggota
             * lolos validasi.
             */
            $prepared = [];

            foreach (array_values($members) as $index => $member) {
                $row = $index + 1;

                $nik = $this->normalizeNik($member['nik'] ?? null);

                if ($nik === null || ! preg_match('/^\d{16}$/', $nik)) {
                    throw ValidationException::withMessages([
                        "ocr_members.{$index}.nik" => "Anggota ke-{$row}: NIK harus terdiri dari 16 digit.",
                    ]);
                }

                $fullName = trim((string) ($member['full_name'] ?? ''));

                if ($fullName === '') {
                    throw ValidationException::withMessages([
                        "ocr_members.{$index}.full_name" => "Anggota ke-{$row}: nama lengkap wajib tersedia.",
                    ]);
                }

                $gender = $this->normalizeEnumValue(
                    $member['gender'] ?? null,
                );

                if ($gender === null) {
                    throw ValidationException::withMessages([
                        "ocr_members.{$index}.gender" => "Anggota ke-{$row}: jenis kelamin belum terbaca.",
                    ]);
                }

                $birthPlace = trim(
                    (string) ($member['birth_place'] ?? ''),
                );

                if ($birthPlace === '') {
                    throw ValidationException::withMessages([
                        "ocr_members.{$index}.birth_place" => "Anggota ke-{$row}: tempat lahir belum terbaca.",
                    ]);
                }

                $birthDate = $member['birth_date'] ?? null;

                if (blank($birthDate)) {
                    throw ValidationException::withMessages([
                        "ocr_members.{$index}.birth_date" => "Anggota ke-{$row}: tanggal lahir belum terbaca.",
                    ]);
                }

                $religionRaw = $member['religion'] ?? null;

                if (blank($religionRaw)) {
                    throw ValidationException::withMessages([
                        "ocr_members.{$index}.religion" => "Anggota ke-{$row}: agama belum terbaca.",
                    ]);
                }

                $educationRaw = $member['education'] ?? null;

                if (blank($educationRaw)) {
                    throw ValidationException::withMessages([
                        "ocr_members.{$index}.education" => "Anggota ke-{$row}: pendidikan belum terbaca.",
                    ]);
                }

                $occupationRaw = $member['occupation'] ?? null;

                if (blank($occupationRaw)) {
                    throw ValidationException::withMessages([
                        "ocr_members.{$index}.occupation" => "Anggota ke-{$row}: pekerjaan belum terbaca.",
                    ]);
                }

                $marital = $this->normalizeEnumValue(
                    $member['marital_status'] ?? null,
                );

                if ($marital === null) {
                    throw ValidationException::withMessages([
                        "ocr_members.{$index}.marital_status" => "Anggota ke-{$row}: status perkawinan belum terbaca.",
                    ]);
                }

                $familyRelation = $this->normalizeEnumValue(
                    $member['family_relation'] ?? null,
                );

                if ($familyRelation === null) {
                    throw ValidationException::withMessages([
                        "ocr_members.{$index}.family_relation" => "Anggota ke-{$row}: hubungan keluarga belum terbaca.",
                    ]);
                }

                $prepared[] = [
                    'nik' => $nik,
                    'full_name' => $fullName,
                    'gender' => $gender,
                    'birth_place' => $birthPlace,
                    'birth_date' => $birthDate,
                    'religion_id' => $this->resolveReligionId($religionRaw),
                    'education_id' => $this->resolveEducationId($educationRaw),
                    'occupation_id' => $this->resolveOccupationId($occupationRaw),
                    'marital_status' => $marital,
                    'family_relation' => $familyRelation,
                    'resident_status' => ResidentStatus::ACTIVE,
                    'kk_id' => $kk->id,
                ];
            }

            /*
             * ============================================================
             * 2. CEK DUPLICATE NIK DALAM SATU APPROVAL
             * ============================================================
             */
            $niks = array_column($prepared, 'nik');

            $duplicates = collect($niks)
                ->countBy()
                ->filter(fn (int $count): bool => $count > 1)
                ->keys()
                ->values();

            if ($duplicates->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'ocr' => 'NIK duplikat dalam hasil OCR: '
                        .$duplicates->implode(', '),
                ]);
            }

            /*
             * ============================================================
             * 3. BARU SEKARANG WRITE KE DATABASE
             * ============================================================
             */
            $saved = [];

            foreach ($prepared as $data) {
                $existing = Penduduk::query()
                    ->lockForUpdate()
                    ->where('nik', $data['nik'])
                    ->first();

                /*
                 * Orang yang sudah MENINGGAL atau PINDAH tidak boleh
                 * diaktifkan kembali hanya karena muncul di OCR.
                 */
                if (
                    $existing !== null
                    && $existing->resident_status !== ResidentStatus::ACTIVE
                ) {
                    throw ValidationException::withMessages([
                        'ocr' => sprintf(
                            'NIK %s (%s) memiliki status %s dan tidak dapat '
                            .'diaktifkan kembali melalui import OCR.',
                            $data['nik'],
                            $existing->full_name,
                            $this->residentStatusLabel(
                                $existing->resident_status,
                            ),
                        ),
                    ]);
                }

                $saved[] = $this->save($data, $existing);
            }

            return $saved;
        });
    }

    /**
     * Cari ID agama berdasarkan nilai canonical dari OCR.
     */
    protected function resolveReligionId(mixed $value): int
    {
        $normalized = $this->normalizeLookupValue($value);

        $model = Religion::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->first();

        if ($model === null) {
            throw ValidationException::withMessages([
                'ocr' => "Agama '{$value}' tidak ditemukan pada master agama.",
            ]);
        }

        return (int) $model->id;
    }

    /**
     * Cari ID pendidikan berdasarkan label OCR.
     */
    protected function resolveEducationId(mixed $value): int
    {
        $normalized = $this->normalizeLookupValue($value);

        $model = Education::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->first();

        if ($model === null) {
            throw ValidationException::withMessages([
                'ocr' => "Pendidikan '{$value}' tidak ditemukan pada master pendidikan.",
            ]);
        }

        return (int) $model->id;
    }

    /**
     * Cari ID pekerjaan berdasarkan label OCR.
     */
    protected function resolveOccupationId(mixed $value): int
    {
        $normalized = $this->normalizeLookupValue($value);

        $model = Occupation::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->first();

        if ($model === null) {
            throw ValidationException::withMessages([
                'ocr' => "Pekerjaan '{$value}' tidak ditemukan pada master pekerjaan.",
            ]);
        }

        return (int) $model->id;
    }

    /**
     * Normalisasi enum/value hasil review OCR.
     *
     * Mengembalikan null jika nilai kosong, placeholder '-',
     * atau tidak dikenali (agar lolos ke validasi eksplisit
     * dan tidak meneruskan string liar ke enum cast).
     */
    protected function normalizeEnumValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        if ($value === '' || $value === '-' || $value === '--') {
            return null;
        }

        return match (mb_strtoupper($value)) {
            'LAKI-LAKI',
            'LAKI LAKI' => 'LAKI_LAKI',

            'PEREMPUAN' => 'PEREMPUAN',

            'BELUM KAWIN' => 'BELUM_KAWIN',
            'KAWIN' => 'KAWIN',
            'CERAI HIDUP' => 'CERAI_HIDUP',
            'CERAI MATI' => 'CERAI_MATI',

            'KEPALA KELUARGA' => 'KEPALA_KELUARGA',
            'ISTRI' => 'ISTRI',
            'ANAK' => 'ANAK',
            'MENANTU' => 'MENANTU',
            'CUCU' => 'CUCU',
            'ORANG TUA' => 'ORANG_TUA',
            'MERTUA' => 'MERTUA',
            'FAMILI LAIN' => 'FAMILI_LAIN',
            'LAINNYA' => 'LAINNYA',

            default => null,
        };
    }

    protected function normalizeLookupValue(mixed $value): string
    {
        return mb_strtolower(
            trim(
                preg_replace('/\s+/', ' ', (string) $value) ?? '',
            ),
        );
    }

    public function save(array $data, ?Penduduk $existing = null): Penduduk
    {
        return DB::transaction(function () use ($data, $existing): Penduduk {
            $kkId = $data['kk_id'] ?? null;
            $nik = $this->normalizeNik($data['nik'] ?? null);

            if (blank($kkId)) {
                throw ValidationException::withMessages([
                    'kk_id' => 'Kartu Keluarga wajib dipilih.',
                ]);
            }

            if (blank($nik)) {
                throw ValidationException::withMessages([
                    'nik' => 'NIK wajib diisi.',
                ]);
            }

            /** @var KartuKeluarga|null $kk */
            $kk = KartuKeluarga::query()
                ->lockForUpdate()
                ->find($kkId);

            if ($kk === null) {
                throw ValidationException::withMessages([
                    'kk_id' => 'Kartu Keluarga yang dipilih tidak ditemukan.',
                ]);
            }

            /*
             * Saat Edit, cari berdasarkan record yang sedang diedit.
             * Saat Create, cari berdasarkan NIK.
             */
            $penduduk = $existing;

            if ($penduduk === null) {
                $penduduk = Penduduk::query()
                    ->lockForUpdate()
                    ->where('nik', $nik)
                    ->first();
            } else {
                /*
                 * Saat Edit, NIK boleh diperbaiki, tetapi tidak boleh
                 * menabrak NIK milik orang lain.
                 *
                 * Tanpa penjagaan ini, unique index pada penduduk.nik
                 * akan melempar QueryException (HTTP 500), bukan pesan
                 * validasi yang bisa dibaca operator.
                 */
                $conflict = Penduduk::query()
                    ->where('nik', $nik)
                    ->where('id', '!=', $penduduk->getKey())
                    ->first();

                if ($conflict !== null) {
                    throw ValidationException::withMessages([
                        'nik' => sprintf(
                            'NIK %s sudah digunakan oleh penduduk lain (%s).',
                            $nik,
                            $conflict->full_name ?? 'tanpa nama',
                        ),
                    ]);
                }
            }

            /*
             * Jika tidak ada NIK tersebut:
             * buat Penduduk baru.
             */
            if ($penduduk === null) {
                $data['nik'] = $nik;
                $data['kk_id'] = $kk->id;

                /** @var Penduduk $penduduk */
                $penduduk = Penduduk::create($data);

                $this->ensureActiveMembership(
                    $penduduk,
                    $kk,
                    $data['family_relation'] ?? $penduduk->family_relation,
                );

                return $penduduk->fresh([
                    'kartuKeluarga',
                    'kkAnggotas',
                ]);
            }

            /*
             * Jangan izinkan satu orang yang sudah meninggal
             * atau berstatus PINDAH untuk dipasang kembali
             * sebagai anggota aktif melalui jalur normal.
             *
             * Untuk koreksi administratif khusus, operator
             * sebaiknya memperbaiki status terlebih dahulu.
             */
            if (
                $existing === null
                && $penduduk->resident_status !== ResidentStatus::ACTIVE
            ) {
                throw ValidationException::withMessages([
                    'nik' => sprintf(
                        'NIK %s sudah terdaftar sebagai penduduk dengan status %s. Data tersebut tidak boleh dibuat ulang.',
                        $nik,
                        $this->residentStatusLabel($penduduk->resident_status),
                    ),
                ]);
            }

            /*
             * Jika Edit record yang sama:
             * update data orang tersebut tanpa membuat
             * Penduduk baru.
             */
            $oldKkId = $penduduk->kk_id;

            $data['nik'] = $nik;
            $data['kk_id'] = $kk->id;

            /*
             * Jangan biarkan data dari form menentukan rt_id.
             * Penduduk::booted() akan sinkron dengan KK.
             */
            unset($data['rt_id']);

            $penduduk->fill($data);
            $penduduk->save();

            /*
             * Setelah save, booted() sudah memastikan rt_id
             * mengikuti KK.
             */
            $penduduk->refresh();

            /*
             * Jika KK tidak berubah:
             * cukup pastikan membership aktifnya benar.
             */
            if ((int) $oldKkId === (int) $kk->id) {
                $this->ensureActiveMembership(
                    $penduduk,
                    $kk,
                    $data['family_relation'] ?? $penduduk->family_relation,
                );

                return $penduduk->fresh([
                    'kartuKeluarga',
                    'kkAnggotas',
                ]);
            }

            /*
             * KK berubah.
             *
             * Tutup membership aktif dari KK lama.
             */
            $this->closeActiveMemberships(
                $penduduk,
                $kk->id,
            );

            /*
             * Buat / pastikan membership baru AKTIF.
             */
            $this->ensureActiveMembership(
                $penduduk,
                $kk,
                $data['family_relation'] ?? $penduduk->family_relation,
            );

            return $penduduk->fresh([
                'kartuKeluarga',
                'kkAnggotas',
            ]);
        });
    }

    /**
     * Tutup membership aktif yang bukan KK tujuan.
     */
    protected function closeActiveMemberships(
        Penduduk $penduduk,
        int $newKkId,
    ): void {
        KkAnggota::query()
            ->where('penduduk_id', $penduduk->id)
            ->where('status', KkAnggotaStatus::AKTIF)
            ->where('kk_id', '!=', $newKkId)
            ->update([
                'status' => KkAnggotaStatus::KELUAR,
                'end_date' => now()->toDateString(),
            ]);
    }

    /**
     * Pastikan hanya ada satu membership AKTIF
     * untuk penduduk pada KK tujuan.
     */
    protected function ensureActiveMembership(
        Penduduk $penduduk,
        KartuKeluarga $kk,
        mixed $familyRelation,
    ): KkAnggota {
        /*
         * Cari membership aktif untuk KK tujuan.
         */
        $membership = KkAnggota::query()
            ->where('kk_id', $kk->id)
            ->where('penduduk_id', $penduduk->id)
            ->where('status', KkAnggotaStatus::AKTIF)
            ->lockForUpdate()
            ->first();

        if ($membership !== null) {
            $membership->update([
                'family_relation' => $familyRelation,
                'end_date' => null,
            ]);

            /*
             * Kalau ternyata masih ada membership AKTIF
             * lain untuk orang yang sama, tutup semuanya.
             */
            KkAnggota::query()
                ->where('penduduk_id', $penduduk->id)
                ->where('status', KkAnggotaStatus::AKTIF)
                ->where('id', '!=', $membership->id)
                ->update([
                    'status' => KkAnggotaStatus::KELUAR,
                    'end_date' => now()->toDateString(),
                ]);

            return $membership->fresh();
        }

        /*
         * Jangan sampai ada membership aktif lain.
         */
        $this->closeActiveMemberships(
            $penduduk,
            $kk->id,
        );

        return KkAnggota::create([
            'kk_id' => $kk->id,
            'penduduk_id' => $penduduk->id,
            'family_relation' => $familyRelation,
            'status' => KkAnggotaStatus::AKTIF,
            'effective_date' => now()->toDateString(),
            'end_date' => null,
        ]);
    }

    /**
     * Normalisasi NIK menjadi 16 digit.
     */
    protected function normalizeNik(mixed $nik): ?string
    {
        if ($nik === null) {
            return null;
        }

        $nik = preg_replace('/\D/', '', (string) $nik);

        return $nik !== '' ? $nik : null;
    }

    protected function residentStatusLabel(
        ?ResidentStatus $status,
    ): string {
        return match ($status) {
            ResidentStatus::ACTIVE => 'Aktif',
            ResidentStatus::PINDAH => 'Pindah',
            ResidentStatus::MENINGGAL => 'Meninggal',
            default => 'Tidak diketahui',
        };
    }
}
