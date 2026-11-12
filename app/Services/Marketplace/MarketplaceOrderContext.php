<?php

namespace App\Services\Marketplace;

use App\Models\AnimalCommerceReservation;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingItem;
use App\Models\PurchaseOrder;
use App\Services\Dynamic\FarmAccessService;
use App\Services\Hiring\FarmOwnership;
use Illuminate\Support\Facades\DB;

class MarketplaceOrderContext
{
    public function resolveBuyerFarm($buyer, ?string $requestedFarmUuid): string
    {
        $farmUuid = $requestedFarmUuid;
        if (! $farmUuid) {
            $ownedFarms = app(FarmOwnership::class)->farms($buyer);
            $farmUuid = $ownedFarms[0]['uuid'] ?? null;
        }
        abort_unless(
            $farmUuid && app(FarmAccessService::class)->can($buyer, $farmUuid, 'livestock.manage'),
            422,
            'Choose a destination farm that you can manage before checkout.'
        );

        return $farmUuid;
    }

    public function releaseExpiredHolds(): void
    {
        AnimalCommerceReservation::where('reservation_type', 'offer_hold')
            ->where('status', 'active')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => 'expired', 'released_at' => now(), 'release_reason' => 'Hold period ended']);
    }

    /**
     * Clean up expired reservations (48 hours timeout).
     */
    public function releaseExpiredOrders(): void
    {
        $this->releaseExpiredHolds();
        $expiredOrders = PurchaseOrder::whereIn('status', ['pending_payment', 'pending_settlement'])
            ->whereNull('payment_evidence_submitted_at')
            ->whereNotNull('reserved_until')
            ->where('reserved_until', '<', now())
            ->get();

        foreach ($expiredOrders as $order) {
            DB::transaction(function () use ($order): void {
                $order->update([
                    'status' => 'cancelled',
                    'cancellation_reason' => '48-hour payment reservation expired.',
                ]);

                // Release commerce reservations
                AnimalCommerceReservation::where('reference_uuid', $order->uuid)
                    ->where('status', 'active')
                    ->update([
                        'status' => 'expired',
                        'released_at' => now(),
                        'release_reason' => 'Payment timeout',
                    ]);

                // Restore listing available quantity
                foreach ($order->items as $item) {
                    $listingItem = MarketplaceListingItem::where('listing_uuid', $item->listing_uuid)
                        ->where('livestock_uuid', $item->livestock_uuid)->first();
                    if ($listingItem) {
                        $listing = MarketplaceListing::where('uuid', $listingItem->listing_uuid)->first();
                        if ($listing) {
                            $listing->increment('available_quantity', $item->quantity);
                            $listing->decrement('reserved_quantity', min($listing->reserved_quantity, $item->quantity));
                        }
                    }
                }
            });
        }
    }
}
