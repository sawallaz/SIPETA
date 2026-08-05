<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Kartu Keluarga — household unit. One row per KK number.
 *
 * @property int $id
 * @property string $kk_number
 * @property string $address
 * @property string|null $postal_code
 * @property string|null $notes
 * @property-read Collection<int, Penduduk> $penduduks
 * @property-read Collection<int, KkAnggota> $kkAnggotas
 * @property-read Collection<int, KkPhoto> $kkPhotos
 * @property-read Collection<int, OcrJob> $ocrJobs
 * @property-read Collection<int, AuditLog> $audits
 */
class KartuKeluarga extends Model
{
    use HasFactory;

    protected $table = 'kartu_keluarga';

    protected $fillable = [
        'kk_number',
        'address',
        'postal_code',
        'notes',
    ];

    public function penduduks(): HasMany
    {
        return $this->hasMany(Penduduk::class, 'kk_id');
    }

    public function kkAnggotas(): HasMany
    {
        return $this->hasMany(KkAnggota::class, 'kk_id');
    }

    public function kkPhotos(): HasMany
    {
        return $this->hasMany(KkPhoto::class, 'kk_id');
    }

    public function ocrJobs(): HasMany
    {
        return $this->hasMany(OcrJob::class, 'kk_id');
    }

    public function audits(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'loggable');
    }
}
