<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Flexible Area Level 1 (Lingkungan OR RW, per local government).
 * The `type` column carries the local admin label so one schema serves any kelurahan.
 *
 * @property int $id
 * @property string $name
 * @property string|null $type
 * @property string|null $code
 * @property-read Collection<int, Rt> $rts
 */
class AreaUnit extends Model
{
    use HasFactory;

    protected $table = 'area_units';

    protected $fillable = ['name', 'type', 'code'];

    public function rts(): HasMany
    {
        return $this->hasMany(Rt::class);
    }
}
