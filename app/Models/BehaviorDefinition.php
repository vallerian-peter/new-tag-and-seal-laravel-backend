<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BehaviorDefinition extends Model
{
    protected $guarded = [];

    protected $casts = [
        'translations_json' => 'array',
        'subject_modes_json' => 'array',
        'roles_json' => 'array',
        'requires_json' => 'array',
        'effects_json' => 'array',
        'reversible' => 'boolean',
    ];
}
