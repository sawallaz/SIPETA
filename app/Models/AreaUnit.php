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

    /**
     * Label wilayah yang ditampilkan ke operator.
     *
     * SATU sumber label untuk seluruh aplikasi (form KK, tabel KK, wilayah
     * read-only pada Penduduk) sehingga tidak ada implementasi ganda.
     *
     * Contoh:
     * - type = lingkungan, name = "Lingkungan I"  -> "Lingkungan I"
     * - type = rw,         code = "01"            -> "RW 01"
     * - type = rw,         name = "RW 01"         -> "RW 01" (tanpa prefiks ganda)
     * - type = rw,         name = "01"            -> "RW 01"
     */
    public function getDisplayLabelAttribute(): string
    {
        $name = trim((string) $this->name);
        $type = strtolower(trim((string) $this->type));

        if ($type !== 'rw') {
            return $name;
        }

        if (filled($this->code)) {
            return 'RW '.trim((string) $this->code);
        }

        /*
         * Kolom `code` tidak lagi diisi dari form (operator hanya mengetik
         * nama), jadi nama bisa saja sudah mengandung prefiks "RW".
         */
        if (preg_match('/^rw\b/i', $name) === 1) {
            return $name;
        }

        return 'RW '.$name;
    }
}
