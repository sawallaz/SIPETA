<?php

namespace App\Models;

use App\Enums\ResidentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendudukStatusHistory extends Model
{
    use HasFactory;

    protected $table = 'penduduk_status_histories';

    protected $fillable = [
        'penduduk_id',
        'status',
        'recorded_at',
        'user_id',
        'notes',
    ];

    protected $casts = [
        'status' => ResidentStatus::class,
        'recorded_at' => 'date',
    ];

    public function penduduk(): BelongsTo
    {
        return $this->belongsTo(Penduduk::class, 'penduduk_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
