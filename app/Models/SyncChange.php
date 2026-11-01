<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncChange extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'data_json' => 'array',
        'revision' => 'integer',
    ];
}
