<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionBid extends Model
{
    public $timestamps = false;

    protected $fillable = ['uuid', 'lotId', 'sessionId', 'bidderUserId', 'bidderNumber', 'amount', 'status', 'rejectReason', 'created_at'];

    protected $casts = ['amount' => 'float', 'bidderNumber' => 'integer', 'created_at' => 'datetime'];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(AuctionLot::class, 'lotId');
    }

    public function bidder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bidderUserId');
    }

    public function toWire(?int $viewerUserId = null): array
    {
        return [
            'uuid' => $this->uuid,
            'lotId' => $this->lotId,
            'bidderNumber' => $this->bidderNumber,
            'amount' => (float) $this->amount,
            'status' => $this->status,
            'isMine' => $viewerUserId !== null && (int) $this->bidderUserId === (int) $viewerUserId,
            'at' => $this->created_at?->toIso8601String(),
        ];
    }
}
