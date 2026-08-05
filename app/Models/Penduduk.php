<?php

namespace App\Models;

use App\Enums\BloodType;
use App\Enums\FamilyRelation;
use App\Enums\Gender;
use App\Enums\MaritalStatus;
use App\Enums\ResidentStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Individual resident. Belongs to exactly one current KK; lives in one RT.
 *
 * @property int $id
 * @property int $kk_id
 * @property string $nik
 * @property string $full_name
 * @property Gender $gender
 * @property string $birth_place
 * @property \Illuminate\Support\Carbon $birth_date
 * @property int $religion_id
 * @property int $education_id
 * @property int $occupation_id
 * @property MaritalStatus $marital_status
 * @property FamilyRelation $family_relation
 * @property BloodType $blood_type
 * @property ResidentStatus $resident_status
 * @property int $rt_id
 * @property \Illuminate\Support\Carbon|null $moved_at
 * @property string|null $moved_destination
 * @property string|null $moved_note
 * @property \Illuminate\Support\Carbon|null $deceased_at
 * @property string|null $deceased_note
 * @property string|null $notes
 * @property-read KartuKeluarga $kartuKeluarga
 * @property-read Religion $religion
 * @property-read Education $education
 * @property-read Occupation $occupation
 * @property-read Rt $rt
 * @property-read Collection<int, KkAnggota> $kkAnggotas
 * @property-read Collection<int, AuditLog> $audits
 */
class Penduduk extends Model
{
    use HasFactory;

    protected $table = 'penduduk';

    protected $fillable = [
        'kk_id',
        'nik',
        'full_name',
        'gender',
        'birth_place',
        'birth_date',
        'religion_id',
        'education_id',
        'occupation_id',
        'marital_status',
        'family_relation',
        'blood_type',
        'resident_status',
        'rt_id',
        'moved_at',
        'moved_destination',
        'moved_note',
        'deceased_at',
        'deceased_note',
        'notes',
    ];

    protected $casts = [
        'gender' => Gender::class,
        'marital_status' => MaritalStatus::class,
        'family_relation' => FamilyRelation::class,
        'blood_type' => BloodType::class,
        'resident_status' => ResidentStatus::class,
        'birth_date' => 'date',
        'moved_at' => 'date',
        'deceased_at' => 'date',
    ];

    public function kartuKeluarga(): BelongsTo
    {
        return $this->belongsTo(KartuKeluarga::class, 'kk_id');
    }

    public function religion(): BelongsTo
    {
        return $this->belongsTo(Religion::class);
    }

    public function education(): BelongsTo
    {
        return $this->belongsTo(Education::class);
    }

    public function occupation(): BelongsTo
    {
        return $this->belongsTo(Occupation::class);
    }

    public function rt(): BelongsTo
    {
        return $this->belongsTo(Rt::class);
    }

    public function kkAnggotas(): HasMany
    {
        return $this->hasMany(KkAnggota::class);
    }

    public function audits(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'loggable');
    }

    /**
     * Age is NEVER stored. Computed at read time per ADR-007.
     */
    public function getAgeAttribute(): int
    {
        return Carbon::parse($this->birth_date)->age;
    }

    /**
     * Scope: only residents whose current status is ACTIVE.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('resident_status', ResidentStatus::ACTIVE->value);
    }
}
