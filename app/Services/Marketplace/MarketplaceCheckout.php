<?php

namespace App\Services\Marketplace;

use App\Models\AnimalCommerceReservation;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\Hiring\HiringNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Places [buyer]'s orders: rechecks live stock, reserves it for 48 hours,
 * one order per seller, all or nothing, and texts each seller.
 * Animals held for someone else cannot be bought. Used by the app and the portal.
 */
class MarketplaceCheckout
{
    /**
     * @param  array<int, array{listing_uuid: string, livestock_uuid?: ?string, quantity: int}>  $items
     * @return PurchaseOrder[]
     */
    public function place(User $buyer, array $items, ?string $destinationFarmUuid = null, ?string $settlementMethod = null): array
    {
        $context = app(MarketplaceOrderContext::class);
        $context->releaseExpiredOrders();
        $destinationFarmUuid = $context->resolveBuyerFarm($buyer, $destinationFarmUuid);

        return DB::transaction(function () use ($buyer, $items, $destinationFarmUuid, $settlementMethod) {
            $ordersBySeller = [];
            $seenListingUuids = [];
            $heldForOthers = fn ($query) => $query->selectRaw('1')->from('animal_commerce_reservations as h')
                ->whereColumn('h.livestock_uuid', 'marketplace_listing_items.livestock_uuid')
                ->where('h.status', 'active')
                ->where(fn ($q) => $q->where('h.reservation_type', 'order_commitment')
                    ->orWhere(fn ($hold) => $hold->where('h.reservation_type', 'offer_hold')->where('h.held_by_user_id', '!=', $buyer->id)));

            foreach ($items as $cartItem) {
                abort_if(in_array($cartItem['listing_uuid'], $seenListingUuids, true), 422, 'Each listing can only appear once in the cart.');
                $seenListingUuids[] = $cartItem['listing_uuid'];
                $listing = MarketplaceListing::where('uuid', $cartItem['listing_uuid'])->lockForUpdate()->first();
                abort_if(! $listing || $listing->status !== 'active', 422, 'One of the items is no longer available in the marketplace.');
                abort_if((int) $listing->seller_user_id === (int) $buyer->id, 422, 'You cannot buy your own listing.');

                $qty = (int) $cartItem['quantity'];
                if ($listing->listing_type === 'individual') {
                    $listedAnimal = MarketplaceListingItem::where('listing_uuid', $listing->uuid)
                        ->where('livestock_uuid', $cartItem['livestock_uuid'] ?? '')->exists();
                    abort_unless($listedAnimal && $qty === 1, 422, 'Select an animal that belongs to this listing.');
                } elseif ($listing->group_sale_type === 'whole_lot' && $qty !== (int) $listing->available_quantity) {
                    abort(422, 'This listing must be purchased as a whole lot.');
                }
                abort_if($qty < (int) ($listing->min_order_quantity ?? 1) || $qty > (int) ($listing->max_order_quantity ?? $listing->available_quantity),
                    422, 'The requested quantity is outside this listing\'s order limits.');
                abort_if($qty > $listing->available_quantity, 422, "Insufficient quantity available for \"{$listing->listing_title}\". Available: {$listing->available_quantity}.");

                if (! empty($cartItem['livestock_uuid'])) {
                    abort_if(AnimalCommerceReservation::where('livestock_uuid', $cartItem['livestock_uuid'])
                        ->where('reservation_type', 'order_commitment')->where('status', 'active')->exists(),
                        422, "Animal ({$cartItem['livestock_uuid']}) is currently reserved by another buyer.");
                    abort_if(AnimalCommerceReservation::where('livestock_uuid', $cartItem['livestock_uuid'])
                        ->where('reservation_type', 'offer_hold')->where('status', 'active')
                        ->where('held_by_user_id', '!=', $buyer->id)->exists(),
                        422, 'This animal is held for another buyer.');
                }

                $unitPrice = $listing->unit_price;
                $isBulk = false;
                if ($listing->bulk_threshold && $qty >= $listing->bulk_threshold && $listing->bulk_price) {
                    $unitPrice = $listing->bulk_price;
                    $isBulk = true;
                }
                $livestockUuids = $listing->listing_type === 'individual'
                    ? [$cartItem['livestock_uuid']]
                    : MarketplaceListingItem::where('listing_uuid', $listing->uuid)
                        ->whereHas('livestock', fn ($query) => $query->whereIn('status', ['active', 'onSale', 'on_sale'])->where('farmUuid', $listing->farm_uuid))
                        ->whereNotExists($heldForOthers)
                        ->lockForUpdate()
                        ->limit($qty)
                        ->pluck('livestock_uuid')
                        ->all();
                abort_if(count($livestockUuids) !== $qty, 422, 'The requested group stock is no longer available.');

                foreach ($livestockUuids as $livestockUuid) {
                    $ordersBySeller[$listing->seller_user_id][] = [
                        'listing' => $listing,
                        'livestock_uuid' => $livestockUuid,
                        'listing_uuid' => $listing->uuid,
                        'quantity' => 1,
                        'unit_price' => $unitPrice,
                        'agreed_price' => $unitPrice,
                        'is_bulk' => $isBulk,
                    ];
                }
            }

            $expiresAt = now()->addHours(48);
            $createdOrders = [];
            foreach ($ordersBySeller as $sellerId => $sellerItems) {
                $order = PurchaseOrder::create([
                    'seller_user_id' => $sellerId,
                    'buyer_user_id' => $buyer->id,
                    'origin_farm_uuid' => $sellerItems[0]['listing']->farm_uuid ?? (string) Str::uuid(),
                    'destination_farm_uuid' => $destinationFarmUuid,
                    'total_amount' => array_sum(array_column($sellerItems, 'agreed_price')),
                    'currency' => 'TZS',
                    'source_type' => 'marketplace_direct',
                    'status' => 'pending_settlement',
                    'reserved_until' => $expiresAt,
                    'settlement_method' => $settlementMethod ?? 'direct_mobile_money',
                    'handover_challenge_hash' => hash('sha256', Str::random(32)),
                    'handover_challenge_expires_at' => now()->addDays(7),
                ]);

                foreach ($sellerItems as $itemData) {
                    PurchaseOrderItem::create([
                        'order_uuid' => $order->uuid,
                        'livestock_uuid' => $itemData['livestock_uuid'],
                        'listing_uuid' => $itemData['listing_uuid'],
                        'quantity' => $itemData['quantity'],
                        'unit_price' => $itemData['unit_price'],
                        'agreed_price' => $itemData['agreed_price'],
                        'is_bulk_applied' => $itemData['is_bulk'],
                    ]);
                    $itemData['listing']->decrement('available_quantity', $itemData['quantity']);
                    $itemData['listing']->increment('reserved_quantity', $itemData['quantity']);
                    if ($itemData['livestock_uuid']) {
                        AnimalCommerceReservation::where('livestock_uuid', $itemData['livestock_uuid'])
                            ->where('reservation_type', 'offer_hold')->where('status', 'active')
                            ->where('held_by_user_id', $buyer->id)
                            ->update(['status' => 'fulfilled', 'released_at' => now(), 'release_reason' => "Bought in order {$order->order_code}"]);
                        AnimalCommerceReservation::create([
                            'livestock_uuid' => $itemData['livestock_uuid'],
                            'reservation_type' => 'order_commitment',
                            'reference_uuid' => $order->uuid,
                            'status' => 'active',
                            'held_by_user_id' => $buyer->id,
                            'expires_at' => $expiresAt,
                        ]);
                    }
                }

                $seller = $order->seller;
                if ($seller) {
                    app(HiringNotifications::class)->send(
                        "marketplace.order.{$order->uuid}.seller",
                        $seller->id,
                        $seller->phone,
                        'marketplace_order',
                        "A new marketplace order {$order->order_code} is waiting for your review.",
                        "Umepokea agizo jipya la sokoni {$order->order_code}. Fungua Tag & Seal kulikagua.",
                        ['order_uuid' => $order->uuid, 'order_code' => $order->order_code]
                    );
                    $order->update(['sms_notified_at' => now()]);
                }
                $createdOrders[] = $order->load(['items.livestock', 'items.listing', 'seller']);
            }

            return $createdOrders;
        });
    }
}
