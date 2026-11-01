<?php

namespace App\Models;

use App\Services\Dynamic\SyncChangeRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EventRecord extends Model
{
    protected $guarded = [];

    protected $casts = [
        'payload_json' => 'array',
        'occurred_at' => 'datetime',
        'voided_at' => 'datetime',
        'revision' => 'integer',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(AnimalSubject::class, 'subject_uuid', 'uuid');
    }

    public function farm(): BelongsTo
    {
        return $this->belongsTo(Farm::class, 'farm_uuid', 'uuid');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(EventDefinition::class, 'event_code', 'code');
    }

    public function behavior(): BelongsTo
    {
        return $this->belongsTo(BehaviorDefinition::class, 'behavior_code', 'code');
    }

    public function familyCode(): ?string
    {
        return match ($this->behavior_code) {
            'birth', 'abortion' => 'births',
            'vaccination', 'deworming', 'medication', 'condition_record' => 'health',
            'milking', 'production_yield' => 'production',
            'insemination', 'pregnancy_test', 'dry_off' => 'breeding',
            'weight_measurement', 'feeding', 'husbandry_procedure', 'marking', 'stage_change' => 'growth',
            'transfer', 'disposal' => 'movement',
            default => null,
        };
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
            $model->idempotency_uuid ??= (string) Str::uuid();
        });
        static::saved(fn (self $model) => SyncChangeRecorder::event($model));
    }
}
