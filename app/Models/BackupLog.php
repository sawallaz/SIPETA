<?php

namespace App\Models;

use App\Enums\BackupStatus;
use App\Enums\BackupType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Google Drive backup history. Rows are removed only after a confirmed remote
 * Drive deletion succeeds; failed deletes retain the history row.
 *
 * @property int $id
 * @property string $filename
 * @property string|null $drive_file_id
 * @property string|null $drive_folder_id
 * @property BackupType $backup_type
 * @property BackupStatus $backup_status
 * @property int $backup_size
 * @property string|null $checksum
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
        'drive_file_id',
        'drive_folder_id',
        'backup_type',
        'backup_status',
        'backup_size',
        'checksum',
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
