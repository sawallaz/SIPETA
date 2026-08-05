<?php

namespace App\Models;

use App\Enums\OcrJobStatus;
use App\Enums\OcrOutcome;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * OCR attempt log + extracted-data snapshot. Audit/infrastructure only — never source of truth.
 *
 * @property int $id
 * @property int|null $kk_id
 * @property string|null $source_image_hash
 * @property string $source_image_path
 * @property OcrJobStatus $status
 * @property float|null $confidence
 * @property string|null $raw_text
 * @property string|null $corrected_text
 * @property array|null $extracted_data
 * @property int|null $operator_id
 * @property Carbon|null $reviewed_at
 * @property OcrOutcome|null $outcome
 * @property string|null $error_message
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property-read KartuKeluarga|null $kartuKeluarga
 * @property-read User|null $operator
 * @property-read Collection<int, KkPhoto> $kkPhotos
 */
class OcrJob extends Model
{
    use HasFactory;

    protected $table = 'ocr_jobs';

    protected $fillable = [
        'kk_id',
        'source_image_hash',
        'source_image_path',
        'status',
        'confidence',
        'raw_text',
        'corrected_text',
        'extracted_data',
        'operator_id',
        'reviewed_at',
        'outcome',
        'error_message',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'status' => OcrJobStatus::class,
        'confidence' => 'decimal:2',
        'extracted_data' => 'array',
        'reviewed_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function kartuKeluarga(): BelongsTo
    {
        return $this->belongsTo(KartuKeluarga::class, 'kk_id');
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function kkPhotos(): HasMany
    {
        return $this->hasMany(KkPhoto::class, 'ocr_job_id');
    }
}
