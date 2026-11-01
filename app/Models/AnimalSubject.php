<?php

namespace App\Models;

use App\Enums\SexCode;
use App\Enums\SubjectUnitType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Services\Dynamic\SyncChangeRecorder;
use Illuminate\Support\Str;

class AnimalSubject extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'attributes_json' => 'array',
        'registration_context_json' => 'array',
        'unit_type' => SubjectUnitType::class,
        'sex_code' => SexCode::class,
        'birth_or_start_date' => 'date',
        'restored_at' => 'datetime',
        'revision' => 'integer',
        'quantity' => 'integer',
        'male_count' => 'integer',
        'female_count' => 'integer',
        'unknown_sex_count' => 'integer',
        'biomass' => 'decimal:3',
    ];

    public function species(): BelongsTo
    {
        return $this->belongsTo(AnimalSpecies::class, 'species_uuid', 'uuid');
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(AnimalBlueprint::class, 'blueprint_uuid', 'uuid');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class, 'farm_uuid', 'uuid');
    }

    public function identifiers(): HasMany
    {
        return $this->hasMany(SubjectIdentifier::class, 'subject_uuid', 'uuid')->where('status', 'active');
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
        static::saved(fn (self $model) => SyncChangeRecorder::subject($model));
        static::deleted(fn (self $model) => SyncChangeRecorder::subject($model));
        static::restored(fn (self $model) => SyncChangeRecorder::subject($model));
    }
}
