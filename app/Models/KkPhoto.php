<?php

namespace App\Models;

use App\Enums\PhotoType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Versioned KK photo archive. Exactly one row per kk_id has is_active = true
 * (enforced in the Service layer).
 *
 * @property int $id
 * @property int $kk_id
 * @property string $original_filename
 * @property string $stored_filename
 * @property string|null $thumbnail_filename
 * @property string $mime_type
 * @property int $file_size
 * @property string $sha256_hash
 * @property string $storage_disk
 * @property string $storage_path
 * @property PhotoType $photo_type
 * @property bool $is_active
 * @property int|null $uploaded_by
 * @property Carbon $uploaded_at
 * @property int|null $ocr_job_id
 * @property-read KartuKeluarga $kartuKeluarga
 * @property-read User|null $uploader
 * @property-read OcrJob|null $ocrJob
 */
class KkPhoto extends Model
{
    use HasFactory;

    protected $table = 'kk_photos';

    protected $fillable = [
        'kk_id',
        'original_filename',
        'stored_filename',
        'thumbnail_filename',
        'mime_type',
        'file_size',
        'sha256_hash',
        'storage_disk',
        'storage_path',
        'photo_type',
        'is_active',
        'uploaded_by',
        'uploaded_at',
        'ocr_job_id',
    ];

    protected $casts = [
        'photo_type' => PhotoType::class,
        'is_active' => 'boolean',
        'file_size' => 'integer',
        'uploaded_at' => 'datetime',
    ];

    public function kartuKeluarga(): BelongsTo
    {
        return $this->belongsTo(KartuKeluarga::class, 'kk_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function ocrJob(): BelongsTo
    {
        return $this->belongsTo(OcrJob::class, 'ocr_job_id');
    }
}
