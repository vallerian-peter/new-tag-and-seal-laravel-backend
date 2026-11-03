<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionSale extends Model
{
    protected $fillable = [
        'uuid', 'lotId', 'sessionId', 'buyerUserId', 'sellerUserId', 'livestockUuid', 'amount', 'currency', 'status',
        'paymentInstructions', 'paymentReference', 'paymentDeadlineAt', 'paidAt', 'confirmedByUserId',
    ];

    protected $casts = ['amount' => 'float', 'paymentDeadlineAt' => 'datetime', 'paidAt' => 'datetime'];

    public function lot(): BelongsTo
    {
        return $this->belongsTo(AuctionLot::class, 'lotId');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AuctionSession::class, 'sessionId');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyerUserId');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sellerUserId');
    }

    public function toWire(?int $viewerUserId = null): array
    {
        $isBuyer = $viewerUserId !== null && (int) $this->buyerUserId === (int) $viewerUserId;
        $isSeller = $viewerUserId !== null && (int) $this->sellerUserId === (int) $viewerUserId;
        $lot = $this->relationLoaded('lot') ? $this->lot : null;
        $session = $this->relationLoaded('session') ? $this->session : null;

        return [
            'uuid' => $this->uuid,
            'lotUuid' => $lot?->uuid,
            'sessionUuid' => $session?->uuid,
            'sessionTitle' => $session?->title,
            'lotNumber' => $lot?->lotNumber,
            'livestock' => $lot?->livestockSnapshot,
            'livestockUuid' => $this->livestockUuid,
            'amount' => (float) $this->amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'paymentInstructions' => $this->paymentInstructions,
            'paymentReference' => $this->paymentReference,
            'paymentDeadlineAt' => $this->paymentDeadlineAt?->toIso8601String(),
            'paidAt' => $this->paidAt?->toIso8601String(),
            'buyer' => $isSeller && $this->relationLoaded('buyer') ? $this->buyer?->farmerCard() : null,
            'seller' => $isBuyer && $this->relationLoaded('seller') ? $this->seller?->farmerCard() : null,
            'isBuyer' => $isBuyer,
            'isSeller' => $isSeller,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
