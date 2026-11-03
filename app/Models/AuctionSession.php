<?php

namespace App\Models;

use App\Enums\AuctionSessionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuctionSession extends Model
{
    protected $fillable = [
        'uuid', 'hostUserId', 'centreId', 'createdBy', 'title', 'description', 'coverPath', 'status', 'scheduledAt', 'startedAt',
        'endedAt', 'lastHeartbeatAt', 'currency', 'settings', 'paymentInstructions', 'viewers', 'viewerPeak',
        'lotsCount', 'soldCount', 'grossSales', 'egressId', 'recordingStatus', 'recordingPath',
    ];

    protected $casts = [
        'settings' => 'array',
        'scheduledAt' => 'datetime',
        'startedAt' => 'datetime',
        'endedAt' => 'datetime',
        'lastHeartbeatAt' => 'datetime',
        'viewers' => 'integer',
        'viewerPeak' => 'integer',
        'lotsCount' => 'integer',
        'soldCount' => 'integer',
        'grossSales' => 'float',
    ];

    public static function defaultSettings(): array
    {
        return [
            'chat' => true,
            'antiSnipeSeconds' => 15,
            'extendBySeconds' => 15,
            'paymentWindowHours' => 48,
            'autoOpenNextLot' => false,
        ];
    }

    public function config(): array
    {
        return array_merge(self::defaultSettings(), $this->settings ?? []);
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hostUserId');
    }

    /** The auction centre this session is hosted at, when not a farm auction. */
    public function centre(): BelongsTo
    {
        return $this->belongsTo(AuctionCentre::class, 'centreId');
    }

    public function lots(): HasMany
    {
        return $this->hasMany(AuctionLot::class, 'sessionId')->orderBy('lotNumber');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(AuctionParticipant::class, 'sessionId');
    }

    public function bids(): HasMany
    {
        return $this->hasMany(AuctionBid::class, 'sessionId');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(AuctionSale::class, 'sessionId');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AuctionEvent::class, 'sessionId')->orderBy('id');
    }

    public function scopeLive(Builder $q): Builder
    {
        return $q->where('status', AuctionSessionStatus::LIVE);
    }

    public function scopeUpcoming(Builder $q): Builder
    {
        return $q->where('status', AuctionSessionStatus::SCHEDULED)->orderBy('scheduledAt');
    }

    public function isLive(): bool
    {
        return $this->status === AuctionSessionStatus::LIVE;
    }

    public function isHost(?int $userId): bool
    {
        return $userId !== null && (int) $this->hostUserId === (int) $userId;
    }

    public function liveLot(): ?AuctionLot
    {
        return $this->lots()->where('status', 'live')->first();
    }

    public function toWire(?int $viewerUserId = null, bool $withLots = false): array
    {
        $isHost = $this->isHost($viewerUserId);
        $hostCard = $this->relationLoaded('host') ? $this->host?->farmerCard() : null;
        $data = [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'description' => $this->description,
            'coverUrl' => $this->coverPath ? url('storage/'.$this->coverPath) : null,
            'status' => $this->status,
            'hostUserId' => $this->hostUserId,
            'host' => $hostCard ? ['userId' => $hostCard['userId'], 'name' => $hostCard['name'], 'phone' => $isHost ? $hostCard['phone'] : null] : null,
            'isHost' => $isHost,
            'centreId' => $this->centreId,
            'centre' => $this->centreId ? $this->centre?->summary() : null,
            'plannedByPortal' => $this->createdBy !== null,
            'scheduledAt' => $this->scheduledAt?->toIso8601String(),
            'startedAt' => $this->startedAt?->toIso8601String(),
            'endedAt' => $this->endedAt?->toIso8601String(),
            'currency' => $this->currency,
            'settings' => (object) $this->config(),
            'paymentInstructions' => $this->paymentInstructions,
            'viewers' => $this->viewers,
            'viewerPeak' => $this->viewerPeak,
            'lotsCount' => $this->lotsCount,
            'soldCount' => $this->soldCount,
            'grossSales' => (float) $this->grossSales,
            'recordingStatus' => $this->recordingStatus,
            'recordingUrl' => $this->recordingPath ? url('storage/'.$this->recordingPath) : null,
            'serverTime' => now()->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
        if ($withLots) {
            $lots = $this->relationLoaded('lots') ? $this->lots : $this->lots()->get();
            $data['lots'] = $lots->map(fn (AuctionLot $l) => $l->toWire($viewerUserId, $isHost))->values()->all();
        }

        return $data;
    }
}
