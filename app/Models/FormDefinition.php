<?php

namespace App\Models;

use App\Enums\DefinitionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FormDefinition extends Model
{
    protected $guarded = [];

    protected $casts = [
        'schema_json' => 'array',
        'status' => DefinitionStatus::class,
        'published_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });

        static::updating(function (self $model): void {
            if ($model->getOriginal('status') === DefinitionStatus::PUBLISHED->value) {
                throw new \LogicException('Published form definitions are immutable. Clone a new version.');
            }
        });
    }
}
