<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VetServiceRequestEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'requestId', 'type', 'actorUserId', 'fromStatus', 'toStatus', 'payload', 'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(VetServiceRequest::class, 'requestId');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actorUserId');
    }

    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'actorUserId' => $this->actorUserId,
            'fromStatus' => $this->fromStatus,
            'toStatus' => $this->toStatus,
            'payload' => $this->payload,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
