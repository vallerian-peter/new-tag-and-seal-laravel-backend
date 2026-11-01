<?php

namespace App\Models;

use App\Enums\DefinitionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EventDefinition extends Model
{
    protected $guarded = [];

    protected $casts = [
        'translations_json' => 'array',
        'eligibility_rule_json' => 'array',
        'repeatability_rule_json' => 'array',
        'result_rule_json' => 'array',
        'status' => DefinitionStatus::class,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model): void {
            $model->uuid ??= (string) Str::uuid();
        });
    }

    public function blueprint()
    {
        return $this->belongsTo(AnimalBlueprint::class, 'blueprint_uuid', 'uuid');
    }

    public function form()
    {
        return $this->belongsTo(FormDefinition::class, 'form_uuid', 'uuid');
    }
}
