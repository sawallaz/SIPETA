<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendudukDocument extends Model
{
    use HasFactory;

    protected $table = 'penduduk_documents';

    protected $fillable = [
        'penduduk_id',
        'document_type',
        'original_filename',
        'stored_filename',
        'mime_type',
        'file_size',
        'sha256_hash',
        'storage_disk',
        'storage_path',
        'is_active',
        'uploaded_by',
        'uploaded_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'uploaded_at' => 'datetime',
    ];

    public function penduduk(): BelongsTo
    {
        return $this->belongsTo(Penduduk::class);
    }

    public function isKtp(): bool
    {
        return $this->document_type === 'KTP';
    }

    public function isAktaKelahiran(): bool
    {
        return $this->document_type === 'AKTA_KELAHIRAN';
    }
}
