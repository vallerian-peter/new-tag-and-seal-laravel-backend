<?php

namespace App\Models;

use App\Enums\DefinitionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class AnimalBlueprint extends Model
{
    protected $guarded = [];

    protected $casts = [
        'settings_json' => 'array',
        'compiled_manifest_json' => 'array',
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
                $allowed = ['status', 'retired_at', 'updated_at'];
                if (array_diff(array_keys($model->getDirty()), $allowed) !== []) {
                    throw new \LogicException('Published blueprints are immutable. Clone a new version.');
                }
            }
        });
    }

    public function species()
    {
        return $this->belongsTo(AnimalSpecies::class, 'species_uuid', 'uuid');
    }

    public function events()
    {
        return $this->hasMany(EventDefinition::class, 'blueprint_uuid', 'uuid');
    }

    public function registrationForm()
    {
        return $this->belongsTo(FormDefinition::class, 'registration_form_uuid', 'uuid');
    }
}
