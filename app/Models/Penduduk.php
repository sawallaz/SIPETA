<?php

namespace App\Models;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use App\Services\PendudukDocumentService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Penduduk extends Model
{
    use HasFactory;

    protected $table = 'penduduk';

    /**
     * Semua data yang memang disimpan pada tabel penduduk.
     *
     * Catatan:
     * - kk_id = KK aktif tempat penduduk berada.
     * - rt_id tetap dipertahankan untuk kompatibilitas database lama.
     * - rt_id tidak menjadi sumber utama wilayah.
     * - sumber wilayah utama tetap KartuKeluarga -> RT -> AreaUnit.
     */
    protected $fillable = [
        'kk_id',
        'nik',
        'full_name',
        'gender',
        'birth_place',
        'birth_date',
        'religion_id',
        'education_id',
        'occupation_id',
        'marital_status',
        'family_relation',
        'blood_type',
        'resident_status',
        'rt_id',
        'moved_at',
        'moved_destination',
        'moved_note',
        'deceased_at',
        'deceased_note',
        'notes',
    ];

    protected $casts = [
        'nik' => 'string',
        'gender' => Gender::class,
        'marital_status' => MaritalStatus::class,
        'family_relation' => FamilyRelation::class,
        'blood_type' => BloodType::class,
        'resident_status' => ResidentStatus::class,
        'birth_date' => 'date',
        'moved_at' => 'date',
        'deceased_at' => 'date',
    ];

    /**
     * NIK adalah identitas string 16 digit.
     *
     * Jangan pernah diperlakukan sebagai integer karena
     * angka 16 digit dapat mengalami perubahan format.
     */
    public function setNikAttribute($value): void
    {
        $this->attributes['nik'] = preg_replace(
            '/\D/',
            '',
            (string) $value
        );
    }

    /**
     * KK aktif penduduk.
     *
     * Inilah relasi utama:
     *
     * Penduduk
     *    ↓
     * Kartu Keluarga
     */
    public function kartuKeluarga(): BelongsTo
    {
        return $this->belongsTo(
            KartuKeluarga::class,
            'kk_id'
        );
    }

    /**
     * Alias pendek untuk kebutuhan kode tertentu.
     */
    public function kk(): BelongsTo
    {
        return $this->kartuKeluarga();
    }

    /**
     * Agama.
     */
    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class);
    }

    /**
     * Pendidikan.
     */
    public function education(): BelongsTo
    {
        return $this->belongsTo(Education::class);
    }

    /**
     * Pekerjaan.
     */
    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    /**
     * RT yang tersimpan pada record penduduk.
     *
     * IMPORTANT:
     * Ini bukan sumber utama wilayah.
     *
     * Sumber utama tetap:
     *
     * penduduk
     *    ↓
     * kartu_keluarga
     *    ↓
     * rt
     *    ↓
     * area_unit
     */
    public function rt(): BelongsTo
    {
        return $this->belongsTo(
            Rt::class,
            'rt_id'
        );
    }

    /**
     * Riwayat keanggotaan KK.
     *
     * kk_anggota digunakan sebagai histori,
     * bukan sebagai sumber utama anggota KK saat ini.
     */
    public function kkAnggotas(): HasMany
    {
        return $this->hasMany(
            KkAnggota::class,
            'penduduk_id'
        );
    }

    /**
     * Dokumen pendukung penduduk (KTP, Akta Kelahiran).
     *
     * Semua dokumen bersifat opsional.
     * Dokumen lama diarsipkan (is_active=false) saat diganti,
     * mengikuti filosofi arsip foto KK.
     */
    public function documents(): HasMany
    {
        return $this->hasMany(
            PendudukDocument::class,
            'penduduk_id'
        );
    }

    /**
     * Audit log penduduk.
     */
    public function audits(): MorphMany
    {
        return $this->morphMany(
            AuditLog::class,
            'loggable'
        );
    }

    /**
     * Wilayah penduduk.
     *
     * Wilayah selalu mengikuti KK aktif.
     *
     * Contoh:
     *
     * Penduduk
     *   NIK: 7371...
     *       ↓
     * KK 7371...
     *       ↓
     * RT 02
     *       ↓
     * Lingkungan I
     *
     * Jadi tidak boleh ada wilayah penduduk
     * yang berbeda dari KK-nya.
     */
    public function getAreaUnitAttribute(): ?AreaUnit
    {
        return $this->kartuKeluarga?->rt?->areaUnit;
    }

    /**
     * RT aktual penduduk.
     *
     * Kita sengaja mengambil dari KK terlebih dahulu.
     *
     * Ini membuat tampilan aplikasi tetap benar
     * walaupun data rt_id lama pada penduduk belum
     * sepenuhnya bersih.
     */
    public function getCurrentRtAttribute(): ?Rt
    {
        return $this->kartuKeluarga?->rt;
    }

    /**
     * Label wilayah penduduk.
     *
     * Contoh:
     * "Lingkungan I / RT 02"
     */
    public function getRtRwLabelAttribute(): ?string
    {
        return $this->kartuKeluarga?->rt_rw_label;
    }

    /**
     * Sinkronisasi RT penduduk dengan KK.
     *
     * Aturan:
     *
     * 1. Penduduk harus memiliki KK.
     * 2. Jika KK mempunyai RT, rt_id penduduk
     *    otomatis mengikuti RT KK.
     * 3. Jika penduduk dipindahkan ke KK lain,
     *    rt_id otomatis ikut KK baru.
     * 4. Operator tidak perlu menentukan RT
     *    secara manual pada form Penduduk.
     */
    protected static function booted(): void
    {
        static::saving(function (Penduduk $penduduk): void {
            if ($penduduk->kk_id === null) {
                return;
            }

            $kk = KartuKeluarga::query()
                ->with('rt')
                ->find($penduduk->kk_id);

            if ($kk === null) {
                return;
            }

            /**
             * KK adalah sumber wilayah.
             *
             * Jangan gunakan rt_id yang dikirim dari
             * form sebagai sumber kebenaran.
             */
            if ($kk->rt_id !== null) {
                $penduduk->rt_id = $kk->rt_id;
            } else {
                /**
                 * Jika KK belum mempunyai RT,
                 * jangan memaksakan RT lama penduduk.
                 */
                $penduduk->rt_id = null;
            }
        });

        /*
         * Bersihkan dokumen pendukung (file + record)
         * sebelum Penduduk dihapus.
         *
         * penduduk_documents.penduduk_id memakai
         * onDelete('RESTRICT'), sehingga baris anak
         * harus dihapus lebih dulu agar FK tidak melanggar.
         *
         * Catatan: kk_anggota.penduduk_id juga RESTRICT
         * (di luar cakupan fitur ini).
         */
        static::deleting(function (Penduduk $penduduk): void {
            app(PendudukDocumentService::class)
                ->deleteForPenduduk($penduduk);
        });
    }

    /**
     * Umur dihitung otomatis.
     *
     * Tidak disimpan di database.
     */
    public function getAgeAttribute(): int
    {
        if ($this->birth_date === null) {
            return 0;
        }

        return Carbon::parse(
            $this->birth_date
        )->age;
    }

    /**
     * Penduduk aktif.
     */
    public function scopeActive(
        Builder $query
    ): Builder {
        return $query->where(
            'resident_status',
            ResidentStatus::ACTIVE->value
        );
    }

    /**
     * Filter berdasarkan rentang umur.
     *
     * Umur tidak pernah disimpan.
     * Query menggunakan birth_date.
     */
    public function scopeAgeRange(
        Builder $query,
        ?int $ageMin,
        ?int $ageMax
    ): Builder {
        if ($ageMin !== null) {
            $query->where(
                'birth_date',
                '<=',
                now()
                    ->subYears($ageMin)
                    ->endOfDay()
            );
        }

        if ($ageMax !== null) {
            $query->where(
                'birth_date',
                '>',
                now()
                    ->subYears($ageMax + 1)
                    ->endOfDay()
            );
        }

        return $query;
    }

    /**
     * Apakah penduduk ini kepala keluarga?
     */
    public function isKepalaKeluarga(): bool
    {
        return $this->family_relation
            === FamilyRelation::KEPALA_KELUARGA;
    }

    /**
     * Apakah penduduk masih aktif?
     */
    public function isActive(): bool
    {
        return $this->resident_status
            === ResidentStatus::ACTIVE;
    }

    /**
     * Nomor KK aktif penduduk.
     */
    public function getKkNumberAttribute(): ?string
    {
        return $this->kartuKeluarga?->kk_number;
    }

    /**
     * Nama kepala keluarga dari KK penduduk.
     *
     * Tidak ada kolom kepala_keluarga pada tabel penduduk.
     * Kepala keluarga adalah penduduk dengan
     * family_relation = KEPALA_KELUARGA.
     */
    public function getKepalaKeluargaAttribute(): ?string
    {
        return $this->kartuKeluarga
            ?->kepalaKeluarga()
            ?->full_name;
    }

    /**
     * Alamat mengikuti KK.
     */
    public function getKkAddressAttribute(): ?string
    {
        return $this->kartuKeluarga?->address;
    }

    /**
     * Kode pos mengikuti KK.
     */
    public function getKkPostalCodeAttribute(): ?string
    {
        return $this->kartuKeluarga?->postal_code;
    }
}
