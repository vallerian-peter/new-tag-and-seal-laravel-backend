<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ConfigurationRevision extends Model
{
    protected $guarded = [];

    protected $casts = [
        'manifest_json' => 'array',
        'published_at' => 'datetime',
        'revision' => 'integer',
    ];
}
