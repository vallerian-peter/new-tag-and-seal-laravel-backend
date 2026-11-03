<?php

namespace App\Services\Auction;

use App\Models\LivestockSaleHold;
use App\Services\VetServices\VetServiceException;
use Illuminate\Database\QueryException;

/**
 * The cross-channel marketplace lock. Every sales channel (live auction,
 * marketplace listing) must acquire a hold before offering an animal.
 */
class SaleHoldService
{
    public function activeFor(string $livestockUuid): ?LivestockSaleHold
    {
        return LivestockSaleHold::active()->where('livestockUuid', $livestockUuid)->first();
    }

    /** @return array<string, LivestockSaleHold> keyed by livestockUuid */
    public function activeForMany(array $livestockUuids): array
    {
        return LivestockSaleHold::active()->whereIn('livestockUuid', $livestockUuids)->get()->keyBy('livestockUuid')->all();
    }

    public function acquire(string $livestockUuid, string $channel, string $referenceUuid, int $holderUserId): LivestockSaleHold
    {
        try {
            return LivestockSaleHold::create([
                'livestockUuid' => $livestockUuid,
                'activeKey' => $livestockUuid,
                'channel' => $channel,
                'referenceUuid' => $referenceUuid,
                'holderUserId' => $holderUserId,
                'status' => 'active',
            ]);
        } catch (QueryException $e) {
            $existing = $this->activeFor($livestockUuid);
            $where = $existing?->channel === LivestockSaleHold::CHANNEL_MARKETPLACE ? 'the marketplace' : 'another auction';
            throw new VetServiceException(
                "This animal is already listed for sale on {$where}. Remove that listing first.",
                409,
                'ANIMAL_ALREADY_LISTED'
            );
        }
    }

    public function release(string $channel, string $referenceUuid): void
    {
        LivestockSaleHold::active()->where('channel', $channel)->where('referenceUuid', $referenceUuid)
            ->get()->each->release();
    }

    public function consume(string $channel, string $referenceUuid): void
    {
        LivestockSaleHold::active()->where('channel', $channel)->where('referenceUuid', $referenceUuid)
            ->get()->each->consume();
    }
}
