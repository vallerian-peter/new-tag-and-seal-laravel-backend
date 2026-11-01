<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Services\Dynamic\SyncChangeRecorder;
use Illuminate\Support\Str;

class SubjectIdentifier extends Model
{
    use SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'metadata_json' => 'array',
        'assigned_at' => 'datetime',
        'retired_at' => 'datetime',
        'revision' => 'integer',
    ];

    public function type(): BelongsTo
    {
        return $this->belongsTo(IdentifierType::class, 'identifier_type_uuid', 'uuid');
    }

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
        static::saved(fn (self $model) => SyncChangeRecorder::identifier($model));
        static::deleted(fn (self $model) => SyncChangeRecorder::identifier($model));
    }
}
