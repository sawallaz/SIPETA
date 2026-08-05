<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only backup history. No updated_at by design.
 *
 * @property int $id
 * @property string $filename
 * @property BackupType $backup_type
 * @property BackupStatus $backup_status
 * @property int $backup_size
 * @property int|null $operator_id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string|null $message
 * @property-read User|null $operator
 */
class BackupLog extends Model
{
    use HasFactory;

    protected $table = 'backup_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'filename',
        'backup_type',
        'backup_status',
        'backup_size',
        'operator_id',
        'started_at',
        'finished_at',
        'message',
    ];

    protected $casts = [
        'backup_type' => BackupType::class,
        'backup_status' => BackupStatus::class,
        'backup_size' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }
}
