<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'uuid',
        'actor_uuid',
        'action',
        'entity_type',
        'entity_uuid',
        'before_json',
        'after_json',
        'reason',
        'request_uuid',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'created_at' => 'datetime',
    ];
}
