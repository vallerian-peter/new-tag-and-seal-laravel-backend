<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\AnimalCommerceReservation;
use App\Models\Disposal;
use App\Models\DisposalType;
use App\Models\Livestock;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingItem;
use App\Models\PurchaseOrder;
use App\Models\Transfer;
use App\Services\Marketplace\MarketplaceCheckout;
use App\Services\Marketplace\MarketplaceOrderContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MarketplaceOrderController extends Controller
{
    /**
     * Atomic Cart Checkout:
     * - Rechecks live availability.
     * - Atomically reserves stock for 48 hours.
     * - Mixed-seller checkout creates seller-scoped orders together or fails atomically.
     * - Calculates bulk quantity tier prices and percentage savings.
     * - Sends SMS notification to each seller.
     */
    public function checkout(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.listing_uuid' => ['required', 'string'],
            'items.*.livestock_uuid' => ['nullable', 'string'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'destination_farm_uuid' => ['nullable', 'string'],
            'settlement_method' => ['nullable', 'string'],
        ]);

        $createdOrders = app(MarketplaceCheckout::class)->place(
            $request->user(),
            $validated['items'],
            $validated['destination_farm_uuid'] ?? null,
            $validated['settlement_method'] ?? null,
        );

        return response()->json([
            'success' => true,
            'message' => 'Order placed successfully. Stock reserved for 48 hours.',
            'data' => $createdOrders,
        ], 201);
    }

    /**
     * List orders for buyer or seller.
     */
    public function orders(Request $request): JsonResponse
    {
        app(MarketplaceOrderContext::class)->releaseExpiredOrders();

        $user = $request->user();
        $isSeller = $request->input('role') === 'seller';

        $query = PurchaseOrder::with(['items.livestock', 'items.listing', 'seller', 'buyer'])
            ->orderByDesc('id');

        if ($isSeller) {
            $query->where('seller_user_id', $user->id);
        } else {
            $query->where('buyer_user_id', $user->id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $orders = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $orders->items(),
            'meta' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Submit payment evidence (Buyer).
     */
    public function submitPaymentEvidence(Request $request, string $orderId): JsonResponse
    {
        app(MarketplaceOrderContext::class)->releaseExpiredOrders();
        $order = PurchaseOrder::where('id', $orderId)->orWhere('uuid', $orderId)->firstOrFail();
        abort_unless($order->buyer_user_id === $request->user()->id, 403, 'Unauthorized');
        abort_unless(in_array($order->status, ['pending_settlement', 'pending_payment'], true), 422, 'Payment evidence can only be submitted for an active unpaid order.');

        $validated = $request->validate([
            'payment_reference' => ['required', 'string', 'max:100'],
            'payment_notes' => ['nullable', 'string'],
            'receipt_image' => ['nullable', 'string'],
        ]);

        $order->update([
            'payment_evidence_ref' => $validated['payment_reference'],
            'payment_evidence_notes' => $validated['payment_notes'] ?? null,
            'payment_evidence_receipt' => $validated['receipt_image'] ?? null,
            'payment_evidence_submitted_at' => now(),
            'status' => 'pending_settlement',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment evidence submitted successfully',
            'data' => $order->fresh(),
        ]);
    }

    /**
     * Verify payment received (Seller).
     * Moves order to 'ready_for_handover'; does NOT mark livestock sold!
     */
    public function verifyPayment(Request $request, string $orderId): JsonResponse
    {
        $order = PurchaseOrder::where('id', $orderId)->orWhere('uuid', $orderId)->firstOrFail();
        abort_unless($order->seller_user_id === $request->user()->id, 403, 'Unauthorized');
        abort_unless($order->status === 'pending_settlement' && $order->payment_evidence_submitted_at, 422, 'Buyer payment evidence must be submitted before verification.');

        $order->update([
            'status' => 'ready_for_handover',
            'settlement_verified_at' => now(),
            'settlement_verified_by_user_id' => $request->user()->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payment verified. Order is now ready for physical handover.',
            'data' => $order->fresh(),
        ]);
    }

    /**
     * Confirm physical handover and transfer ownership (Seller/Buyer).
     * Marks livestock sold and completes ownership transfer.
     */
    public function confirmHandover(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();

        return DB::transaction(function () use ($orderId, $user): JsonResponse {
            $order = PurchaseOrder::with(['items'])->where('id', $orderId)->orWhere('uuid', $orderId)->lockForUpdate()->firstOrFail();
            abort_unless($order->seller_user_id === $user->id || $order->buyer_user_id === $user->id, 403, 'Unauthorized');
            abort_unless($order->status === 'ready_for_handover', 422, 'Payment must be verified before handover.');

            if ($order->seller_user_id === $user->id) {
                $order->update(['seller_handover_confirmed_at' => now()]);
            } else {
                $order->update(['buyer_handover_confirmed_at' => now()]);
            }

            $order->refresh();
            if (! $order->seller_handover_confirmed_at || ! $order->buyer_handover_confirmed_at) {
                return response()->json([
                    'success' => true,
                    'message' => 'Your handover confirmation was recorded. The order completes after both parties confirm.',
                    'data' => $order,
                ]);
            }

            $order->load(['items', 'buyer', 'seller']);
            $order->update([
                'status' => 'transferred',
                'handover_verified_at' => now(),
                'ownership_transferred_at' => now(),
            ]);

            // Release reservation and mark livestock sold/transferred
            AnimalCommerceReservation::where('reference_uuid', $order->uuid)
                ->where('status', 'active')
                ->update([
                    'status' => 'fulfilled',
                    'released_at' => now(),
                    'release_reason' => 'Handover complete and ownership transferred',
                ]);

            $soldTypeId = DisposalType::whereRaw('LOWER(name) = ?', ['sold'])->value('id')
                ?? DisposalType::whereRaw('LOWER(name) = ?', ['other'])->value('id')
                ?? 6;
            $buyerName = trim((string) optional($order->buyer)->name);
            $buyerPhone = trim((string) optional($order->buyer)->phone);
            $buyerLabel = $buyerName !== ''
                ? ($buyerPhone !== '' ? "{$buyerName} ({$buyerPhone})" : $buyerName)
                : 'Marketplace Buyer';

            foreach ($order->items as $item) {
                $item->update(['handover_status' => 'verified']);

                if ($item->livestock_uuid) {
                    $livestock = Livestock::with('breed')->where('uuid', $item->livestock_uuid)->first();
                    if ($livestock) {
                        $originFarmUuid = $order->origin_farm_uuid ?? $livestock->farmUuid;
                        $salePrice = (float) ($item->agreed_price ?? $item->unit_price ?? $order->total_amount ?? 0);
                        $saleWeight = $livestock->weightAsOnRegistration ? (float) $livestock->weightAsOnRegistration : null;
                        $breedName = optional($livestock->breed)->name ?? 'Registered Breed';
                        $livestockDetails = "Livestock: {$livestock->name} | Tag: {$livestock->identificationNumber} | Breed: {$breedName} | Gender: {$livestock->gender}"
                            .($saleWeight ? " | Weight: {$saleWeight} kg" : '');

                        // 1. Record Disposal (Sold) Event on origin farm with full Livestock Details
                        Disposal::updateOrCreate(
                            ['uuid' => "disposal-sale-{$order->uuid}-{$livestock->uuid}"],
                            [
                                'eventDate' => now(),
                                'farmUuid' => $originFarmUuid,
                                'livestockUuid' => $livestock->uuid,
                                'disposalTypeId' => $soldTypeId,
                                'reasons' => "Sold via Tag & Seal Marketplace (Order #{$order->order_code})",
                                'remarks' => $livestockDetails,
                                'saleWeight' => $saleWeight,
                                'salePrice' => $salePrice,
                                'buyerName' => $buyerLabel,
                                'status' => 'completed',
                            ]
                        );

                        // 2. Record Transfer Event with full Livestock Details
                        Transfer::updateOrCreate(
                            ['uuid' => "transfer-sale-{$order->uuid}-{$livestock->uuid}"],
                            [
                                'eventDate' => now(),
                                'farmUuid' => $originFarmUuid,
                                'livestockUuid' => $livestock->uuid,
                                'toFarmUuid' => $order->destination_farm_uuid,
                                'reason' => "Marketplace Sale (Order #{$order->order_code}) to {$buyerLabel}",
                                'price' => (string) $salePrice,
                                'transferDate' => now()->toDateString(),
                                'remarks' => "{$livestockDetails} | Buyer: {$buyerLabel}",
                                'status' => 'completed',
                            ]
                        );

                        $transferredToBuyerFarm = $order->destination_farm_uuid
                            && $order->destination_farm_uuid !== $livestock->farmUuid;
                        $livestock->update([
                            'status' => $transferredToBuyerFarm ? 'active' : 'sold',
                            'farmUuid' => $order->destination_farm_uuid ?? $livestock->farmUuid,
                            'updated_at' => now(),
                        ]);
                    }
                }

                // Update listing sold quantity
                $listingItem = MarketplaceListingItem::where('listing_uuid', $item->listing_uuid)
                    ->where('livestock_uuid', $item->livestock_uuid)->first();
                if ($listingItem) {
                    $listing = MarketplaceListing::where('uuid', $listingItem->listing_uuid)->first();
                    if ($listing) {
                        $listing->decrement('reserved_quantity', min($listing->reserved_quantity, $item->quantity));
                        $listing->increment('sold_quantity', $item->quantity);

                        if ($listing->available_quantity <= 0 && $listing->reserved_quantity <= 0) {
                            $listing->update(['status' => 'sold']);
                        }
                    }
                }
            }

            return response()->json([
                'success' => true,
                'message' => 'Handover verified and ownership transferred successfully',
                'data' => $order->fresh(),
            ]);
        });
    }

    /**
     * Seller cancel order before handover.
     * Unpaid cancellation releases reservation immediately.
     */
    public function cancelOrder(Request $request, string $orderId): JsonResponse
    {
        $order = PurchaseOrder::with('items')->where('id', $orderId)->orWhere('uuid', $orderId)->firstOrFail();
        abort_unless($order->seller_user_id === $request->user()->id, 403, 'Unauthorized');

        $reason = $request->input('reason', 'Cancelled by seller');

        return DB::transaction(function () use ($order, $reason, $request) {
            $isPaid = $order->status === 'ready_for_handover'
                || $order->settlement_verified_at !== null
                || $order->payment_evidence_submitted_at !== null;

            if ($isPaid) {
                // If payment was verified, cancellation requires refund review
                $order->update([
                    'status' => 'disputed',
                    'cancellation_reason' => $reason,
                    'cancelled_by_user_id' => $request->user()->id,
                    'dispute_reason' => 'Seller requested cancellation on paid order. Refund review required.',
                    'disputed_at' => now(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Order flagged for cancellation and refund review.',
                    'data' => $order->fresh(),
                ]);
            }

            // Unpaid order cancellation releases reservation immediately
            $order->update([
                'status' => 'cancelled',
                'cancellation_reason' => $reason,
                'cancelled_by_user_id' => $request->user()->id,
            ]);

            AnimalCommerceReservation::where('reference_uuid', $order->uuid)
                ->where('status', 'active')
                ->update([
                    'status' => 'released',
                    'released_at' => now(),
                    'release_reason' => 'Cancelled by seller before payment',
                ]);

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

            return response()->json([
                'success' => true,
                'message' => 'Order cancelled and stock reservation released.',
                'data' => $order->fresh(),
            ]);
        });
    }

    /**
     * Dispute order.
     * Keeps stock reserved and livestock unsold until admin review.
     */
    public function disputeOrder(Request $request, string $orderId): JsonResponse
    {
        $order = PurchaseOrder::where('id', $orderId)->orWhere('uuid', $orderId)->firstOrFail();
        $user = $request->user();

        abort_unless($order->seller_user_id === $user->id || $order->buyer_user_id === $user->id, 403, 'Unauthorized');

        $validated = $request->validate([
            'reason' => ['required', 'string'],
        ]);

        $order->update([
            'status' => 'disputed',
            'dispute_reason' => $validated['reason'],
            'disputed_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Order dispute recorded. Stock remains reserved pending admin resolution.',
            'data' => $order->fresh(),
        ]);
    }
}
