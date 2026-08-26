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
        'active_at',
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
        'active_at' => 'date',
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
     * Riwayat status kependudukan (Aktif / Pindah / Meninggal).
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(
            PendudukStatusHistory::class,
            'penduduk_id'
        );
    }

    /**
     * Label tanggal status berdasarkan status kependudukan saat ini.
     *
     * Aktif     → Tanggal Aktif
     * Pindah    → Tanggal Pindah
     * Meninggal → Tanggal Meninggal
     */
    public function getStatusDateLabelAttribute(): string
    {
        $status = $this->resident_status instanceof ResidentStatus
            ? $this->resident_status
            : ResidentStatus::tryFrom((string) $this->resident_status);

        return match ($status) {
            ResidentStatus::ACTIVE => 'Tanggal Aktif',
            ResidentStatus::PINDAH => 'Tanggal Pindah',
            ResidentStatus::MENINGGAL => 'Tanggal Meninggal',
            default => 'Tanggal Status',
        };
    }

    /**
     * Tanggal status yang berlaku saat ini.
     */
    public function getStatusDateAttribute(): ?Carbon
    {
        $status = $this->resident_status instanceof ResidentStatus
            ? $this->resident_status
            : ResidentStatus::tryFrom((string) $this->resident_status);

        if ($status === null) {
            return null;
        }

        if ($status === ResidentStatus::ACTIVE && $this->active_at) {
            return Carbon::parse($this->active_at);
        }

        if ($status === ResidentStatus::PINDAH && $this->moved_at) {
            return Carbon::parse($this->moved_at);
        }

        if ($status === ResidentStatus::MENINGGAL && $this->deceased_at) {
            return Carbon::parse($this->deceased_at);
        }

        $latestHistory = $this->relationLoaded('statusHistories')
            ? $this->statusHistories->where('status', $status)->sortByDesc('id')->first()
            : $this->statusHistories()->where('status', $status->value)->latest('recorded_at')->latest('id')->first();

        if ($latestHistory?->recorded_at) {
            return Carbon::parse($latestHistory->recorded_at);
        }

        return $this->created_at ? Carbon::parse($this->created_at) : now();
    }

    /**
     * Format tanggal status dalam bahasa Indonesia (e.g. 21 Agustus 2026).
     */
    public function getFormattedStatusDateAttribute(): ?string
    {
        $date = $this->status_date;

        if ($date === null) {
            return null;
        }

        return Carbon::parse($date)->locale('id')->translatedFormat('d F Y');
    }

    /**
     * Sinkronisasi RT penduduk dengan KK dan riwayat status kependudukan.
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
     * 5. Tanggal status disimpan berdasarkan input manual operator (event date),
     *    bukan otomatis now(), dan riwayat tersimpan lengkap.
     */
    protected static function booted(): void
    {
        static::saving(function (Penduduk $penduduk): void {
            if ($penduduk->kk_id !== null) {
                $kk = KartuKeluarga::query()
                    ->with('rt')
                    ->find($penduduk->kk_id);

                if ($kk !== null) {
                    if ($kk->rt_id !== null) {
                        $penduduk->rt_id = $kk->rt_id;
                    } else {
                        $penduduk->rt_id = null;
                    }
                }
            }

            $status = $penduduk->resident_status instanceof ResidentStatus
                ? $penduduk->resident_status
                : ResidentStatus::tryFrom((string) $penduduk->resident_status);

            if ($status === ResidentStatus::ACTIVE && blank($penduduk->active_at) && ! $penduduk->exists) {
                $penduduk->active_at = now()->toDateString();
            }
        });

        static::created(function (Penduduk $penduduk): void {
            $status = $penduduk->resident_status instanceof ResidentStatus
                ? $penduduk->resident_status
                : ResidentStatus::tryFrom((string) $penduduk->resident_status);

            $status = $status ?? ResidentStatus::ACTIVE;

            $recordedAt = match ($status) {
                ResidentStatus::PINDAH => $penduduk->moved_at ? Carbon::parse($penduduk->moved_at)->toDateString() : now()->toDateString(),
                ResidentStatus::MENINGGAL => $penduduk->deceased_at ? Carbon::parse($penduduk->deceased_at)->toDateString() : now()->toDateString(),
                default => $penduduk->active_at ? Carbon::parse($penduduk->active_at)->toDateString() : now()->toDateString(),
            };

            $penduduk->statusHistories()->create([
                'status' => $status,
                'recorded_at' => $recordedAt,
                'user_id' => auth()->id(),
                'notes' => $status === ResidentStatus::PINDAH
                    ? $penduduk->moved_note
                    : ($status === ResidentStatus::MENINGGAL ? $penduduk->deceased_note : null),
            ]);
        });

        static::updated(function (Penduduk $penduduk): void {
            if ($penduduk->wasChanged('resident_status')) {
                $status = $penduduk->resident_status instanceof ResidentStatus
                    ? $penduduk->resident_status
                    : ResidentStatus::tryFrom((string) $penduduk->resident_status);

                if ($status !== null) {
                    $recordedAt = match ($status) {
                        ResidentStatus::PINDAH => $penduduk->moved_at ? Carbon::parse($penduduk->moved_at)->toDateString() : now()->toDateString(),
                        ResidentStatus::MENINGGAL => $penduduk->deceased_at ? Carbon::parse($penduduk->deceased_at)->toDateString() : now()->toDateString(),
                        default => $penduduk->active_at ? Carbon::parse($penduduk->active_at)->toDateString() : now()->toDateString(),
                    };

                    $penduduk->statusHistories()->create([
                        'status' => $status,
                        'recorded_at' => $recordedAt,
                        'user_id' => auth()->id(),
                        'notes' => $status === ResidentStatus::PINDAH
                            ? $penduduk->moved_note
                            : ($status === ResidentStatus::MENINGGAL ? $penduduk->deceased_note : null),
                    ]);
                }
            } elseif ($penduduk->wasChanged(['active_at', 'moved_at', 'deceased_at'])) {
                $status = $penduduk->resident_status instanceof ResidentStatus
                    ? $penduduk->resident_status
                    : ResidentStatus::tryFrom((string) $penduduk->resident_status);

                $latestHistory = $penduduk->statusHistories()->where('status', $status?->value)->latest('id')->first();
                if ($latestHistory !== null) {
                    $recordedAt = match ($status) {
                        ResidentStatus::PINDAH => $penduduk->moved_at ? Carbon::parse($penduduk->moved_at)->toDateString() : null,
                        ResidentStatus::MENINGGAL => $penduduk->deceased_at ? Carbon::parse($penduduk->deceased_at)->toDateString() : null,
                        default => $penduduk->active_at ? Carbon::parse($penduduk->active_at)->toDateString() : null,
                    };

                    if ($recordedAt !== null) {
                        $latestHistory->update(['recorded_at' => $recordedAt]);
                    }
                }
            }
        });

        /*
         * Bersihkan relasi anak sebelum Penduduk dihapus:
         * 1. Dokumen pendukung (file + record)
         * 2. Riwayat keanggotaan KK (kk_anggota)
         * 3. Riwayat status kependudukan (penduduk_status_histories)
         */
        static::deleting(function (Penduduk $penduduk): void {
            app(PendudukDocumentService::class)
                ->deleteForPenduduk($penduduk);

            $penduduk->kkAnggotas()->delete();
            $penduduk->statusHistories()->delete();
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
            ?->kepalaKeluarga
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
