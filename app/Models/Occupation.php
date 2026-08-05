<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lookup master: pekerjaan. Evolving taxonomy (data, not enum).
 *
 * @property int $id
 * @property string $name
 * @property-read Collection<int, Penduduk> $penduduks
 */
class Occupation extends Model
{
    use HasFactory;

    protected $table = 'occupations';

    protected $fillable = ['name'];

    public function penduduks(): HasMany
    {
        return $this->hasMany(Penduduk::class);
    }
}
