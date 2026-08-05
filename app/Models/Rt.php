<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Rukun Tetangga. Always belongs to exactly one AreaUnit.
 *
 * @property int $id
 * @property int $area_unit_id
 * @property string $number
 * @property-read AreaUnit $areaUnit
 * @property-read Collection<int, Penduduk> $penduduks
 */
class Rt extends Model
{
    use HasFactory;

    protected $table = 'rts';

    protected $fillable = ['area_unit_id', 'number'];

    public function areaUnit(): BelongsTo
    {
        return $this->belongsTo(AreaUnit::class);
    }

    public function penduduks(): HasMany
    {
        return $this->hasMany(Penduduk::class);
    }
}
