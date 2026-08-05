<?php

namespace App\Models;

use App\Enums\FamilyRelation;
use App\Enums\KkAnggotaStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * KK membership history. Preserves the old KK <-> resident link when reassigned.
 *
 * @property int $id
 * @property int $kk_id
 * @property int $penduduk_id
 * @property FamilyRelation $family_relation
 * @property KkAnggotaStatus $status
 * @property Carbon $effective_date
 * @property Carbon|null $end_date
 * @property-read KartuKeluarga $kartuKeluarga
 * @property-read Penduduk $penduduk
 */
class KkAnggota extends Model
{
    use HasFactory;

    protected $table = 'kk_anggota';

    protected $fillable = [
        'kk_id',
        'penduduk_id',
        'family_relation',
        'status',
        'effective_date',
        'end_date',
    ];

    protected $casts = [
        'family_relation' => FamilyRelation::class,
        'status' => KkAnggotaStatus::class,
        'effective_date' => 'date',
        'end_date' => 'date',
    ];

    public function kartuKeluarga(): BelongsTo
    {
        return $this->belongsTo(KartuKeluarga::class, 'kk_id');
    }

    public function penduduk(): BelongsTo
    {
        return $this->belongsTo(Penduduk::class, 'penduduk_id');
    }
}
