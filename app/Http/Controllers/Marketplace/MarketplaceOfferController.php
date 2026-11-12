<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Services\Marketplace\MarketplaceOrderContext;
use App\Models\AnimalCommerceReservation;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingItem;
use App\Models\MarketplaceOffer;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceOfferController extends Controller
{
    public function offers(Request $request): JsonResponse
    {
        $offers = MarketplaceOffer::with(['listing.seller', 'listing.items.livestock', 'buyer'])
            ->where('buyer_user_id', $request->user()->id)
            ->orWhereHas('listing', fn ($query) => $query->where('seller_user_id', $request->user()->id))
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $offers]);
    }

    /** Submit an offer for one active individual listing. */

    public function createOffer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'listing_uuid' => ['required', 'string'],
            'offered_amount' => ['required', 'numeric', 'min:1'],
            'buyer_notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $listing = MarketplaceListing::where('uuid', $data['listing_uuid'])->firstOrFail();
        abort_unless($listing->status === 'active' && $listing->available_quantity > 0, 422, 'Listing is no longer available.');
        abort_if((int) $listing->seller_user_id === (int) $request->user()->id, 422, 'You cannot offer on your own listing.');
        abort_unless($listing->listing_type === 'individual', 422, 'Offers are currently supported for individual listings only.');

        $offer = MarketplaceOffer::create([
            'listing_uuid' => $listing->uuid,
            'buyer_user_id' => $request->user()->id,
            'offered_amount' => $data['offered_amount'],
            'buyer_notes' => $data['buyer_notes'] ?? null,
            'status' => 'proposed',
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json(['success' => true, 'data' => $offer->load(['listing.seller', 'listing.items.livestock', 'buyer'])], 201);
    }

    /** Seller counters, rejects, or accepts an offer. Acceptance creates a reserved payment order. */

    public function respondToOffer(Request $request, string $offerId): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'in:accept,counter,reject,accept_counter'],
            'counter_amount' => ['required_if:action,counter', 'nullable', 'numeric', 'min:1'],
            'seller_notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($request, $offerId, $data): JsonResponse {
            $offer = MarketplaceOffer::with('listing')->where('uuid', $offerId)->lockForUpdate()->firstOrFail();
            $listing = MarketplaceListing::where('uuid', $offer->listing_uuid)->lockForUpdate()->firstOrFail();
            $buyerAcceptingCounter = $data['action'] === 'accept_counter';
            if ($buyerAcceptingCounter) {
                abort_unless((int) $offer->buyer_user_id === (int) $request->user()->id, 403, 'Unauthorized');
                abort_unless($offer->status === 'countered' && $offer->counter_amount, 422, 'There is no seller counter-offer to accept.');
            } else {
                abort_unless((int) $listing->seller_user_id === (int) $request->user()->id, 403, 'Unauthorized');
                abort_unless(in_array($offer->status, ['proposed', 'countered'], true), 422, 'This offer is no longer open.');
            }
            abort_if($offer->expires_at && $offer->expires_at->isPast(), 422, 'This offer has expired.');

            if ($data['action'] === 'counter') {
                $offer->update([
                    'status' => 'countered',
                    'counter_amount' => $data['counter_amount'],
                    'seller_notes' => $data['seller_notes'] ?? null,
                ]);

                return response()->json(['success' => true, 'data' => $offer->fresh(['listing', 'buyer'])]);
            }
            if ($data['action'] === 'reject') {
                $offer->update(['status' => 'rejected', 'seller_notes' => $data['seller_notes'] ?? null]);

                return response()->json(['success' => true, 'data' => $offer->fresh(['listing', 'buyer'])]);
            }

            abort_unless($listing->status === 'active' && $listing->available_quantity > 0, 422, 'Listing is no longer available.');
            $listingItem = MarketplaceListingItem::where('listing_uuid', $listing->uuid)->lockForUpdate()->firstOrFail();
            $reserved = AnimalCommerceReservation::where('livestock_uuid', $listingItem->livestock_uuid)
                ->where('reservation_type', 'order_commitment')->where('status', 'active')->exists();
            abort_if($reserved, 422, 'This livestock already has an active order.');

            abort_if($buyerAcceptingCounter && $listing->status !== 'active', 422, 'Listing is no longer available.');
            $agreedPrice = $buyerAcceptingCounter || $offer->counter_amount
                ? $offer->counter_amount
                : $offer->offered_amount;
            $destinationFarmUuid = app(MarketplaceOrderContext::class)->resolveBuyerFarm($offer->buyer, null);
            $order = PurchaseOrder::create([
                'seller_user_id' => $listing->seller_user_id,
                'buyer_user_id' => $offer->buyer_user_id,
                'origin_farm_uuid' => $listing->farm_uuid,
                'destination_farm_uuid' => $destinationFarmUuid,
                'total_amount' => $agreedPrice,
                'currency' => $listing->currency ?? 'TZS',
                'source_type' => 'marketplace_negotiated',
                'offer_uuid' => $offer->uuid,
                'status' => 'pending_settlement',
                'reserved_until' => now()->addHours(48),
            ]);
            PurchaseOrderItem::create([
                'order_uuid' => $order->uuid,
                'listing_uuid' => $listing->uuid,
                'livestock_uuid' => $listingItem->livestock_uuid,
                'quantity' => 1,
                'unit_price' => $agreedPrice,
                'agreed_price' => $agreedPrice,
            ]);
            AnimalCommerceReservation::create([
                'livestock_uuid' => $listingItem->livestock_uuid,
                'reservation_type' => 'order_commitment',
                'reference_uuid' => $order->uuid,
                'status' => 'active',
                'held_by_user_id' => $offer->buyer_user_id,
                'expires_at' => $order->reserved_until,
            ]);
            $listing->decrement('available_quantity');
            $listing->increment('reserved_quantity');
            $offer->update(['status' => 'accepted', 'accepted_at' => now()]);

            return response()->json([
                'success' => true,
                'message' => 'Offer accepted. A 48-hour payment order has been created.',
                'data' => $offer->fresh(['listing', 'buyer']),
                'order' => $order->load(['items.livestock', 'items.listing', 'seller', 'buyer']),
            ]);
        });
    }
}
