<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class LookupSet extends Model
{
    protected $guarded = [];

    protected $casts = [
        'translations_json' => 'array',
        'is_system' => 'boolean',
        'is_extensible' => 'boolean',
        'published_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function options(): HasMany
    {
        return $this->hasMany(LookupOption::class, 'lookup_set_uuid', 'uuid');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_lookup_set_uuid', 'uuid');
    }
}
