<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\AnimalCommerceReservation;
use App\Models\Farm;
use App\Models\Farmer;
use App\Models\Livestock;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceListingItem;
use App\Services\Dynamic\FarmAccessService;
use App\Services\Hiring\FarmOwnership;
use App\Services\Marketplace\MarketplaceListings;
use App\Services\Marketplace\MarketplaceOrderContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MarketplaceListingController extends Controller
{
    /**
     * Browse marketplace listings with filters.
     */
    public function listings(Request $request): JsonResponse
    {
        app(MarketplaceOrderContext::class)->releaseExpiredOrders();

        $query = MarketplaceListing::with([
            'seller', 'farm', 'items.livestock.breed',
            'items.livestock.species', 'items.livestock.livestockType',
        ])
            ->orderByDesc('id');

        // Filter by seller (for seller's own listings view)
        if ($request->input('scope') === 'mine') {
            $query->where('seller_user_id', $request->user()->id);
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
        } elseif ($request->has('seller_id')) {
            // Never allow a caller to read another seller's private listings.
            abort_unless((int) $request->seller_id === (int) $request->user()->id, 403, 'Unauthorized');
            $query->where('seller_user_id', $request->user()->id);
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
        } else {
            // Public marketplace view shows active listings with available stock
            $query->where('status', 'active')
                ->where('available_quantity', '>', 0);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search): void {
                $q->where('listing_title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('location_name', 'like', "%{$search}%");
            });
        }

        if ($request->filled('location')) {
            $query->where('location_name', 'like', "%{$request->location}%");
        }

        $listings = $query->paginate($request->integer('per_page', 20));
        $animalUuids = collect($listings->items())
            ->flatMap(fn ($listing) => $listing->items->pluck('livestock_uuid'))
            ->unique();
        $reservedUuids = AnimalCommerceReservation::whereIn('livestock_uuid', $animalUuids)
            ->where('reservation_type', 'order_commitment')
            ->where('status', 'active')
            ->pluck('livestock_uuid')
            ->flip();
        foreach ($listings as $listing) {
            foreach ($listing->items as $item) {
                $animal = $item->livestock;
                $item->setAttribute(
                    'marketplace_available',
                    ! $item->is_sold && ! $reservedUuids->has($item->livestock_uuid)
                        && $animal && $animal->farmUuid === $listing->farm_uuid
                        && in_array($animal->status, ['active', 'onSale', 'on_sale'], true)
                );
            }
        }

        return response()->json([
            'success' => true,
            'data' => $listings->items(),
            'meta' => [
                'current_page' => $listings->currentPage(),
                'last_page' => $listings->lastPage(),
                'total' => $listings->total(),
            ],
        ]);
    }

    /**
     * Get seller's active livestock available for listing.
     * Excludes livestock already in active listings or active reservations.
     */
    public function sellerLivestock(Request $request): JsonResponse
    {
        $user = $request->user();
        $farmOwnership = app(FarmOwnership::class);
        $farmerIds = $user ? $farmOwnership->farmerIds($user) : [];

        if ($request->filled('farmer_id')) {
            $farmerIds[] = (int) $request->query('farmer_id');
        }

        if ($user && ! empty($user->phone)) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $user->phone);
            $suffix = substr($cleanPhone, -9);
            $phoneFarmerIds = Farmer::where('phone1', 'like', "%{$suffix}%")
                ->orWhere('phone2', 'like', "%{$suffix}%")
                ->pluck('id')->all();
            $farmerIds = array_merge($farmerIds, $phoneFarmerIds);
        }

        $farmUuids = Farm::whereIn('farmerId', array_unique(array_filter($farmerIds)))->pluck('uuid')->all();

        if ($request->filled('farm_uuid')) {
            $farmUuids[] = (string) $request->query('farm_uuid');
        }

        if (empty($farmUuids) && $user && $user->role === 'farmer' && $user->roleId) {
            $farmUuids = Farm::where('farmerId', $user->roleId)->pluck('uuid')->all();
        }

        if (empty($farmUuids) && $user) {
            $farmAccess = app(FarmAccessService::class);
            $allFarms = Farm::all();
            foreach ($allFarms as $farm) {
                if ($farmAccess->can($user, $farm->uuid, 'livestock.manage')) {
                    $farmUuids[] = $farm->uuid;
                }
            }
        }

        if (empty($farmUuids)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'farms' => [],
                'primary_farm' => null,
            ]);
        }

        app(MarketplaceOrderContext::class)->releaseExpiredHolds();
        $activeReservationUuids = AnimalCommerceReservation::where('status', 'active')->pluck('livestock_uuid');
        $activeListedUuids = MarketplaceListingItem::whereHas('listing', function ($q): void {
            $q->whereIn('status', ['active', 'reserved', 'under_negotiation']);
        })->pluck('livestock_uuid');

        $excludedUuids = $activeReservationUuids->concat($activeListedUuids)->unique()->all();

        $query = Livestock::whereIn('farmUuid', array_unique($farmUuids))
            ->where('status', 'active');

        if (! empty($excludedUuids)) {
            $query->whereNotIn('uuid', $excludedUuids);
        }

        $livestock = $query->with(['farm', 'livestockType', 'breed', 'species'])
            ->orderByDesc('id')
            ->get();

        $sellerFarms = Farm::whereIn('uuid', array_unique($farmUuids))->get(['id', 'uuid', 'name', 'physicalAddress']);
        $primaryFarm = $sellerFarms->first();

        return response()->json([
            'success' => true,
            'data' => $livestock,
            'farms' => $sellerFarms,
            'primary_farm' => $primaryFarm,
        ]);
    }

    /**
     * Create a new marketplace listing (individual animal or group stock).
     */
    public function createListing(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'listing_title' => ['required', 'string', 'max:255'],
            'listing_type' => ['required', 'in:individual,group_lot'],
            'group_sale_type' => ['nullable', 'in:whole_lot,quantity_based'],
            'blueprint_group_uuid' => ['prohibited'],
            'farm_uuid' => ['nullable', 'string'],
            'animal_uuids' => ['nullable', 'array'],
            'animal_uuids.*' => ['string'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'bulk_threshold' => ['nullable', 'integer', 'min:2'],
            'bulk_price' => ['nullable', 'numeric', 'min:0'],
            'min_order_quantity' => ['nullable', 'integer', 'min:1'],
            'max_order_quantity' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'location_name' => ['nullable', 'string'],
            'photo_files' => ['nullable', 'array', 'max:10'],
            'photo_files.*' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'photos' => ['prohibited'],
        ]);

        $listing = app(MarketplaceListings::class)->create($request->user(), $validated, $request->file('photo_files', []));

        return response()->json([
            'success' => true,
            'message' => 'Listing created successfully',
            'data' => $listing,
        ], 201);
    }

    public function photo(string $filename): StreamedResponse
    {
        abort_unless(preg_match('/^[0-9a-f-]{36}\.(jpg|jpeg|png|webp)$/i', $filename), 404);
        $path = 'marketplace-photos/'.$filename;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }

    /**
     * Show single listing details with verified livestock records.
     */
    public function showListing(string $id): JsonResponse
    {
        $listing = MarketplaceListing::with(['seller', 'farm', 'items.livestock'])
            ->where('id', $id)
            ->orWhere('uuid', $id)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $listing,
        ]);
    }

    /**
     * Update active listing details.
     * Existing reserved orders retain their agreed price; edits apply to future buyers.
     */
    public function updateListing(Request $request, string $id): JsonResponse
    {
        $listing = MarketplaceListing::where('id', $id)->orWhere('uuid', $id)->firstOrFail();

        abort_unless($listing->seller_user_id === $request->user()->id, 403, 'Unauthorized');

        $validated = $request->validate([
            'listing_title' => ['sometimes', 'string', 'max:255'],
            'unit_price' => ['sometimes', 'numeric', 'min:0'],
            'bulk_threshold' => ['nullable', 'integer', 'min:2'],
            'bulk_price' => ['nullable', 'numeric', 'min:0'],
            'min_order_quantity' => ['nullable', 'integer', 'min:1'],
            'max_order_quantity' => ['nullable', 'integer', 'min:1'],
            'description' => ['nullable', 'string'],
            'location_name' => ['nullable', 'string'],
            'photos' => ['nullable', 'array'],
        ]);

        if (isset($validated['unit_price'])) {
            $validated['total_asking_price'] = $validated['unit_price'] * $listing->available_quantity;
        }

        $listing->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Listing updated successfully',
            'data' => $listing->fresh('items.livestock'),
        ]);
    }

    /**
     * Withdraw an active listing.
     */
    public function withdrawListing(Request $request, string $id): JsonResponse
    {
        $listing = MarketplaceListing::where('id', $id)->orWhere('uuid', $id)->firstOrFail();
        abort_unless($listing->seller_user_id === $request->user()->id, 403, 'Unauthorized');

        if ($listing->reserved_quantity > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot withdraw listing while units are currently reserved in active orders.',
            ], 422);
        }

        DB::transaction(function () use ($listing): void {
            $listing->update([
                'status' => 'withdrawn',
                'withdrawn_at' => now(),
            ]);

            AnimalCommerceReservation::where('reference_uuid', $listing->uuid)
                ->where('status', 'active')
                ->update([
                    'status' => 'released',
                    'released_at' => now(),
                    'release_reason' => 'Listing withdrawn by seller',
                ]);

            $livestockUuids = MarketplaceListingItem::where('listing_uuid', $listing->uuid)
                ->pluck('livestock_uuid');

            if ($livestockUuids->isNotEmpty()) {
                Livestock::whereIn('uuid', $livestockUuids)->update([
                    'status' => 'active',
                    'updated_at' => now(),
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Listing withdrawn successfully',
        ]);
    }

    /** Restore an unsold withdrawn listing using only livestock still owned by its farm. */
    public function relistListing(Request $request, string $id): JsonResponse
    {
        $listing = MarketplaceListing::where('id', $id)->orWhere('uuid', $id)->firstOrFail();
        abort_unless((int) $listing->seller_user_id === (int) $request->user()->id, 403, 'Unauthorized');
        abort_unless($listing->status === 'withdrawn', 422, 'Only withdrawn listings can be relisted.');

        return DB::transaction(function () use ($listing, $request): JsonResponse {
            $listing = MarketplaceListing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
            abort_if($listing->reserved_quantity > 0, 422, 'This listing still has reserved units.');

            $eligible = MarketplaceListingItem::where('listing_uuid', $listing->uuid)
                ->whereHas('livestock', function ($query) use ($listing): void {
                    $query->where('status', 'active')->where('farmUuid', $listing->farm_uuid);
                })
                ->whereNotExists(function ($query): void {
                    $query->selectRaw('1')->from('animal_commerce_reservations')
                        ->whereColumn('animal_commerce_reservations.livestock_uuid', 'marketplace_listing_items.livestock_uuid')
                        ->where('animal_commerce_reservations.status', 'active');
                })
                ->lockForUpdate()
                ->get();

            abort_if($eligible->isEmpty(), 422, 'No unsold livestock from this listing remains eligible to relist.');
            foreach ($eligible as $listingItem) {
                AnimalCommerceReservation::create([
                    'livestock_uuid' => $listingItem->livestock_uuid,
                    'reservation_type' => 'marketplace_listing',
                    'reference_uuid' => $listing->uuid,
                    'status' => 'active',
                    'held_by_user_id' => $request->user()->id,
                ]);
            }

            Livestock::whereIn('uuid', $eligible->pluck('livestock_uuid'))->update([
                'status' => 'onSale',
                'updated_at' => now(),
            ]);

            $listing->update([
                'status' => 'active',
                'available_quantity' => $eligible->count(),
                'total_quantity' => $listing->sold_quantity + $eligible->count(),
                'withdrawn_at' => null,
                'published_at' => now(),
            ]);

            return response()->json(['success' => true, 'message' => 'Listing relisted successfully.']);
        });
    }

    /** List offers visible to the authenticated buyer or seller. */
}
