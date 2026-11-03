<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuctionSale extends Model
{
    protected $fillable = [
        'uuid', 'lotId', 'sessionId', 'buyerUserId', 'sellerUserId', 'livestockUuid', 'amount', 'currency', 'status',
        'paymentInstructions', 'paymentReference', 'paymentDeadlineAt', 'paidAt', 'confirmedByUserId',
        'receiptNo', 'channel', 'buyerName', 'buyerPhone', 'weightKg', 'pricePerKg', 'recordedByUserId',
    ];

    protected $casts = ['amount' => 'float', 'weightKg' => 'float', 'pricePerKg' => 'float', 'paymentDeadlineAt' => 'datetime', 'paidAt' => 'datetime'];

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

    public function toWire(?int $viewerUserId = null, bool $asStaff = false): array
    {
        $isBuyer = $viewerUserId !== null && $this->buyerUserId !== null && (int) $this->buyerUserId === (int) $viewerUserId;
        $isSeller = $viewerUserId !== null && (int) $this->sellerUserId === (int) $viewerUserId;
        $lot = $this->relationLoaded('lot') ? $this->lot : null;
        $session = $this->relationLoaded('session') ? $this->session : null;
        $isHost = $viewerUserId !== null && $session !== null && $session->isHost($viewerUserId);
        $staff = $asStaff || $isHost || $isSeller || ($viewerUserId !== null && (int) $this->recordedByUserId === (int) $viewerUserId);

        return [
            'uuid' => $this->uuid,
            'receiptNo' => $this->receiptNo,
            'channel' => $this->channel ?? 'live',
            'lotUuid' => $lot?->uuid,
            'sessionUuid' => $session?->uuid,
            'sessionTitle' => $session?->title,
            'lotNumber' => $lot?->lotNumber,
            'livestock' => $lot?->livestockSnapshot,
            'livestockUuid' => $this->livestockUuid,
            'amount' => (float) $this->amount,
            'weightKg' => $this->weightKg === null ? null : (float) $this->weightKg,
            'pricePerKg' => $this->pricePerKg === null ? null : (float) $this->pricePerKg,
            'buyerName' => $staff || $isBuyer ? $this->buyerName : null,
            'buyerPhone' => $staff ? $this->buyerPhone : null,
            'sellerName' => $lot?->sellerCard['name'] ?? null,
            'currency' => $this->currency,
            'status' => $this->status,
            'paymentInstructions' => $this->paymentInstructions,
            'paymentReference' => $this->paymentReference,
            'paymentDeadlineAt' => $this->paymentDeadlineAt?->toIso8601String(),
            'paidAt' => $this->paidAt?->toIso8601String(),
            'buyer' => $staff && $this->relationLoaded('buyer') ? $this->buyer?->farmerCard() : null,
            'seller' => ($isBuyer || $staff) && $this->relationLoaded('seller') ? $this->seller?->farmerCard() : null,
            'isBuyer' => $isBuyer,
            'isSeller' => $isSeller,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
