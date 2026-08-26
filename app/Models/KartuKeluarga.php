<?php

namespace App\Models;

use App\Enums\FamilyRelation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class KartuKeluarga extends Model
{
    use HasFactory;

    protected $table = 'kartu_keluarga';

    /**
     * Data yang memang dimiliki oleh Kartu Keluarga.
     *
     * PENTING:
     *
     * Nama kepala keluarga TIDAK disimpan di sini.
     * Nama kepala keluarga berasal dari Penduduk:
     *
     * penduduk.kk_id
     * +
     * penduduk.family_relation = KEPALA_KELUARGA
     *
     * Wilayah juga dimiliki oleh KK:
     *
     * kartu_keluarga.rt_id
     *      ↓
     * rts.area_unit_id
     *      ↓
     * area_units
     */
    protected $fillable = [
        'kk_number',
        'address',
        'rt_id',
        'postal_code',
        'notes',
    ];

    protected $casts = [
        'kk_number' => 'string',
    ];

    /**
     * Nomor KK selalu dianggap sebagai STRING.
     *
     * Nomor KK terdiri dari 16 digit dan tidak boleh
     * diperlakukan sebagai integer.
     */
    public function setKkNumberAttribute($value): void
    {
        $this->attributes['kk_number'] = preg_replace(
            '/\D/',
            '',
            (string) $value
        );
    }

    /**
     * Semua penduduk yang saat ini berada di KK ini.
     *
     * SUMBER KEBENARAN ANGGOTA:
     *
     * penduduk.kk_id
     *
     * BUKAN kk_anggota.
     *
     * kk_anggota hanya digunakan untuk histori perpindahan.
     */
    public function penduduks(): HasMany
    {
        return $this->hasMany(
            Penduduk::class,
            'kk_id'
        );
    }

    /**
     * RT milik KK.
     *
     * Wilayah keluarga ditentukan dari sini.
     */
    public function rt(): BelongsTo
    {
        return $this->belongsTo(
            Rt::class,
            'rt_id'
        );
    }

    /**
     * Jumlah anggota keluarga saat ini.
     *
     * Selalu berdasarkan penduduk.kk_id.
     *
     * Jika withCount('penduduks') sudah digunakan,
     * gunakan hasil tersebut agar tidak melakukan query tambahan.
     */
    public function getJumlahAnggotaAttribute(): int
    {
        return (int) (
            $this->penduduks_count
            ?? $this->penduduks()->count()
        );
    }

    /**
     * Histori keanggotaan KK.
     *
     * BUKAN sumber jumlah anggota saat ini.
     *
     * Digunakan untuk:
     * - histori pindah KK
     * - histori hubungan keluarga
     * - tanggal mulai
     * - tanggal keluar
     */
    public function kkAnggotas(): HasMany
    {
        return $this->hasMany(
            KkAnggota::class,
            'kk_id'
        );
    }

    /**
     * Arsip foto KK.
     *
     * Foto KK bukan hanya untuk OCR.
     * Foto merupakan dokumen resmi/arsip KK.
     */
    public function kkPhotos(): HasMany
    {
        return $this->hasMany(
            KkPhoto::class,
            'kk_id'
        );
    }

    /**
     * OCR jobs yang berkaitan dengan KK.
     */
    public function ocrJobs(): HasMany
    {
        return $this->hasMany(
            OcrJob::class,
            'kk_id'
        );
    }

    /**
     * Audit log KK.
     */
    public function audits(): MorphMany
    {
        return $this->morphMany(
            AuditLog::class,
            'loggable'
        );
    }

    /**
     * Kepala keluarga saat ini.
     *
     * Kepala keluarga TIDAK mempunyai kolom sendiri
     * pada tabel kartu_keluarga.
     *
     * Kepala keluarga adalah Penduduk yang:
     *
     * kk_id = KK ini
     * family_relation = KEPALA_KELUARGA
     *
     * Jika belum ada kepala keluarga, return null.
     */
    public function kepalaKeluarga(): HasOne
    {
        return $this->hasOne(
            Penduduk::class,
            'kk_id'
        )->ofMany(
            ['id' => 'max'],
            function ($query): void {
                $query->where(
                    'family_relation',
                    FamilyRelation::KEPALA_KELUARGA->value
                );
            }
        );
    }

    /**
     * Nama kepala keluarga.
     *
     * Accessor ini hanya untuk tampilan.
     *
     * BUKAN kolom database.
     */
    public function getNamaKepalaKeluargaAttribute(): ?string
    {
        return $this->kepalaKeluarga?->full_name;
    }

    /**
     * Label wilayah KK.
     *
     * Contoh:
     *
     * Lingkungan Bottoe / RT 02
     *
     * atau:
     *
     * RW 01 / RT 02
     *
     * Tergantung nama AreaUnit yang tersimpan.
     */
    public function getRtRwLabelAttribute(): ?string
    {
        $rt = $this->rt;
        $area = $rt?->areaUnit;

        if ($rt === null && $area === null) {
            return null;
        }

        $parts = [];

        if ($area !== null) {
            $label = $area->display_label ?? $area->name;

            if (filled($label)) {
                $parts[] = $label;
            }
        }

        if ($rt !== null) {
            $parts[] = 'RT '.$rt->number;
        }

        return $parts !== []
            ? implode(' / ', $parts)
            : null;
    }

    /**
     * Alias yang lebih jelas untuk kode baru.
     *
     * Wilayah tetap berasal dari KK.
     */
    public function getWilayahAttribute(): ?AreaUnit
    {
        return $this->rt?->areaUnit;
    }

    /**
     * Nomor RT KK.
     */
    public function getNomorRtAttribute(): ?string
    {
        return $this->rt?->number;
    }

    /**
     * Nama RW/Lingkungan KK.
     *
     * AreaUnit sengaja tidak diberi nama RW/Lingkungan
     * secara permanen karena setiap daerah bisa memakai
     * istilah yang berbeda.
     */
    public function getNamaWilayahAttribute(): ?string
    {
        $area = $this->rt?->areaUnit;

        if ($area === null) {
            return null;
        }

        return $area->display_label ?? $area->name;
    }

    /**
     * Foto KK yang sedang aktif.
     *
     * Service layer memastikan hanya satu foto aktif.
     */
    public function activePhoto(): HasOne
    {
        return $this->hasOne(
            KkPhoto::class,
            'kk_id'
        )->ofMany(
            ['id' => 'max'],
            function ($query): void {
                $query->where('is_active', true);
            }
        );
    }

    /**
     * URL thumbnail foto KK aktif.
     *
     * Dipakai tabel KK.
     */
    public function getActivePhotoThumbnailUrlAttribute(): ?string
    {
        $photo = $this->activePhoto;

        if ($photo === null) {
            return null;
        }

        return route(
            'kk-photos.thumbnail',
            $photo
        );
    }

    /**
     * URL foto KK resolusi penuh.
     *
     * Dipakai:
     * - detail KK
     * - edit KK
     * - tabel KK
     */
    public function getActivePhotoFullUrlAttribute(): ?string
    {
        $photo = $this->activePhoto;

        if ($photo === null) {
            return null;
        }

        return route(
            'kk-photos.full',
            $photo
        );
    }

    /**
     * Apakah KK mempunyai foto aktif?
     */
    public function hasActivePhoto(): bool
    {
        return $this->activePhoto !== null;
    }

    /**
     * Apakah KK sudah mempunyai kepala keluarga?
     */
    public function hasKepalaKeluarga(): bool
    {
        return $this->kepalaKeluarga !== null;
    }

    /**
     * Apakah KK mempunyai RT?
     */
    public function hasWilayah(): bool
    {
        return $this->rt !== null;
    }

    /**
     * Scope KK yang mempunyai RT.
     */
    public function scopeWithWilayah($query)
    {
        return $query->whereNotNull('rt_id');
    }

    /**
     * Scope KK tanpa RT.
     *
     * Berguna untuk menemukan data lama yang belum
     * mempunyai wilayah.
     */
    public function scopeWithoutWilayah($query)
    {
        return $query->whereNull('rt_id');
    }

    /**
     * Scope KK yang mempunyai kepala keluarga.
     */
    public function scopeWithKepalaKeluarga($query)
    {
        return $query->whereHas(
            'penduduks',
            function ($penduduk): void {
                $penduduk->where(
                    'family_relation',
                    FamilyRelation::KEPALA_KELUARGA->value
                );
            }
        );
    }

    /**
     * Scope pencarian berdasarkan nomor KK atau
     * nama kepala keluarga.
     *
     * Penting:
     *
     * JANGAN pernah menggunakan:
     *
     * where('kepala_keluarga', ...)
     *
     * karena kolom tersebut TIDAK ADA.
     *
     * Gunakan relasi penduduks.
     */
    public function scopeSearch(
        $query,
        ?string $search
    ) {
        $search = trim((string) $search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function ($query) use ($search): void {
            $query
                ->where(
                    'kk_number',
                    'like',
                    '%'.$search.'%'
                )
                ->orWhere(
                    'address',
                    'like',
                    '%'.$search.'%'
                )
                ->orWhere(
                    'postal_code',
                    'like',
                    '%'.$search.'%'
                )
                ->orWhereHas(
                    'penduduks',
                    function ($penduduk) use ($search): void {
                        $penduduk
                            ->where(
                                'family_relation',
                                FamilyRelation::KEPALA_KELUARGA->value
                            )
                            ->where(
                                'full_name',
                                'like',
                                '%'.$search.'%'
                            );
                    }
                );
        });
    }

    /**
     * Scope KK aktif (daftar utama sistem).
     *
     * 1. Masih mempunyai penduduk saat ini (penduduks)
     * ATAU
     * 2. Baru dibuat dan belum memiliki histori anggota (kkAnggotas)
     */
    public function scopeActive($query)
    {
        return $query->where(function ($query): void {
            $query
                ->whereHas('penduduks')
                ->orWhereDoesntHave('kkAnggotas');
        });
    }

    /**
     * Scope eager loading standar untuk daftar KK.
     *
     * Tujuannya menghindari N+1 query ketika tabel
     * menampilkan:
     * - wilayah
     * - kepala keluarga
     * - jumlah anggota
     * - foto
     */
    public function scopeForList($query)
    {
        return $query
            ->with([
                'rt.areaUnit',
            ])
            ->withCount('penduduks');
    }

    /**
     * Scope eager loading untuk detail KK.
     *
     * Detail membutuhkan:
     * - RT
     * - AreaUnit
     * - semua Penduduk
     * - foto aktif
     */
    public function scopeForDetail($query)
    {
        return $query->with([
            'rt.areaUnit',
            'penduduks.religion',
            'penduduks.education',
            'penduduks.occupation',
            'kkPhotos',
        ]);
    }
}
