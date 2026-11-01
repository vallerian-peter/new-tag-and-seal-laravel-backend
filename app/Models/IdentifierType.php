<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class IdentifierType extends Model
{
    protected $guarded = [];

    protected $casts = [
        'translations_json' => 'array',
        'supported_subject_modes' => 'array',
        'format_rule' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }
}
