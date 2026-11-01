<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncTombstone extends Model
{
    protected $fillable = [
        'uuid',
        'entity_type',
        'entity_uuid',
        'farm_uuid',
        'entity_revision',
        'operation',
        'occurred_at',
        'superseded_at',
    ];

    protected $casts = [
        'entity_revision' => 'integer',
        'occurred_at' => 'datetime',
        'superseded_at' => 'datetime',
    ];
}
