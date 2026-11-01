<?php

namespace App\Models;

use App\Enums\DefinitionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AnimalSpecies extends Model
{
    protected $guarded = [];

    protected $casts = [
        'translations_json' => 'array',
        'capabilities_json' => 'array',
        'status' => DefinitionStatus::class,
        'published_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }
}
