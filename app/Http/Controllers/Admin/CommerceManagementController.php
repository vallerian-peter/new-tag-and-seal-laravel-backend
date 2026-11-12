<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\AnimalCommerceReservation;
use App\Models\Livestock;
use App\Models\MarketplaceListing;
use App\Models\User;
use App\Services\Hiring\FarmOwnership;
use App\Services\Marketplace\MarketplaceCheckout;
use App\Services\Marketplace\MarketplaceListings;
use App\Services\Marketplace\MarketplaceOrderContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Portal-side creation of listings, orders and holds. Listings and orders run
 * through the same marketplace services as the app, acting for the chosen person.
 */
class CommerceManagementController extends Controller
{
    private const HOLD_MAX_DAYS = 30;

    /** People who own at least one farm, with those farms. */
    public function people(): JsonResponse
    {
        $ownership = app(FarmOwnership::class);
        $people = User::where('status', UserStatus::ACTIVE)
            ->where('role', '!=', UserRole::SYSTEM_USER)
            ->orderBy('id')
            ->limit(500)
            ->get()
            ->map(fn (User $user) => ['user' => $user, 'farms' => $ownership->farms($user)])
            ->filter(fn (array $row) => $row['farms'] !== [])
            ->map(fn (array $row) => [
                'id' => $row['user']->id,
                'name' => $row['user']->name,
                'phone' => $row['user']->phone,
                'farms' => $row['farms'],
            ])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return $this->ok($people);
    }

    /** Animals on [farm] that can be put on a new listing. */
    public function farmLivestock(string $farm): JsonResponse
    {
        app(MarketplaceOrderContext::class)->releaseExpiredHolds();

        return $this->ok($this->freeLivestock()->where('l.farmUuid', $farm)->where('l.status', 'active')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('marketplace_listing_items as li')
                ->join('marketplace_listings as ml', 'ml.uuid', '=', 'li.listing_uuid')
                ->whereColumn('li.livestock_uuid', 'l.uuid')
                ->whereIn('ml.status', ['active', 'reserved', 'under_negotiation']))
            ->get());
    }

    /** Animals that can be held: active or on sale, not committed to an order or already held. */
    public function holdableLivestock(): JsonResponse
    {
        app(MarketplaceOrderContext::class)->releaseExpiredHolds();

        return $this->ok($this->freeLivestock(['order_commitment', 'offer_hold'])
            ->leftJoin('farms as f', 'f.uuid', '=', 'l.farmUuid')
            ->whereIn('l.status', ['active', 'onSale', 'on_sale'])
            ->addSelect('f.name as farm_name')
            ->limit(500)
            ->get());
    }

    /** Active listings with stock left, and for single-animal listings the animals still free to order. */
    public function openListings(): JsonResponse
    {
        app(MarketplaceOrderContext::class)->releaseExpiredOrders();
        $listings = MarketplaceListing::with(['seller', 'items.livestock'])
            ->where('status', 'active')
            ->where('available_quantity', '>', 0)
            ->latest('published_at')
            ->limit(500)
            ->get();
        $committed = AnimalCommerceReservation::where('reservation_type', 'order_commitment')
            ->where('status', 'active')
            ->whereIn('livestock_uuid', $listings->flatMap->items->pluck('livestock_uuid'))
            ->pluck('livestock_uuid')
            ->flip();

        return $this->ok($listings->map(fn (MarketplaceListing $listing) => [
            'uuid' => $listing->uuid,
            'listing_title' => $listing->listing_title,
            'listing_type' => $listing->listing_type,
            'group_sale_type' => $listing->group_sale_type,
            'available_quantity' => (int) $listing->available_quantity,
            'min_order_quantity' => (int) ($listing->min_order_quantity ?? 1),
            'max_order_quantity' => (int) ($listing->max_order_quantity ?? $listing->available_quantity),
            'unit_price' => $listing->unit_price,
            'seller_user_id' => $listing->seller_user_id,
            'seller_name' => $listing->seller?->name,
            'animals' => $listing->listing_type === 'individual'
                ? $listing->items->reject(fn ($item) => isset($committed[$item->livestock_uuid]))
                    ->map(fn ($item) => [
                        'uuid' => $item->livestock_uuid,
                        'identification_number' => $item->livestock?->identificationNumber,
                        'name' => $item->livestock?->name,
                    ])->values()
                : [],
        ]));
    }

    public function storeListing(Request $request): JsonResponse
    {
        $data = $request->validate([
            'seller_user_id' => ['required', 'integer', 'exists:users,id'],
            'farm_uuid' => ['required', 'string'],
            'listing_title' => ['required', 'string', 'max:255'],
            'listing_type' => ['required', 'in:individual,group_lot'],
            'group_sale_type' => ['nullable', 'required_if:listing_type,group_lot', 'in:whole_lot,quantity_based'],
            'animal_uuids' => ['required', 'array', 'min:1'],
            'animal_uuids.*' => ['string'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'min_order_quantity' => ['nullable', 'integer', 'min:1'],
            'max_order_quantity' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location_name' => ['nullable', 'string', 'max:255'],
        ]);
        abort_if($data['listing_type'] === 'individual' && count($data['animal_uuids']) !== 1, 422, 'A single listing takes exactly one animal.');
        $seller = User::findOrFail($data['seller_user_id']);
        abort_unless(app(FarmOwnership::class)->owns($seller, $data['farm_uuid']), 422, 'The selected seller does not own this farm.');

        $listing = app(MarketplaceListings::class)->create($seller, $data);

        return $this->ok($listing, 'Listing created.', 201);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'listing_uuid' => ['required', 'string'],
            'livestock_uuid' => ['nullable', 'string'],
            'quantity' => ['required', 'integer', 'min:1'],
            'buyer_user_id' => ['required', 'integer', 'exists:users,id'],
            'destination_farm_uuid' => ['required', 'string'],
            'settlement_method' => ['required', 'in:cash_on_delivery,direct_mobile_money,bank_transfer,escrow'],
        ]);
        $buyer = User::findOrFail($data['buyer_user_id']);

        $orders = app(MarketplaceCheckout::class)->place($buyer, [[
            'listing_uuid' => $data['listing_uuid'],
            'livestock_uuid' => $data['livestock_uuid'] ?? null,
            'quantity' => (int) $data['quantity'],
        ]], $data['destination_farm_uuid'], $data['settlement_method']);

        return $this->ok($orders[0], 'Order placed. Stock is reserved for 48 hours.', 201);
    }

    public function storeHold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'livestock_uuid' => ['required', 'string', 'exists:livestocks,uuid'],
            'held_by_user_id' => ['required', 'integer', 'exists:users,id'],
            'expires_at' => ['required', 'date', 'after:now', 'before_or_equal:'.now()->addDays(self::HOLD_MAX_DAYS)->toDateTimeString()],
        ]);
        app(MarketplaceOrderContext::class)->releaseExpiredHolds();

        $hold = DB::transaction(function () use ($data) {
            $animal = Livestock::where('uuid', $data['livestock_uuid'])->lockForUpdate()->firstOrFail();
            abort_unless(in_array($animal->status, ['active', 'onSale', 'on_sale'], true), 422, 'Only active or on-sale livestock can be held.');
            abort_if(AnimalCommerceReservation::where('livestock_uuid', $animal->uuid)
                ->whereIn('reservation_type', ['order_commitment', 'offer_hold'])
                ->where('status', 'active')->exists(), 422, 'This animal is already held or committed to an order.');

            $uuid = (string) Str::uuid();

            return AnimalCommerceReservation::create([
                'uuid' => $uuid,
                'reference_uuid' => $uuid,
                'livestock_uuid' => $animal->uuid,
                'reservation_type' => 'offer_hold',
                'status' => 'active',
                'held_by_user_id' => $data['held_by_user_id'],
                'expires_at' => $data['expires_at'],
            ]);
        });

        return $this->ok($hold->load(['livestock', 'heldBy']), 'Hold created.', 201);
    }

    public function releaseHold(Request $request, string $reservation): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $hold = AnimalCommerceReservation::where('uuid', $reservation)->firstOrFail();
        abort_unless($hold->reservation_type === 'offer_hold', 422, 'Only holds made here can be released here. Listing and order reservations follow their listing or order.');
        abort_unless($hold->status === 'active', 422, 'This hold is no longer active.');
        $hold->update([
            'status' => 'released',
            'released_at' => now(),
            'release_reason' => $data['reason'] ?? 'Released from the portal',
        ]);

        return $this->ok($hold->fresh(['livestock', 'heldBy']), 'Hold released.');
    }

    /** @param  string[]  $blockingTypes */
    private function freeLivestock(array $blockingTypes = ['marketplace_listing', 'offer_hold', 'order_commitment'])
    {
        return DB::table('livestocks as l')
            ->select('l.uuid', 'l.identificationNumber as identification_number', 'l.name', 'l.status', 'l.farmUuid as farm_uuid')
            ->whereNull('l.deleted_at')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('animal_commerce_reservations as r')
                ->whereColumn('r.livestock_uuid', 'l.uuid')
                ->where('r.status', 'active')
                ->whereIn('r.reservation_type', $blockingTypes))
            ->orderBy('l.identificationNumber');
    }

    private function ok(mixed $data, string $message = 'Retrieved.', int $status = 200): JsonResponse
    {
        return response()->json(['status' => true, 'message' => $message, 'data' => $data], $status);
    }
}
