<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Morphic audit trail (who/when/what/old/new). No hard FK — audit outlives the row.
 * Append-only by design (created_at only). Written by Model Observers in the app phase.
 *
 * @property int $id
 * @property string $loggable_type
 * @property int $loggable_id
 * @property string|null $actor_type
 * @property int|null $actor_id
 * @property string $event
 * @property array|null $old_values
 * @property array|null $new_values
 * @property string|null $ip_address
 * @property-read Model $loggable
 * @property-read Model|null $actor
 */
class AuditLog extends Model
{
    use HasFactory;

    protected $table = 'audit_logs';

    public const UPDATED_AT = null;

    protected $fillable = [
        'loggable_type',
        'loggable_id',
        'actor_type',
        'actor_id',
        'event',
        'old_values',
        'new_values',
        'ip_address',
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
    ];

    public function loggable(): MorphTo
    {
        return $this->morphTo();
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
