<?php

namespace App\Models;

use App\Enums\AuctionLotStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AuctionLot extends Model
{
    protected $fillable = [
        'uuid', 'sessionId', 'livestockUuid', 'farmUuid', 'sellerUserId', 'sellerCard', 'livestockSnapshot', 'lotNumber', 'title', 'description',
        'photos', 'startingPrice', 'minIncrement', 'reservePrice', 'durationSeconds', 'status', 'openedAt', 'closesAt',
        'closedAt', 'extendedCount', 'currentPrice', 'currentBidId', 'bidCount', 'winnerUserId', 'winningBidId', 'version',
    ];

    protected $casts = [
        'livestockSnapshot' => 'array',
        'sellerCard' => 'array',
        'photos' => 'array',
        'startingPrice' => 'float',
        'minIncrement' => 'float',
        'reservePrice' => 'float',
        'currentPrice' => 'float',
        'durationSeconds' => 'integer',
        'extendedCount' => 'integer',
        'bidCount' => 'integer',
        'version' => 'integer',
        'lotNumber' => 'integer',
        'openedAt' => 'datetime',
        'closesAt' => 'datetime',
        'closedAt' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(AuctionSession::class, 'sessionId');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(AuctionBid::class, 'lotId')->orderByDesc('amount')->orderBy('id');
    }

    public function currentBid(): BelongsTo
    {
        return $this->belongsTo(AuctionBid::class, 'currentBidId');
    }

    public function sale(): HasOne
    {
        return $this->hasOne(AuctionSale::class, 'lotId');
    }

    public function isLive(): bool
    {
        return $this->status === AuctionLotStatus::LIVE;
    }

    public function isExpired(): bool
    {
        return $this->isLive() && $this->closesAt !== null && $this->closesAt->lte(now());
    }

    /** Lowest amount the next bid must reach. */
    public function minimumNextBid(): float
    {
        if ($this->bidCount === 0 || $this->currentPrice <= 0) {
            return (float) $this->startingPrice;
        }

        return (float) $this->currentPrice + (float) $this->minIncrement;
    }

    public function reserveMet(): bool
    {
        return $this->reservePrice === null || (float) $this->currentPrice >= (float) $this->reservePrice;
    }

    public function secondsRemaining(): ?int
    {
        if (! $this->isLive() || $this->closesAt === null) {
            return null;
        }

        return max(0, $this->closesAt->getTimestamp() - now()->getTimestamp());
    }

    /**
     * Wire shape. The reserve price is only revealed to the host; buyers only
     * learn whether it has been met.
     */
    public function toWire(?int $viewerUserId = null, bool $isHost = false): array
    {
        $leading = $this->relationLoaded('currentBid') ? $this->currentBid : ($this->currentBidId ? AuctionBid::find($this->currentBidId) : null);

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'sessionId' => $this->sessionId,
            'lotNumber' => $this->lotNumber,
            'title' => $this->title,
            'description' => $this->description,
            'livestockUuid' => $this->livestockUuid,
            'farmUuid' => $this->farmUuid,
            'sellerUserId' => $this->sellerUserId,
            'seller' => $this->sellerCard ? [
                'name' => $this->sellerCard['name'] ?? null,
                'farmName' => $this->sellerCard['farmName'] ?? null,
                'phone' => $isHost ? ($this->sellerCard['phone'] ?? null) : null,
            ] : null,
            'livestock' => $this->livestockSnapshot,
            'photos' => $this->photos ?? [],
            'startingPrice' => (float) $this->startingPrice,
            'minIncrement' => (float) $this->minIncrement,
            'reservePrice' => $isHost ? $this->reservePrice : null,
            'hasReserve' => $this->reservePrice !== null,
            'reserveMet' => $this->reserveMet(),
            'durationSeconds' => $this->durationSeconds,
            'status' => $this->status,
            'openedAt' => $this->openedAt?->toIso8601String(),
            'closesAt' => $this->closesAt?->toIso8601String(),
            'closedAt' => $this->closedAt?->toIso8601String(),
            'secondsRemaining' => $this->secondsRemaining(),
            'extendedCount' => $this->extendedCount,
            'currentPrice' => (float) $this->currentPrice,
            'minimumNextBid' => $this->minimumNextBid(),
            'bidCount' => $this->bidCount,
            'leadingBid' => $leading ? [
                'uuid' => $leading->uuid,
                'bidderNumber' => $leading->bidderNumber,
                'amount' => (float) $leading->amount,
                'isMine' => $viewerUserId !== null && (int) $leading->bidderUserId === (int) $viewerUserId,
                'at' => $leading->created_at?->toIso8601String(),
            ] : null,
            'winnerUserId' => $this->winnerUserId,
            'iAmWinner' => $viewerUserId !== null && $this->winnerUserId !== null && (int) $this->winnerUserId === (int) $viewerUserId,
            'version' => $this->version,
        ];
    }
}
