<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Singleton kelurahan identity + backup path.
 * Exactly one row is enforced by the Service layer (firstOrCreate(['id' => 1])).
 *
 * @property int $id
 * @property string $kelurahan_name
 * @property string $kecamatan_name
 * @property string $kabupaten_name
 * @property string $province_name
 * @property string|null $logo_path
 */
class Setting extends Model
{
    use HasFactory;

    protected $table = 'settings';

    protected $fillable = [
        'kelurahan_name',
        'kecamatan_name',
        'kabupaten_name',
        'province_name',
        'logo_path',
        'google_drive_account_email',
        'google_drive_folder_id',
        'google_drive_credentials',
        'google_drive_connected_at',
    ];

    protected function casts(): array
    {
        return [
            'google_drive_credentials' => 'encrypted:array',
            'google_drive_connected_at' => 'datetime',
        ];
    }
}
