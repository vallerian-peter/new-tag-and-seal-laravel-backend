<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuctionEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['sessionId', 'lotId', 'type', 'actorUserId', 'payload', 'created_at'];

    protected $casts = ['payload' => 'array', 'created_at' => 'datetime'];

    public function toWire(): array
    {
        return [
            'id' => $this->id,
            'lotId' => $this->lotId,
            'type' => $this->type,
            'actorUserId' => $this->actorUserId,
            'payload' => $this->payload,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
