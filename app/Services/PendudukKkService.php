<?php

namespace App\Services;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
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

                $existing = Penduduk::query()
                    ->where('nik', $nik)
                    ->first();

                $fullName = trim((string) ($member['full_name'] ?? ''));
                if ($fullName === '' && $existing !== null) {
                    $fullName = $existing->full_name;
                }

                if ($fullName === '') {
                    throw ValidationException::withMessages([
                        "ocr_members.{$index}.full_name" => "Anggota ke-{$row}: nama lengkap wajib tersedia.",
                    ]);
                }

                $gender = $this->normalizeEnumValue(
                    $member['gender'] ?? null,
                );
                if ($gender === null && $existing !== null) {
                    $gender = $existing->gender?->value ?? (string) $existing->gender;
                }
                $gender ??= Gender::LAKI_LAKI->value;

                $birthPlace = trim(
                    (string) ($member['birth_place'] ?? ''),
                );
                if ($birthPlace === '' && $existing !== null) {
                    $birthPlace = (string) $existing->birth_place;
                }
                if ($birthPlace === '') {
                    $birthPlace = '-';
                }

                $birthDate = $member['birth_date'] ?? null;
                if (blank($birthDate) && $existing !== null) {
                    $birthDate = $existing->birth_date?->format('Y-m-d');
                }
                if (blank($birthDate)) {
                    $birthDate = '2000-01-01';
                }

                $religionRaw = $member['religion'] ?? null;
                $religionId = filled($religionRaw)
                    ? $this->resolveReligionId($religionRaw)
                    : $existing?->religion_id;
                $religionId ??= Religion::query()->orderBy('id')->value('id') ?: 1;

                $educationRaw = $member['education'] ?? null;
                $educationId = filled($educationRaw)
                    ? $this->resolveEducationId($educationRaw)
                    : $existing?->education_id;
                $educationId ??= Education::query()->orderBy('id')->value('id') ?: 1;

                $occupationRaw = $member['occupation'] ?? null;
                $occupationId = filled($occupationRaw)
                    ? $this->resolveOccupationId($occupationRaw)
                    : $existing?->occupation_id;
                $occupationId ??= Occupation::query()->orderBy('id')->value('id') ?: 1;

                $marital = $this->normalizeEnumValue(
                    $member['marital_status'] ?? null,
                );
                if ($marital === null && $existing !== null) {
                    $marital = $existing->marital_status?->value ?? (string) $existing->marital_status;
                }
                $marital ??= MaritalStatus::BELUM_KAWIN->value;

                $familyRelation = $this->normalizeEnumValue(
                    $member['family_relation'] ?? null,
                );
                if ($familyRelation === null && $existing !== null) {
                    $familyRelation = $existing->family_relation?->value ?? (string) $existing->family_relation;
                }
                $familyRelation ??= ($index === 0 ? FamilyRelation::KEPALA_KELUARGA->value : FamilyRelation::ANAK->value);

                $bloodType = $member['blood_type'] ?? $existing?->blood_type?->value ?? BloodType::TIDAK_DIKETAHUI->value;

                $prepared[] = [
                    'nik' => $nik,
                    'full_name' => $fullName,
                    'gender' => $gender,
                    'birth_place' => $birthPlace,
                    'birth_date' => $birthDate,
                    'religion_id' => $religionId,
                    'education_id' => $educationId,
                    'occupation_id' => $occupationId,
                    'blood_type' => $bloodType,
                    'marital_status' => $marital,
                    'family_relation' => $familyRelation,
                    'resident_status' => ResidentStatus::ACTIVE,
                    'kk_id' => $kk->id,
                ];
            }

            /*
             * ============================================================
             * 2. CEK DUPLICATE NIK & KEPALA KELUARGA DALAM SATU APPROVAL
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

            $kepalaCount = collect($prepared)
                ->filter(fn (array $m): bool => ($m['family_relation'] ?? null) === FamilyRelation::KEPALA_KELUARGA->value)
                ->count();

            if ($kepalaCount > 1) {
                throw ValidationException::withMessages([
                    'ocr' => 'Dalam satu Kartu Keluarga hanya boleh terdapat 1 Kepala Keluarga (ditemukan '.$kepalaCount.' anggota).',
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
     * Cari ID agama berdasarkan nilai canonical/alias dari OCR.
     */
    protected function resolveReligionId(mixed $value): int
    {
        $normalized = $this->normalizeLookupValue($value);

        $model = Religion::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->first();

        if ($model !== null) {
            return (int) $model->id;
        }

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
                    $targetId = Religion::query()
                        ->whereRaw('UPPER(name) = ?', [mb_strtoupper($targetCanonical)])
                        ->value('id');
                    if ($targetId !== null) {
                        return (int) $targetId;
                    }
                    foreach ($groupAliases as $candidate) {
                        $candId = Religion::query()
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

        $created = Religion::firstOrCreate(['name' => ucwords(strtolower((string) $value))]);

        return (int) $created->id;
    }

    /**
     * Cari ID pendidikan berdasarkan label OCR / alias.
     */
    protected function resolveEducationId(mixed $value): int
    {
        $normalized = $this->normalizeLookupValue($value);

        $model = Education::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->first();

        if ($model !== null) {
            return (int) $model->id;
        }

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
                    $targetId = Education::query()
                        ->whereRaw('UPPER(name) = ?', [mb_strtoupper($targetCanonical)])
                        ->value('id');
                    if ($targetId !== null) {
                        return (int) $targetId;
                    }

                    foreach ($groupAliases as $candidate) {
                        $candId = Education::query()
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

        $created = Education::firstOrCreate(['name' => trim((string) $value)]);

        return (int) $created->id;
    }

    /**
     * Cari ID pekerjaan berdasarkan label OCR / alias.
     */
    protected function resolveOccupationId(mixed $value): int
    {
        $normalized = $this->normalizeLookupValue($value);

        $model = Occupation::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalized])
            ->first();

        if ($model !== null) {
            return (int) $model->id;
        }

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
                    $targetId = Occupation::query()
                        ->whereRaw('UPPER(name) = ?', [mb_strtoupper($targetCanonical)])
                        ->value('id');
                    if ($targetId !== null) {
                        return (int) $targetId;
                    }

                    foreach ($groupAliases as $candidate) {
                        $candId = Occupation::query()
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

        $created = Occupation::firstOrCreate(['name' => ucwords(strtolower((string) $value))]);

        return (int) $created->id;
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

        $upper = mb_strtoupper($value);

        return match ($upper) {
            'LAKI-LAKI',
            'LAKI LAKI',
            'LAKI_LAKI',
            'L' => 'LAKI_LAKI',

            'PEREMPUAN',
            'P' => 'PEREMPUAN',

            'BELUM KAWIN',
            'BELUM_KAWIN',
            'BELUMKAWIN',
            'BELUM KAWN',
            'BELUMKAWN',
            'BELUM NIKAH',
            'SINGLE' => 'BELUM_KAWIN',

            'KAWIN',
            'KAW1N',
            'NIKAH',
            'MARRIED',
            'KAWIN TERCATAT' => 'KAWIN',

            'CERAI HIDUP',
            'CERAI_HIDUP',
            'CERAIHIDUP',
            'CERAI',
            'DUDA' => 'CERAI_HIDUP',

            'CERAI MATI',
            'CERAI_MATI',
            'CERAIMATI',
            'JANDA' => 'CERAI_MATI',

            'KEPALA KELUARGA',
            'KEPALA_KELUARGA',
            'KEPALA KEL.',
            'KEPALA KEL',
            'KEPALAKELUARGA',
            'KEPALAKEUARGA',
            'KEPALAKEL',
            'KEPALA' => 'KEPALA_KELUARGA',

            'ISTRI',
            'ISTERI',
            '1STRI',
            'ISTRI KEPALA KELUARGA' => 'ISTRI',

            'ANAK',
            'ANAK2',
            'ANAK-',
            'AN4K',
            'ANAK KANDUNG',
            'ANAK ANGKAT',
            'ANAK TIRI' => 'ANAK',

            'MENANTU' => 'MENANTU',
            'CUCU' => 'CUCU',

            'ORANG TUA',
            'ORANG_TUA',
            'ORANGTUA',
            'AYAH',
            'IBU',
            'BAPAK' => 'ORANG_TUA',

            'MERTUA' => 'MERTUA',

            'FAMILI LAIN',
            'FAMILI_LAIN',
            'FAMILI LAINNYA',
            'FAMILI',
            'FAMILILAIN' => 'FAMILI_LAIN',

            'PEMBANTU',
            'LAINNYA',
            'LAIN' => 'LAINNYA',

            default => FamilyRelation::tryFrom($upper)?->value
                ?? MaritalStatus::tryFrom($upper)?->value
                ?? Gender::tryFrom($upper)?->value
                ?? null,
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
             * Saat Edit ($existing !== null):
             * NIK boleh tetap sama, tetapi tidak boleh menabrak NIK milik record lain.
             *
             * Saat Create ($existing === null):
             * NIK tidak boleh sudah terdaftar di database.
             */
            if ($existing === null) {
                $conflict = Penduduk::query()
                    ->where('nik', $nik)
                    ->first();

                if ($conflict !== null) {
                    throw ValidationException::withMessages([
                        'nik' => sprintf(
                            'Penduduk dengan NIK %s sudah terdaftar (%s).',
                            $nik,
                            $conflict->full_name ?? 'tanpa nama',
                        ),
                    ]);
                }

                $data['nik'] = $nik;
                $data['kk_id'] = $kk->id;
                $data['blood_type'] ??= BloodType::TIDAK_DIKETAHUI->value;

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

            $penduduk = $existing;

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
