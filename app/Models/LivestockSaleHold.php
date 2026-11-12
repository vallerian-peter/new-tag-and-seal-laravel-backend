<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Marketplace lock: at most one ACTIVE hold per animal across every sales
 * channel (live auction, marketplace listing). Enforced by the unique
 * `activeKey` column, which mirrors `livestockUuid` only while active.
 */
class LivestockSaleHold extends Model
{
    public const CHANNEL_AUCTION = 'auction';
    public const CHANNEL_MARKETPLACE = 'marketplace';

    protected $fillable = ['livestockUuid', 'activeKey', 'channel', 'referenceUuid', 'holderUserId', 'status', 'releasedAt'];

    protected $casts = ['releasedAt' => 'datetime'];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('status', 'active');
    }

    public function release(): void
    {
        $this->update(['status' => 'released', 'activeKey' => null, 'releasedAt' => now()]);
    }

    public function consume(): void
    {
        // Keep the key so a sold animal can't be relisted until the sale is undone.
        $this->update(['status' => 'consumed', 'releasedAt' => now()]);
    }

    public function toWire(): array
    {
        return [
            'livestockUuid' => $this->livestockUuid,
            'channel' => $this->channel,
            'referenceUuid' => $this->referenceUuid,
            'status' => $this->status,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
